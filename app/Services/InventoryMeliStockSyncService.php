<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Services\MercadoLibre\MeliVariationStockPayloadBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InventoryMeliStockSyncService
{
    public const READY = 'READY';

    public const SKIPPED_SYNC_DISABLED = 'SKIPPED_SYNC_DISABLED';

    public const SKIPPED_LINK_INACTIVE = 'SKIPPED_LINK_INACTIVE';

    public const SKIPPED_PRODUCT_INACTIVE = 'SKIPPED_PRODUCT_INACTIVE';

    public const INVALID_ACCOUNT = 'INVALID_ACCOUNT';

    public const INVALID_EXTERNAL_ID = 'INVALID_EXTERNAL_ID';

    public const UNSUPPORTED = 'UNSUPPORTED';

    public const LOCKED = 'LOCKED';

    public const REMOTE_USER_PRODUCT_CONFLICT = 'REMOTE_USER_PRODUCT_CONFLICT';

    public const NO_CHANGE = 'NO_CHANGE';

    public const CONFIRMATION_MISMATCH = 'CONFIRMATION_MISMATCH';

    public const REMOTE_GROUP_CHANGED = 'REMOTE_GROUP_CHANGED';

    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryKitStockService $kitStock,
        private readonly MeliAccountApiClient $api,
        private readonly MeliVariationStockPayloadBuilder $variationPayload,
        private readonly InventoryMeliSharedStockGroupService $groups,
    ) {}

    /** @return list<string> */
    public static function previewStatuses(): array
    {
        return [
            self::READY,
            self::SKIPPED_SYNC_DISABLED,
            self::SKIPPED_LINK_INACTIVE,
            self::SKIPPED_PRODUCT_INACTIVE,
            self::INVALID_ACCOUNT,
            self::INVALID_EXTERNAL_ID,
            self::UNSUPPORTED,
            self::LOCKED,
            self::REMOTE_USER_PRODUCT_CONFLICT,
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,counts:array<string,int>,filters:array<string,mixed>} */
    public function preview(array $filters = []): array
    {
        $query = InventoryChannelLink::query()
            ->with('product')
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->when(filled($filters['account_key'] ?? null), fn ($q) => $q->where('account_key', (string) $filters['account_key']))
            ->when(($filters['enabled'] ?? '') === '1' || ($filters['enabled'] ?? '') === '0', fn ($q) => $q->where('stock_sync_enabled', $filters['enabled'] === '1'))
            ->when(filled($filters['link'] ?? null), fn ($q) => $q->whereKey((int) $filters['link']))
            ->when(is_array($filters['links'] ?? null), fn ($q) => $q->whereIn('id', array_map('intval', $filters['links'])))
            ->when(filled($filters['sku'] ?? null), fn ($q) => $q->whereHas('product', fn ($p) => $p->where('sku', 'like', '%'.trim((string) $filters['sku']).'%')))
            ->orderBy('id');

        $links = $query->get();
        $accountIds = $links->pluck('account_key')->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id)->unique();
        $accounts = MeliAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        $lastSuccess = InventoryChannelStockSync::query()
            ->whereIn('inventory_channel_link_id', $links->pluck('id'))
            ->where('status', InventoryChannelStockSync::SUCCESS)
            ->orderByDesc('id')->get()->unique('inventory_channel_link_id')->keyBy('inventory_channel_link_id');

        $rows = $links->map(function (InventoryChannelLink $link) use ($accounts, $lastSuccess): array {
            $product = $link->product;
            $account = $this->resolveAccount($link->account_key, $accounts);
            [$physical, $reserved, $available] = $this->stockValues($product);
            $status = self::READY;
            $reason = null;
            if (! $link->stock_sync_enabled) {
                $status = self::SKIPPED_SYNC_DISABLED;
                $reason = 'La sincronización de stock no está habilitada para este vínculo.';
            } elseif (! $link->is_active) {
                $status = self::SKIPPED_LINK_INACTIVE;
                $reason = 'El vínculo está inactivo.';
            } elseif (! $product) {
                $status = self::UNSUPPORTED;
                $reason = 'El producto de inventario no existe.';
            } elseif (! $product->is_active) {
                $status = self::SKIPPED_PRODUCT_INACTIVE;
                $reason = 'El producto de inventario está inactivo.';
            } elseif (! $account) {
                $status = self::INVALID_ACCOUNT;
                $reason = 'La cuenta de Mercado Libre no existe.';
            } elseif (blank($link->external_listing_id)) {
                $status = self::INVALID_EXTERNAL_ID;
                $reason = 'Falta el ID de publicación de Mercado Libre.';
            }

            $target = max(0, $available);
            if ($available < 0) {
                Log::warning('Inventory MeLi stock availability was clamped to zero', ['link_id' => $link->id, 'available' => $available]);
            }

            $grouped = $this->groups->isGrouped($link);
            $siblings = $grouped ? $this->groups->links($link, true) : collect([$link]);
            $groupConflict = $grouped ? $this->groups->conflict($link) : ['conflict' => false, 'link_ids' => [], 'product_ids' => []];
            if ($status === self::READY && $groupConflict['conflict']) {
                $status = self::REMOTE_USER_PRODUCT_CONFLICT;
                $reason = 'El user_product_id remoto pertenece a varios productos Inventory.';
            }

            return [
                'id' => (int) $link->id,
                'inventory_product_id' => $product?->id,
                'sku' => $product?->sku,
                'product_name' => $product?->name,
                'product_type' => $product?->product_type,
                'physical' => $physical,
                'reserved' => $reserved,
                'available' => $available,
                'target' => $target,
                'account_key' => $link->account_key,
                'account_name' => $account?->nickname,
                'external_listing_id' => $link->external_listing_id,
                'external_variant_id' => $link->external_variant_id,
                'remote_user_product_id' => $link->remote_user_product_id,
                'shared_stock_group' => $grouped ? $link->remote_user_product_id : null,
                'sibling_link_ids' => $grouped ? $siblings->pluck('id')->map(fn ($id) => (int) $id)->all() : [],
                'representative_link_id' => $grouped ? (int) ($siblings->first()?->id ?? $link->id) : (int) $link->id,
                'remote_user_product_conflict' => $groupConflict['conflict'],
                'conflict_link_ids' => $groupConflict['link_ids'],
                'conflict_product_ids' => $groupConflict['product_ids'],
                'stock_sync_enabled' => (bool) $link->stock_sync_enabled,
                'is_active' => (bool) $link->is_active,
                'status' => $status,
                'reason' => $reason,
                'warning' => $available < 0 ? 'La disponibilidad negativa se limitó a cero.' : null,
                'last_successful_target' => $lastSuccess->get($link->id)?->target_quantity,
                'last_sync_at' => $lastSuccess->get($link->id)?->finished_at?->toISOString(),
                'last_error' => InventoryChannelStockSync::query()->where('inventory_channel_link_id', $link->id)->where('status', InventoryChannelStockSync::FAILED)->latest('id')->value('error_message'),
            ];
        })->values()->all();

        if (filled($filters['result'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['status'] === $filters['result']));
        }
        if (filled($filters['search'] ?? null)) {
            $search = Str::lower(trim((string) $filters['search']));
            $rows = array_values(array_filter($rows, fn (array $row): bool => Str::contains(Str::lower(implode(' ', array_filter([
                $row['sku'], $row['product_name'], $row['external_listing_id'], $row['external_variant_id'], $row['account_name'],
            ]))), $search)));
        }

        $counts = array_fill_keys(self::previewStatuses(), 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        return ['rows' => $rows, 'counts' => $counts, 'filters' => $filters];
    }

    /** @return array{imported:int,results:list<array<string,mixed>>} */
    public function apply(array $filters = [], ?int $userId = null): array
    {
        $rows = $this->preview($filters)['rows'];
        $results = [];
        $processedGroups = [];
        foreach ($rows as $row) {
            if ($row['status'] !== self::READY) {
                continue;
            }
            $groupKey = $row['shared_stock_group'] === null
                ? 'link:'.$row['id']
                : 'group:'.hash('sha256', $row['account_key']."\0".$row['shared_stock_group']);
            if (isset($processedGroups[$groupKey])) {
                continue;
            }
            $processedGroups[$groupKey] = true;
            $representativeId = (int) ($row['representative_link_id'] ?? $row['id']);
            $representative = InventoryChannelLink::query()->find($representativeId)
                ?? InventoryChannelLink::query()->findOrFail($row['id']);
            $results[] = $this->syncLink($representative, $userId, 'manual');
        }

        return ['imported' => count(array_filter($results, fn (array $result): bool => $result['status'] === InventoryChannelStockSync::SUCCESS)), 'results' => $results];
    }

    /** @return array<string,mixed> */
    public function sync(InventoryChannelLink|int $link, ?int $userId = null, string $triggeredBy = 'manual'): array
    {
        return $this->syncLink($link, $userId, $triggeredBy);
    }

    /** @return array<string,mixed> */
    public function syncLink(InventoryChannelLink|int $link, ?int $userId = null, string $triggeredBy = 'manual'): array
    {
        return $this->syncLinkInternal($link, $userId, $triggeredBy, null, true);
    }

    /** @return array<string,mixed> */
    public function syncLinkUnderExistingLock(
        InventoryChannelLink|int $link,
        ?int $userId = null,
        string $triggeredBy = 'manual',
        ?int $previousKnownQuantity = null,
        ?int $requestedLinkId = null,
        ?string $confirmedWriteIdentity = null,
    ): array {
        return $this->syncLinkInternal($link, $userId, $triggeredBy, $previousKnownQuantity, false, $requestedLinkId, $confirmedWriteIdentity);
    }

    /** @return array<string,mixed> */
    private function syncLinkInternal(
        InventoryChannelLink|int $link,
        ?int $userId,
        string $triggeredBy,
        ?int $previousKnownQuantity,
        bool $acquireLock,
        ?int $requestedLinkId = null,
        ?string $confirmedWriteIdentity = null,
    ): array {
        $link = $link instanceof InventoryChannelLink ? $link : InventoryChannelLink::query()->findOrFail($link);
        $requestedLinkId ??= (int) $link->getKey();
        $row = collect($this->preview(['link' => $link->getKey()])['rows'])->first();
        if (! $row || $row['status'] !== self::READY) {
            $status = $row['status'] ?? self::UNSUPPORTED;
            $result = [...($row ?? ['id' => $link->id]), 'status' => $status];
            if ($status === self::REMOTE_USER_PRODUCT_CONFLICT) {
                $result['link_ids'] = $result['link_ids'] ?? $result['conflict_link_ids'] ?? [];
                $result['product_ids'] = $result['product_ids'] ?? $result['conflict_product_ids'] ?? [];
            }

            return $result;
        }

        if ($this->groups->isGrouped($link)) {
            $conflict = $this->groups->conflict($link);
            if ($conflict['conflict']) {
                Log::warning('Inventory MeLi shared stock ownership conflict', [
                    'remote_user_product_id' => $link->remote_user_product_id,
                    'link_ids' => $conflict['link_ids'],
                    'product_ids' => $conflict['product_ids'],
                ]);

                return [...$row, 'status' => self::REMOTE_USER_PRODUCT_CONFLICT, 'reason' => 'REMOTE_USER_PRODUCT_CONFLICT', ...$conflict];
            }
            $link = $this->groups->representative($link)->fresh(['product']);
            $row = collect($this->preview(['link' => $link->getKey()])['rows'])->first() ?? $row;
        }

        $lock = $acquireLock ? $this->groups->lock($link) : null;
        if ($lock && ! $lock->get()) {
            return [...$row, 'status' => self::LOCKED, 'reason' => 'La sincronización ya está en curso.'];
        }

        try {
            $link = $link->fresh(['product']);
            $freshRow = collect($this->preview(['link' => $link->getKey()])['rows'])->first();
            if (! $freshRow || $freshRow['status'] !== self::READY) {
                return $freshRow ?? [...$row, 'status' => self::UNSUPPORTED];
            }
            $row = $freshRow;
            $account = MeliAccount::query()->find((int) $link->account_key);
            $started = now();
            $audit = InventoryChannelStockSync::create([
                'inventory_channel_link_id' => $link->id,
                'inventory_product_id' => $link->inventory_product_id,
                'channel' => $link->channel,
                'account_key' => $link->account_key,
                'external_listing_id' => $link->external_listing_id,
                'external_variant_id' => $link->external_variant_id,
                'target_quantity' => $row['target'],
                'previous_known_quantity' => $previousKnownQuantity ?? $row['last_successful_target'],
                'status' => InventoryChannelStockSync::FAILED,
                'triggered_by' => $triggeredBy,
                'created_by' => $userId,
                'started_at' => $started,
                'metadata' => [
                    'negative_available_clamped' => $row['available'] < 0,
                    'requested_link_id' => $requestedLinkId,
                    ...($this->groups->isGrouped($link) ? [
                        'shared_stock_group' => $link->remote_user_product_id,
                        'representative' => true,
                        'representative_link_id' => (int) $link->id,
                        'sibling_link_ids' => $this->groups->links($link, true)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    ] : []),
                ],
            ]);
            if ($confirmedWriteIdentity !== null && $confirmedWriteIdentity !== $this->identity($link)) {
                return $this->abortAudit($audit, $row, self::CONFIRMATION_MISMATCH, 'La identidad confirmada no coincide con la identidad final de escritura.');
            }
            try {
                $this->api->ensureFreshAccessToken($account);
                if (filled($link->external_variant_id)) {
                    $remote = $this->api->request($account, 'get', '/items/'.rawurlencode((string) $link->external_listing_id));
                    $item = $remote->json();
                    $variation = collect((array) ($item['variations'] ?? []))->first(fn ($v) => (string) ($v['id'] ?? '') === (string) $link->external_variant_id);
                    if (is_numeric($variation['available_quantity'] ?? null)) {
                        $previousKnownQuantity = (int) $variation['available_quantity'];
                        $audit->forceFill(['previous_known_quantity' => $previousKnownQuantity])->save();
                    }
                    if (is_numeric($variation['available_quantity'] ?? null) && (int) $variation['available_quantity'] === (int) $row['target']) {
                        return $this->recordNoChange($audit, $row, (int) $variation['available_quantity']);
                    }
                    $payload = $this->variationPayload->build((array) ($item['variations'] ?? []), (string) $link->external_variant_id, $row['target']);
                } else {
                    if ($previousKnownQuantity === null) {
                        $lockKeyBeforeDiscovery = $this->groups->lockKey($link);
                        $representativeWasRead = false;
                        $hadKnownRemoteGroup = $this->groups->isGrouped($link);
                        $knownRemoteUserProductId = $link->remote_user_product_id;
                        $remote = $this->api->request($account, 'get', '/items/'.rawurlencode((string) $link->external_listing_id));
                        $item = $remote->json();
                        $remoteUserProductId = is_string($item['user_product_id'] ?? null) ? $item['user_product_id'] : null;
                        if ($remoteUserProductId !== null && $remoteUserProductId !== '') {
                            $link->forceFill(['remote_user_product_id' => $remoteUserProductId])->save();
                            $row['remote_user_product_id'] = $remoteUserProductId;
                            if ($hadKnownRemoteGroup && $remoteUserProductId !== (string) $knownRemoteUserProductId) {
                                return $this->abortAudit($audit, $row, self::REMOTE_GROUP_CHANGED, 'La identidad remota cambió; reintentar con el lock del nuevo grupo.', self::REMOTE_GROUP_CHANGED, [
                                    'remote_user_product_id' => $remoteUserProductId,
                                ]);
                            }
                            if ($lockKeyBeforeDiscovery !== $this->groups->lockKey($link)) {
                                $lock?->release();
                                $lock = $this->groups->lock($link);
                                if (! $lock->get()) {
                                    return $this->abortAudit($audit, $row, self::LOCKED, 'La sincronización del grupo remoto ya está en curso.');
                                }
                                $link = $this->groups->representative($link)->fresh(['product']);
                                $freshRow = collect($this->preview(['link' => $link->getKey()])['rows'])->first();
                                if (is_array($freshRow) && array_key_exists('target', $freshRow)) {
                                    $audit->forceFill(['target_quantity' => $freshRow['target']])->save();
                                }
                                if (($freshRow['status'] ?? null) === self::REMOTE_USER_PRODUCT_CONFLICT) {
                                    return $this->abortAudit($audit, $freshRow, self::REMOTE_USER_PRODUCT_CONFLICT, 'REMOTE_USER_PRODUCT_CONFLICT', self::REMOTE_USER_PRODUCT_CONFLICT, [
                                        'link_ids' => $freshRow['conflict_link_ids'] ?? [],
                                        'product_ids' => $freshRow['conflict_product_ids'] ?? [],
                                    ]);
                                }
                                if (! $freshRow || $freshRow['status'] !== self::READY) {
                                    throw new \RuntimeException('El grupo remoto dejó de ser elegible durante la sincronización.');
                                }
                                $row = $freshRow;
                                if ($confirmedWriteIdentity !== null && $confirmedWriteIdentity !== $this->identity($link)) {
                                    return $this->abortAudit($audit, $row, self::CONFIRMATION_MISMATCH, 'La identidad confirmada no coincide con la identidad final de escritura.');
                                }
                                $account = MeliAccount::query()->find((int) $link->account_key);
                                $representativeRemote = $this->api->request($account, 'get', '/items/'.rawurlencode((string) $link->external_listing_id));
                                $representativeItem = $representativeRemote->json();
                                $representativeUserProductId = $representativeItem['user_product_id'] ?? null;
                                if (! is_string($representativeUserProductId) || $representativeUserProductId === '' || $representativeUserProductId !== (string) $link->remote_user_product_id) {
                                    if (is_string($representativeUserProductId) && $representativeUserProductId !== '') {
                                        $link->forceFill(['remote_user_product_id' => $representativeUserProductId])->save();
                                    }

                                    return $this->abortAudit($audit, $row, self::REMOTE_GROUP_CHANGED, 'La identidad remota del representante cambió; reintentar con el nuevo grupo.', self::REMOTE_GROUP_CHANGED, [
                                        'remote_user_product_id' => $representativeUserProductId,
                                    ]);
                                }
                                if (! is_numeric($representativeItem['available_quantity'] ?? null)) {
                                    return $this->abortAudit($audit, $row, InventoryChannelStockSync::FAILED, 'La cantidad remota del representante no es válida.', 'MALFORMED_RESPONSE');
                                }
                                $representativeWasRead = true;
                                $previousKnownQuantity = is_numeric($representativeItem['available_quantity'] ?? null)
                                    ? (int) $representativeItem['available_quantity']
                                    : null;
                                $audit->forceFill([
                                    'inventory_channel_link_id' => $link->id,
                                    'inventory_product_id' => $link->inventory_product_id,
                                    'external_listing_id' => $link->external_listing_id,
                                    'previous_known_quantity' => $previousKnownQuantity,
                                ])->save();
                            }
                            $knownGroupLinks = $this->groups->links($link, true);
                            $representativeId = (int) ($knownGroupLinks->first()?->id ?? $link->id);
                            $audit->forceFill(['metadata' => array_merge((array) $audit->metadata, [
                                'shared_stock_group' => $remoteUserProductId,
                                'representative' => $representativeId === (int) $link->id,
                                'representative_link_id' => $representativeId,
                                'sibling_link_ids' => $knownGroupLinks->pluck('id')->map(fn ($id) => (int) $id)->all(),
                            ])])->save();
                            $conflict = $this->groups->conflict($link);
                            if ($conflict['conflict']) {
                                $audit->forceFill([
                                    'status' => InventoryChannelStockSync::FAILED,
                                    'error_code' => self::REMOTE_USER_PRODUCT_CONFLICT,
                                    'error_message' => 'REMOTE_USER_PRODUCT_CONFLICT',
                                    'finished_at' => now(),
                                    'metadata' => array_merge((array) $audit->metadata, $conflict, ['remote_user_product_id' => $remoteUserProductId]),
                                ])->save();

                                return [...$row, 'status' => self::REMOTE_USER_PRODUCT_CONFLICT, 'reason' => 'REMOTE_USER_PRODUCT_CONFLICT', ...$conflict];
                            }
                        }
                        if (! $representativeWasRead && $this->groups->isGrouped($link)) {
                            $remoteRepresentativeId = $item['user_product_id'] ?? null;
                            if (! is_string($remoteRepresentativeId) || $remoteRepresentativeId === '' || $remoteRepresentativeId !== (string) $link->remote_user_product_id) {
                                if (is_string($remoteRepresentativeId) && $remoteRepresentativeId !== '') {
                                    $link->forceFill(['remote_user_product_id' => $remoteRepresentativeId])->save();
                                }

                                return $this->abortAudit($audit, $row, self::REMOTE_GROUP_CHANGED, 'La identidad remota del representante cambió; reintentar con el nuevo grupo.', self::REMOTE_GROUP_CHANGED, [
                                    'remote_user_product_id' => $remoteRepresentativeId,
                                ]);
                            }
                            if (! is_numeric($item['available_quantity'] ?? null)) {
                                return $this->abortAudit($audit, $row, InventoryChannelStockSync::FAILED, 'La cantidad remota del representante no es válida.', 'MALFORMED_RESPONSE');
                            }
                            $representativeWasRead = true;
                        }
                        $previousKnownQuantity ??= is_numeric($item['available_quantity'] ?? null) ? (int) $item['available_quantity'] : null;
                        $audit->forceFill(['previous_known_quantity' => $previousKnownQuantity])->save();
                    }
                    if ($previousKnownQuantity !== null && $previousKnownQuantity === (int) $row['target']) {
                        return $this->recordNoChange($audit, $row, $previousKnownQuantity);
                    }
                }
                $payload ??= ['available_quantity' => $row['target']];
                $response = $this->api->request($account, 'put', '/items/'.rawurlencode((string) $link->external_listing_id), $payload);
                if (! $response->successful()) {
                    throw new MeliApiRequestException(
                        'Mercado Libre rechazó la actualización de stock.',
                        $response->status(),
                    );
                }
                $audit->forceFill(['status' => InventoryChannelStockSync::SUCCESS, 'http_status' => $response->status(), 'finished_at' => now()])->save();
                $link->forceFill(['last_synced_at' => now(), 'remote_status' => 'synced'])->save();

                return [...$row, 'status' => InventoryChannelStockSync::SUCCESS, 'http_status' => $response->status(), 'audit_id' => $audit->id];
            } catch (Throwable $exception) {
                $httpStatus = $exception instanceof MeliApiRequestException ? $exception->httpStatus() : null;
                $audit->forceFill([
                    'status' => InventoryChannelStockSync::FAILED,
                    'http_status' => $httpStatus,
                    'error_code' => $exception instanceof MeliApiRequestException ? 'MELI_HTTP_'.$httpStatus : class_basename($exception),
                    'error_message' => Str::limit($this->safeError($exception->getMessage()), 1000),
                    'finished_at' => now(),
                ])->save();
                Log::warning('Inventory MeLi stock sync failed', ['link_id' => $link->id, 'status' => $httpStatus, 'error' => $audit->error_message]);

                return [...$row, 'status' => InventoryChannelStockSync::FAILED, 'http_status' => $httpStatus, 'reason' => $audit->error_message, 'audit_id' => $audit->id];
            }
        } finally {
            $lock?->release();
        }
    }

    private function recordNoChange(InventoryChannelStockSync $audit, array $row, int $quantity): array
    {
        $audit->forceFill([
            'status' => InventoryChannelStockSync::SUCCESS,
            'previous_known_quantity' => $quantity,
            'verified_quantity' => $quantity,
            'verification_status' => InventoryChannelStockSync::VERIFIED,
            'verified_at' => now(),
            'finished_at' => now(),
            'metadata' => array_merge((array) $audit->metadata, ['no_change' => true, 'put_skipped' => true]),
        ])->save();

        return [...$row, 'status' => self::NO_CHANGE, 'audit_id' => $audit->id, 'verified_quantity' => $quantity];
    }

    private function identity(InventoryChannelLink $link): string
    {
        return filled($link->external_variant_id)
            ? $link->external_listing_id.':'.$link->external_variant_id
            : (string) $link->external_listing_id;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $extra */
    private function abortAudit(InventoryChannelStockSync $audit, array $row, string $status, string $reason, ?string $errorCode = null, array $extra = []): array
    {
        $audit->forceFill([
            'status' => InventoryChannelStockSync::FAILED,
            'error_code' => $errorCode ?? $status,
            'error_message' => $reason,
            'finished_at' => now(),
            'metadata' => array_merge((array) $audit->metadata, $extra),
        ])->save();

        return [...$row, 'status' => $status, 'reason' => $reason, 'audit_id' => $audit->id, ...$extra];
    }

    private function stockValues(?InventoryProduct $product): array
    {
        if (! $product) {
            return [0, 0, 0];
        }
        if ($product->isKit()) {
            $physical = $this->kitStock->physicalStock($product);
            $available = $this->kitStock->availableStock($product);

            return [$physical, max(0, $physical - $available), $available];
        }
        $physical = $this->stock->physicalStock($product);
        $reserved = $this->stock->reservedStock($product);

        return [$physical, $reserved, $this->stock->availableStock($product)];
    }

    private function resolveAccount(mixed $key, EloquentCollection $accounts): ?MeliAccount
    {
        return ctype_digit((string) $key) ? $accounts->get((int) $key) : null;
    }

    private function safeError(string $message): string
    {
        $sanitized = preg_replace([
            '/\b(?:authorization|access_token|refresh_token|client_secret)\b\s*[:=]\s*(?:Bearer\s+)?(?:\[[^\]]*\]|[^\s,;]+)/i',
            '/\bBearer\s+(?:\[[^\]]*\]|[^\s,;]+)/i',
            '/\b(?:authorization|access_token|refresh_token|client_secret|bearer)\b/i',
        ], [
            '[REDACTED]',
            '[REDACTED]',
            '',
        ], $message) ?? 'Error de sincronización.';

        return Str::limit(trim((string) preg_replace('/\s{2,}/', ' ', $sanitized)), 1000);
    }
}
