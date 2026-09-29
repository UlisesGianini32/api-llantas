<?php

namespace App\Services\Shopify;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;
use App\Models\InventoryProduct;
use App\Services\InventoryKitStockService;
use App\Services\InventoryStockService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InventoryShopifyStockSyncService
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
        private readonly InventoryShopifyClient $client,
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
            ->where('channel', InventoryChannelLink::SHOPIFY)
            ->when(filled($filters['account_key'] ?? null), fn ($q) => $q->where('account_key', (string) $filters['account_key']))
            ->when(($filters['enabled'] ?? '') === '1' || ($filters['enabled'] ?? '') === '0', fn ($q) => $q->where('stock_sync_enabled', $filters['enabled'] === '1'))
            ->when(filled($filters['link'] ?? null), fn ($q) => $q->whereKey((int) $filters['link']))
            ->when(is_array($filters['links'] ?? null), fn ($q) => $q->whereIn('id', array_map('intval', $filters['links'])))
            ->when(filled($filters['sku'] ?? null), fn ($q) => $q->whereHas('product', fn ($p) => $p->where('sku', 'like', '%'.trim((string) $filters['sku']).'%')))
            ->orderBy('id');

        $links = $query->get();

        $lastSuccess = InventoryChannelStockSync::query()
            ->whereIn('inventory_channel_link_id', $links->pluck('id'))
            ->where('status', InventoryChannelStockSync::SUCCESS)
            ->orderByDesc('id')
            ->get()
            ->unique('inventory_channel_link_id')
            ->keyBy('inventory_channel_link_id');

        $rows = $links->map(function (InventoryChannelLink $link) use ($lastSuccess): array {
            $product = $link->product;
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
            } elseif (blank($link->external_variant_id)) {
                $status = self::INVALID_EXTERNAL_ID;
                $reason = 'Falta el ID de variante de Shopify.';
            }

            $target = max(0, $available);
            if ($available < 0) {
                Log::warning('Inventory Shopify stock availability was clamped to zero', ['link_id' => $link->id, 'available' => $available]);
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
                'external_listing_id' => $link->external_listing_id,
                'external_variant_id' => $link->external_variant_id,
                'external_product_id' => $link->external_product_id,
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
                $row['sku'], $row['product_name'], $row['external_listing_id'], $row['external_variant_id'], $row['account_key'],
            ]))), $search)));
        }

        $counts = array_fill_keys(self::previewStatuses(), 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        return ['rows' => $rows, 'counts' => $counts, 'filters' => $filters];
    }

    /** @return array{imported:int,results:list<array<string,mixed>>} */
    public function apply(array $filters = [], ?int $userId = null, ?string $locationId = null): array
    {
        $rows = $this->preview($filters)['rows'];
        $results = [];

        foreach ($rows as $row) {
            if ($row['status'] !== self::READY) {
                continue;
            }
            $results[] = $this->syncLink($row['id'], $userId, 'manual', $locationId);
        }

        return [
            'imported' => count(array_filter($results, fn (array $r): bool => ($r['status'] ?? '') === InventoryChannelStockSync::SUCCESS)),
            'results' => $results,
        ];
    }

    /** @return array<string,mixed> */
    public function sync(InventoryChannelLink|int $link, ?int $userId = null, string $triggeredBy = 'manual', ?string $locationId = null): array
    {
        return $this->syncLink($link, $userId, $triggeredBy, $locationId);
    }

    /** @return array<string,mixed> */
    public function syncLink(
        InventoryChannelLink|int $link,
        ?int $userId = null,
        string $triggeredBy = 'manual',
        ?string $locationId = null,
    ): array {
        $link = $link instanceof InventoryChannelLink ? $link : InventoryChannelLink::query()->findOrFail($link);
        $preview = $this->preview(['link' => $link->getKey()]);
        $row = collect($preview['rows'])->first();

        if (! $row || $row['status'] !== self::READY) {
            $status = $row['status'] ?? self::UNSUPPORTED;

            return [...($row ?? ['id' => $link->id]), 'status' => $status];
        }

        $locationId ??= $this->client->getPrimaryLocationId();
        if (blank($locationId)) {
            return [
                ...$row,
                'status' => self::UNSUPPORTED,
                'reason' => 'No se encontró una ubicación activa de Shopify.',
            ];
        }

        $inventoryItemId = $link->external_product_id;
        if (blank($inventoryItemId)) {
            try {
                $variantData = $this->client->getVariant($link->external_variant_id);
                $remoteItem = $variantData['variant']['inventory_item_id'] ?? null;
                if ($remoteItem) {
                    $inventoryItemId = (string) $remoteItem;
                    $link->forceFill(['external_product_id' => $inventoryItemId])->save();
                    $row['external_product_id'] = $inventoryItemId;
                }
            } catch (Throwable $e) {
                Log::warning('No se pudo resolver inventory_item_id de Shopify para variante', [
                    'variant_id' => $link->external_variant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (blank($inventoryItemId)) {
            return [
                ...$row,
                'status' => self::UNSUPPORTED,
                'reason' => "Falta el ID de item de inventario en Shopify para la variante {$link->external_variant_id}.",
            ];
        }

        $started = now();
        $audit = InventoryChannelStockSync::create([
            'inventory_channel_link_id' => $link->id,
            'inventory_product_id' => $link->inventory_product_id,
            'channel' => InventoryChannelLink::SHOPIFY,
            'account_key' => $link->account_key,
            'external_listing_id' => $link->external_listing_id,
            'external_variant_id' => $link->external_variant_id,
            'target_quantity' => $row['target'],
            'previous_known_quantity' => $row['last_successful_target'],
            'status' => InventoryChannelStockSync::FAILED,
            'triggered_by' => $triggeredBy,
            'created_by' => $userId,
            'started_at' => $started,
            'metadata' => [
                'location_id' => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'negative_available_clamped' => $row['available'] < 0,
            ],
        ]);

        try {
            try {
                $this->client->setInventoryLevel($inventoryItemId, $locationId, (int) $row['target']);
            } catch (Throwable $primaryException) {
                // Si la ubicación no estaba conectada para este ítem, conectar y reintentar
                try {
                    $this->client->connectInventoryLevel($inventoryItemId, $locationId);
                    $this->client->setInventoryLevel($inventoryItemId, $locationId, (int) $row['target']);
                } catch (Throwable) {
                    throw $primaryException;
                }
            }

            $audit->forceFill([
                'status' => InventoryChannelStockSync::SUCCESS,
                'verified_quantity' => $row['target'],
                'verification_status' => InventoryChannelStockSync::VERIFIED,
                'verified_at' => now(),
                'finished_at' => now(),
            ])->save();

            $link->forceFill(['last_synced_at' => now()])->save();

            return [
                ...$row,
                'status' => InventoryChannelStockSync::SUCCESS,
                'audit_id' => $audit->id,
            ];
        } catch (Throwable $e) {
            $errorMessage = $this->safeError($e->getMessage());

            $audit->forceFill([
                'status' => InventoryChannelStockSync::FAILED,
                'error_message' => $errorMessage,
                'finished_at' => now(),
            ])->save();

            return [
                ...$row,
                'status' => InventoryChannelStockSync::FAILED,
                'reason' => $errorMessage,
                'audit_id' => $audit->id,
            ];
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
        ], $message) ?? 'Error de sincronización con Shopify.';

        return Str::limit(trim((string) preg_replace('/\s{2,}/', ' ', $sanitized)), 1000);
    }
}
