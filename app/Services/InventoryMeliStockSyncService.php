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
use Illuminate\Support\Facades\Cache;
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

    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryKitStockService $kitStock,
        private readonly MeliAccountApiClient $api,
        private readonly MeliVariationStockPayloadBuilder $variationPayload,
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
        foreach ($rows as $row) {
            if ($row['status'] !== self::READY) {
                continue;
            }
            $results[] = $this->syncLink(InventoryChannelLink::query()->findOrFail($row['id']), $userId, 'manual');
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
    ): array {
        return $this->syncLinkInternal($link, $userId, $triggeredBy, $previousKnownQuantity, false);
    }

    /** @return array<string,mixed> */
    private function syncLinkInternal(
        InventoryChannelLink|int $link,
        ?int $userId,
        string $triggeredBy,
        ?int $previousKnownQuantity,
        bool $acquireLock,
    ): array {
        $link = $link instanceof InventoryChannelLink ? $link : InventoryChannelLink::query()->findOrFail($link);
        $row = collect($this->preview(['link' => $link->getKey()])['rows'])->first();
        if (! $row || $row['status'] !== self::READY) {
            return [...($row ?? ['id' => $link->id]), 'status' => $row['status'] ?? self::UNSUPPORTED];
        }

        $lock = $acquireLock ? Cache::lock('inventory-meli-stock-sync:link:'.$link->getKey(), 600) : null;
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
                'metadata' => ['negative_available_clamped' => $row['available'] < 0],
            ]);
            try {
                $this->api->ensureFreshAccessToken($account);
                $payload = ['available_quantity' => $row['target']];
                if (filled($link->external_variant_id)) {
                    $remote = $this->api->request($account, 'get', '/items/'.rawurlencode((string) $link->external_listing_id));
                    $payload = $this->variationPayload->build((array) $remote->json('variations', []), (string) $link->external_variant_id, $row['target']);
                }
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
