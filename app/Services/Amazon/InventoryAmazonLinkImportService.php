<?php

namespace App\Services\Amazon;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Services\InventoryChannelLinkService;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryAmazonLinkImportService
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
        private readonly InventoryAmazonClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>|null  $amazonItems
     * @return array{rows:list<array<string,mixed>>,counts:array<string,int>,filters:array<string,mixed>,total_rows:int,rows_truncated:bool,row_limit:int}
     */
    public function preview(array $filters = [], ?array $amazonItems = null): array
    {
        $items = $amazonItems ?? [];
        $sellerId = $this->client->getSellerId();
        if ($sellerId === '') {
            $sellerId = (string) config('services.amazon.seller_id', 'default_seller');
        }

        $existingLinks = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::AMAZON)
            ->where('account_key', $sellerId)
            ->get(['inventory_product_id', 'external_listing_id', 'external_product_id'])
            ->keyBy(fn ($link) => $link->external_listing_id ?? $link->external_product_id);

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

        foreach ($items as $item) {
            $totalRows++;
            $sellerSku = trim((string) ($item['sku'] ?? $item['seller_sku'] ?? ''));
            $asin = trim((string) ($item['asin'] ?? ''));
            $barcode = trim((string) ($item['barcode'] ?? $item['upc'] ?? $item['ean'] ?? ''));
            $title = (string) ($item['title'] ?? '');
            $price = $item['price'] ?? null;

            $status = self::MATCHED;
            $matchedProduct = null;
            $reason = null;

            if ($sellerSku === '' && $asin === '') {
                $status = self::MISSING_SKU;
                $reason = 'El ítem de Amazon no cuenta con Seller SKU ni ASIN.';
            } elseif ($existingLinks->has($sellerSku) || ($asin !== '' && $existingLinks->has($asin))) {
                $status = self::ALREADY_LINKED;
                $matchedLink = $existingLinks->get($sellerSku) ?? $existingLinks->get($asin);
                $matchedProduct = $inventoryProducts->firstWhere('id', $matchedLink->inventory_product_id);
                $reason = 'Este producto de Amazon ya está vinculado.';
            } else {
                $tSku = $sellerSku !== '' ? ltrim($sellerSku, '0') : '';
                $tBarcode = $barcode !== '' ? ltrim($barcode, '0') : '';

                if (($tSku !== '' && isset($ambiguousTrimmed['sku:'.$tSku]))
                    || ($tBarcode !== '' && isset($ambiguousTrimmed['barcode:'.$tBarcode]))) {
                    $status = self::AMBIGUOUS;
                    $reason = 'El código coincide con múltiples productos en el inventario.';
                } else {
                    $matchedProduct = ($sellerSku !== '' ? ($byExactSku[$sellerSku] ?? $byExactBarcode[$sellerSku] ?? null) : null)
                        ?? ($barcode !== '' ? ($byExactBarcode[$barcode] ?? $byExactSku[$barcode] ?? null) : null)
                        ?? ($tSku !== '' ? ($byTrimmedSku[$tSku] ?? $byTrimmedBarcode[$tSku] ?? null) : null)
                        ?? ($tBarcode !== '' ? ($byTrimmedBarcode[$tBarcode] ?? $byTrimmedSku[$tBarcode] ?? null) : null);

                    if (! $matchedProduct) {
                        $status = self::PRODUCT_NOT_FOUND;
                        $reason = 'No se encontró un producto local con este SKU o código de barras.';
                    }
                }
            }

            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $row = [
                'account_key' => $sellerId,
                'external_listing_id' => $sellerSku !== '' ? $sellerSku : null,
                'external_product_id' => $asin !== '' ? $asin : null,
                'seller_sku' => $sellerSku,
                'asin' => $asin,
                'barcode' => $barcode,
                'amazon_title' => $title,
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

        if (filled($filters['result'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === $filters['result']));
        }

        if (filled($filters['search'] ?? null)) {
            $term = Str::lower(trim((string) $filters['search']));
            $rows = array_values(array_filter($rows, fn (array $r): bool => Str::contains(
                Str::lower(implode(' ', array_filter([
                    $r['seller_sku'], $r['asin'], $r['barcode'], $r['amazon_title'], $r['inventory_product_sku'], $r['inventory_product_name'],
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
     * @param  list<array<string, mixed>>|null  $amazonItems
     * @return array{preview:array<string,mixed>,imported:int,errors:list<string>}
     */
    public function apply(array $filters = [], ?array $amazonItems = null): array
    {
        $preview = $this->preview($filters, $amazonItems);
        $imported = 0;
        $errors = [];

        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== self::MATCHED || blank($row['inventory_product_id'])) {
                continue;
            }

            try {
                $this->links->create([
                    'channel' => InventoryChannelLink::AMAZON,
                    'account_key' => $row['account_key'],
                    'inventory_product_id' => $row['inventory_product_id'],
                    'external_listing_id' => $row['external_listing_id'],
                    'external_product_id' => $row['external_product_id'],
                    'remote_price' => $row['remote_price'],
                    'remote_currency' => 'MXN',
                    'is_active' => true,
                    'stock_sync_enabled' => false,
                    'order_reservation_enabled' => false,
                    'metadata' => [
                        'amazon_title' => $row['amazon_title'],
                        'asin' => $row['asin'],
                    ],
                ]);
                $imported++;
            } catch (InvalidArgumentException $e) {
                $identifier = $row['seller_sku'] ?: $row['asin'];
                $errors[] = "Ítem Amazon {$identifier}: {$e->getMessage()}";
            }
        }

        return [
            'preview' => $this->preview($filters, $amazonItems),
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
