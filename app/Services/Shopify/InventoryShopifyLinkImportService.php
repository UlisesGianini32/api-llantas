<?php

namespace App\Services\Shopify;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Services\InventoryChannelLinkService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class InventoryShopifyLinkImportService
{
    public const MATCHED = 'MATCHED';

    public const ALREADY_LINKED = 'ALREADY_LINKED';

    public const MISSING_SKU = 'MISSING_SKU';

    public const PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    public const AMBIGUOUS = 'AMBIGUOUS';

    public const UNSUPPORTED = 'UNSUPPORTED';

    public const PREVIEW_ROW_LIMIT = 250;

    /** @return list<string> */
    public static function classStatuses(): array
    {
        return [
            self::MATCHED,
            self::ALREADY_LINKED,
            self::MISSING_SKU,
            self::PRODUCT_NOT_FOUND,
            self::AMBIGUOUS,
            self::UNSUPPORTED,
        ];
    }

    public function __construct(
        private readonly InventoryChannelLinkService $links,
        private readonly InventoryShopifyClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>|null  $shopifyProducts
     * @return array{rows:list<array<string,mixed>>,counts:array<string,int>,filters:array<string,mixed>,total_rows:int,rows_truncated:bool,row_limit:int}
     */
    public function preview(array $filters = [], ?array $shopifyProducts = null): array
    {
        $products = $shopifyProducts ?? $this->fetchProducts($filters);
        $storeDomain = $this->resolveStoreDomain();

        $existingLinks = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::SHOPIFY)
            ->where('account_key', $storeDomain)
            ->pluck('inventory_product_id', 'external_variant_id')
            ->all();

        $inventoryProducts = InventoryProduct::query()->where('is_active', true)->get();

        $byExactSku = [];
        $byExactBarcode = [];
        $byTrimmedSku = [];
        $byTrimmedBarcode = [];
        $ambiguousTrimmed = [];

        foreach ($inventoryProducts as $p) {
            $sku = trim((string) $p->sku);
            $barcode = trim((string) $p->barcode);

            if ($sku !== '') {
                $byExactSku[$sku] = $p;
                $tSku = ltrim($sku, '0');
                if (isset($byTrimmedSku[$tSku]) && $byTrimmedSku[$tSku]->id !== $p->id) {
                    $ambiguousTrimmed['sku:'.$tSku] = true;
                } else {
                    $byTrimmedSku[$tSku] = $p;
                }
            }

            if ($barcode !== '') {
                $byExactBarcode[$barcode] = $p;
                $tBarcode = ltrim($barcode, '0');
                if (isset($byTrimmedBarcode[$tBarcode]) && $byTrimmedBarcode[$tBarcode]->id !== $p->id) {
                    $ambiguousTrimmed['barcode:'.$tBarcode] = true;
                } else {
                    $byTrimmedBarcode[$tBarcode] = $p;
                }
            }
        }

        $counts = array_fill_keys(self::classStatuses(), 0);
        $rows = [];
        $totalRows = 0;

        foreach ($products as $prod) {
            $prodId = (string) ($prod['id'] ?? '');
            $prodTitle = (string) ($prod['title'] ?? '');
            $variants = (array) ($prod['variants'] ?? []);

            foreach ($variants as $var) {
                $totalRows++;
                $variantId = (string) ($var['id'] ?? '');
                $inventoryItemId = (string) ($var['inventory_item_id'] ?? '');
                $sku = trim((string) ($var['sku'] ?? ''));
                $barcode = trim((string) ($var['barcode'] ?? ''));
                $variantTitle = (string) ($var['title'] ?? '');
                $price = $var['price'] ?? null;

                $tSku = $sku !== '' ? ltrim($sku, '0') : '';
                $tBarcode = $barcode !== '' ? ltrim($barcode, '0') : '';

                $status = self::MATCHED;
                $matchedProduct = null;
                $reason = null;

                if (isset($existingLinks[$variantId])) {
                    $status = self::ALREADY_LINKED;
                    $matchedProduct = $inventoryProducts->firstWhere('id', $existingLinks[$variantId]);
                    $reason = 'Esta variante ya se encuentra vinculada a un producto de inventario.';
                } elseif ($sku === '' && $barcode === '') {
                    $status = self::MISSING_SKU;
                    $reason = 'La variante de Shopify no tiene SKU ni código de barras registrado.';
                } else {
                    // Verificación de ambigüedad
                    if (($tSku !== '' && isset($ambiguousTrimmed['sku:'.$tSku]))
                        || ($tBarcode !== '' && isset($ambiguousTrimmed['barcode:'.$tBarcode]))) {
                        $status = self::AMBIGUOUS;
                        $reason = 'El código coincide con múltiples productos en el inventario.';
                    } else {
                        // Coincidencia exacta o normalizada
                        $matchedProduct = ($sku !== '' ? ($byExactSku[$sku] ?? $byExactBarcode[$sku] ?? null) : null)
                            ?? ($barcode !== '' ? ($byExactBarcode[$barcode] ?? $byExactSku[$barcode] ?? null) : null)
                            ?? ($tSku !== '' ? ($byTrimmedSku[$tSku] ?? $byTrimmedBarcode[$tSku] ?? null) : null)
                            ?? ($tBarcode !== '' ? ($byTrimmedBarcode[$tBarcode] ?? $byTrimmedSku[$tBarcode] ?? null) : null);

                        if (! $matchedProduct) {
                            $status = self::PRODUCT_NOT_FOUND;
                            $reason = 'No se encontró ningún producto con este SKU o código de barras en el inventario.';
                        }
                    }
                }

                $counts[$status] = ($counts[$status] ?? 0) + 1;

                $row = [
                    'account_key' => $storeDomain,
                    'external_listing_id' => $prodId,
                    'external_variant_id' => $variantId,
                    'external_product_id' => $inventoryItemId,
                    'shopify_title' => $prodTitle,
                    'variant_title' => $variantTitle,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'remote_price' => $price,
                    'inventory_product_id' => $matchedProduct?->id,
                    'inventory_product_sku' => $matchedProduct?->sku,
                    'inventory_product_name' => $matchedProduct?->name,
                    'status' => $status,
                    'reason' => $reason,
                ];

                if (count($rows) < self::PREVIEW_ROW_LIMIT) {
                    $rows[] = $row;
                }
            }
        }

        if (filled($filters['result'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === $filters['result']));
        }

        if (filled($filters['search'] ?? null)) {
            $term = Str::lower(trim((string) $filters['search']));
            $rows = array_values(array_filter($rows, fn (array $r): bool => Str::contains(
                Str::lower(implode(' ', array_filter([
                    $r['sku'], $r['barcode'], $r['shopify_title'], $r['variant_title'], $r['inventory_product_sku'], $r['inventory_product_name'],
                ]))),
                $term
            )));
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'filters' => $filters,
            'total_rows' => $totalRows,
            'rows_truncated' => $totalRows > self::PREVIEW_ROW_LIMIT,
            'row_limit' => self::PREVIEW_ROW_LIMIT,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>|null  $shopifyProducts
     * @return array{preview:array<string,mixed>,imported:int,errors:list<string>}
     */
    public function apply(array $filters = [], ?array $shopifyProducts = null): array
    {
        $preview = $this->preview($filters, $shopifyProducts);
        $imported = 0;
        $errors = [];

        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== self::MATCHED || blank($row['inventory_product_id'])) {
                continue;
            }

            try {
                $this->links->create([
                    'channel' => InventoryChannelLink::SHOPIFY,
                    'account_key' => $row['account_key'],
                    'inventory_product_id' => $row['inventory_product_id'],
                    'external_listing_id' => $row['external_listing_id'],
                    'external_variant_id' => $row['external_variant_id'],
                    'external_product_id' => $row['external_product_id'],
                    'remote_price' => $row['remote_price'],
                    'remote_currency' => 'MXN',
                    'is_active' => true,
                    'stock_sync_enabled' => false,
                    'order_reservation_enabled' => false,
                    'metadata' => [
                        'shopify_title' => $row['shopify_title'],
                        'variant_title' => $row['variant_title'],
                    ],
                ]);
                $imported++;
            } catch (InvalidArgumentException $e) {
                $errors[] = "Variante {$row['external_variant_id']} ({$row['sku']}): {$e->getMessage()}";
            }
        }

        return [
            'preview' => $this->preview($filters, $shopifyProducts),
            'imported' => $imported,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function fetchProducts(array $filters = []): array
    {
        try {
            $limit = isset($filters['limit']) ? max(1, min(250, (int) $filters['limit'])) : 250;
            $res = $this->client->getProducts(['limit' => $limit]);

            return (array) ($res['products'] ?? []);
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveStoreDomain(): string
    {
        try {
            return $this->client->getStoreDomain();
        } catch (Throwable) {
            return (string) config('services.shopify.store_domain', 'shopify');
        }
    }
}
