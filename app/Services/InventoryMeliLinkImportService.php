<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliPublication;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class InventoryMeliLinkImportService
{
    public const MATCHED = 'MATCHED';

    public const ALREADY_LINKED = 'ALREADY_LINKED';

    public const MISSING_SKU = 'MISSING_SKU';

    public const PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    public const AMBIGUOUS = 'AMBIGUOUS';

    public const CONFLICT = 'CONFLICT';

    public const UNSUPPORTED = 'UNSUPPORTED';

    public const PREVIEW_ROW_LIMIT = 200;

    private const PUBLICATION_CHUNK_SIZE = 50;

    /** @return list<string> */
    public static function classStatuses(): array
    {
        return [
            self::MATCHED,
            self::ALREADY_LINKED,
            self::MISSING_SKU,
            self::PRODUCT_NOT_FOUND,
            self::AMBIGUOUS,
            self::CONFLICT,
            self::UNSUPPORTED,
        ];
    }

    public function __construct(private readonly InventoryChannelLinkService $links) {}

    /** @return array{rows:list<array<string,mixed>>,counts:array<string,int>,filters:array<string,string>,total_rows:int,rows_truncated:bool,row_limit:int} */
    public function preview(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $counts = array_fill_keys(self::classStatuses(), 0);
        $rows = [];
        $totalRows = 0;

        $this->eachFilteredRow($filters, function (array $row) use (&$counts, &$rows, &$totalRows): void {
            $totalRows++;
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            if (count($rows) < self::PREVIEW_ROW_LIMIT) {
                $rows[] = $row;
            }
        });

        return [
            'rows' => $rows,
            'counts' => $counts,
            'filters' => $filters,
            'total_rows' => $totalRows,
            'rows_truncated' => $totalRows > self::PREVIEW_ROW_LIMIT,
            'row_limit' => self::PREVIEW_ROW_LIMIT,
        ];
    }

    /** @return array{preview:array<string,mixed>,imported:int,errors:list<string>} */
    public function apply(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $imported = 0;
        $errors = [];

        // Apply the complete filtered set without materializing the legacy catalog.
        $this->eachFilteredRow($filters, function (array $row) use (&$imported, &$errors): void {
            if ($row['status'] !== self::MATCHED) {
                return;
            }

            try {
                $this->links->create(
                    $this->linkPayload($row, InventoryProduct::query()->findOrFail($row['inventory_product_id']))
                );
                $imported++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $row['mlm'].($row['variation_id'] ? ':'.$row['variation_id'] : '').' — '.$exception->getMessage();
            }
        });

        return ['preview' => $this->preview($filters), 'imported' => $imported, 'errors' => $errors];
    }

    /** @param array<string,string> $filters */
    private function eachFilteredRow(array $filters, callable $consumer): void
    {
        $this->publicationQuery($filters)->chunkById(self::PUBLICATION_CHUNK_SIZE, function (Collection $publications) use ($filters, $consumer): void {
            $candidates = $publications->flatMap(fn (MeliPublication $publication): Collection => $this->candidatesFor($publication));
            $skus = $candidates->pluck('sku')
                ->filter()
                ->map(fn (mixed $sku): string => trim((string) $sku))
                ->unique()
                ->values();
            $productsBySku = $skus->isEmpty()
                ? collect()
                : InventoryProduct::query()
                    ->select(['id', 'sku'])
                    ->whereIn('sku', $skus)
                    ->get()
                    ->groupBy(fn (InventoryProduct $product): string => trim((string) $product->sku));

            $identityKeys = $candidates
                ->map(fn (array $candidate): string => $this->candidateIdentityKey($candidate))
                ->unique()
                ->values();
            $existing = $identityKeys->isEmpty()
                ? collect()
                : InventoryChannelLink::query()
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->whereIn('identity_key', $identityKeys)
                    ->pluck('inventory_product_id', 'identity_key');

            foreach ($candidates as $candidate) {
                $row = $this->classifyCandidate($candidate, $productsBySku, $existing);
                if ($this->matchesFilters($row, $filters)) {
                    $consumer($row);
                }
            }
        }, 'id');
    }

    /** @param array<string,string> $filters */
    private function publicationQuery(array $filters)
    {
        return MeliPublication::query()
            ->select(['id', 'meli_account_id', 'sku', 'mlm', 'status', 'permalink', 'last_sync_at', 'raw'])
            ->with('meliAccount:id,nickname,meli_user_id')
            ->when($filters['account_key'] !== '', fn ($query) => $query->where('meli_account_id', (int) $filters['account_key']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['search'].'%';
                $query->where(function ($nested) use ($like): void {
                    $nested->where('sku', 'like', $like)
                        ->orWhere('mlm', 'like', $like)
                        // Variation seller SKUs live inside the legacy JSON snapshot.
                        ->orWhere('raw', 'like', $like);
                });
            })
            ->whereNotNull('mlm')
            // chunkById advances with the cursor column. Do not sort by another
            // column first or records with lower IDs can be skipped between chunks.
            ->orderBy('id');
    }

    /** @param array<string,mixed> $filters @return array<string,string> */
    private function normalizeFilters(array $filters): array
    {
        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'result' => strtoupper(trim((string) ($filters['result'] ?? ''))),
            'account_key' => trim((string) ($filters['account_key'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function candidateIdentityKey(array $candidate): string
    {
        return $this->links->identityKey([
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => $candidate['account_key'],
            'external_listing_id' => $candidate['mlm'],
            'external_variant_id' => $candidate['variation_id'],
        ]);
    }

    /** @param array<string,mixed> $candidate */
    private function classifyCandidate(array $candidate, Collection $productsBySku, Collection $existing): array
    {
        $row = $candidate;
        $sku = trim((string) ($candidate['sku'] ?? ''));
        $matches = $sku === '' ? collect() : $productsBySku->get($sku, collect());
        $product = $matches->count() === 1 ? $matches->first() : null;
        $identityKey = $this->candidateIdentityKey($candidate);
        $existingProductId = $existing->get($identityKey);

        if ($candidate['forced_status'] !== null) {
            $status = $candidate['forced_status'];
            $reason = $candidate['forced_reason'] ?? 'Estructura no soportada.';
        } elseif (blank($candidate['account_key'])) {
            $status = self::UNSUPPORTED;
            $reason = 'La publicación no tiene una cuenta Mercado Libre identificable.';
        } elseif ($sku === '') {
            $status = self::MISSING_SKU;
            $reason = 'La publicación o variación no tiene seller SKU utilizable.';
        } elseif ($matches->count() > 1) {
            $status = self::AMBIGUOUS;
            $reason = 'El SKU coincide con más de un InventoryProduct.';
        } elseif ($existingProductId !== null && $product !== null && (int) $existingProductId !== (int) $product->getKey()) {
            $status = self::CONFLICT;
            $reason = 'La identidad externa ya está vinculada a otro InventoryProduct.';
        } elseif ($existingProductId !== null) {
            $status = self::ALREADY_LINKED;
            $reason = 'El vínculo externo correcto ya existe.';
        } elseif ($product === null) {
            $status = self::PRODUCT_NOT_FOUND;
            $reason = 'No existe un InventoryProduct con ese SKU.';
        } else {
            $status = self::MATCHED;
            $reason = 'SKU exacto encontrado; el vínculo puede importarse.';
        }

        $row['status'] = $status;
        $row['reason'] = $reason;
        $row['inventory_product_id'] = $product?->getKey();
        $row['inventory_product_sku'] = $product?->sku;
        $row['identity_key'] = $identityKey;

        return $row;
    }

    /** @param array<string,mixed> $row @param array<string,string> $filters */
    private function matchesFilters(array $row, array $filters): bool
    {
        if ($filters['result'] !== '' && $row['status'] !== $filters['result']) {
            return false;
        }
        if ($filters['search'] === '') {
            return true;
        }

        $needle = strtolower($filters['search']);

        return str_contains(strtolower((string) ($row['sku'] ?? '')), $needle)
            || str_contains(strtolower((string) ($row['mlm'] ?? '')), $needle);
    }

    /** @return Collection<int, array<string,mixed>> */
    private function candidatesFor(MeliPublication $publication): Collection
    {
        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        if ($variations === []) {
            return collect([$this->candidate($publication, $item, null)]);
        }

        return collect($variations)->map(function (mixed $variation) use ($publication, $item): array {
            if (! is_array($variation) || blank($variation['id'] ?? null)) {
                return $this->candidate($publication, $item, null, self::UNSUPPORTED, 'La variación no tiene ID externo representable.');
            }

            return $this->candidate($publication, $variation, (string) $variation['id'], null, null, $item);
        });
    }

    /** @return array<string,mixed> */
    private function candidate(MeliPublication $publication, array $data, ?string $variationId, ?string $forcedStatus = null, ?string $forcedReason = null, ?array $parent = null): array
    {
        $item = $parent ?? MeliPublication::itemArrayFromRaw($publication->raw);
        $sku = $this->sellerSku($data);
        if ($sku === '' && $variationId === null) {
            $sku = trim((string) ($publication->sku ?: ($item['seller_custom_field'] ?? '')));
        }
        $price = is_numeric($data['price'] ?? null) ? round((float) $data['price'], 2) : MeliPublication::listPriceFromRaw($publication->raw);
        $mlm = strtoupper(trim((string) $publication->mlm));

        return [
            'account_key' => (string) $publication->meli_account_id,
            'account_name' => $publication->meliAccount?->nickname,
            'mlm' => $mlm,
            'variation_id' => $variationId,
            'sku' => trim($sku),
            'catalog_product_id' => $data['catalog_product_id'] ?? $item['catalog_product_id'] ?? null,
            'external_url' => $publication->permalink ?: ($item['permalink'] ?? null),
            'remote_status' => $publication->status,
            'remote_price' => $price,
            'remote_currency' => $data['currency_id'] ?? $item['currency_id'] ?? null,
            'last_synced_at' => $publication->last_sync_at,
            'metadata' => array_filter([
                'source' => 'legacy_meli_import',
                'legacy_model' => MeliPublication::class,
                'legacy_id' => $publication->getKey(),
                'variation_id' => $variationId,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'forced_status' => $forcedStatus,
            'forced_reason' => $forcedReason,
        ];
    }

    private function sellerSku(array $data): string
    {
        $direct = trim((string) ($data['seller_custom_field'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }
        foreach ((array) ($data['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && strtoupper((string) ($attribute['id'] ?? '')) === 'SELLER_SKU') {
                return trim((string) ($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
            }
        }

        return '';
    }

    /** @return array<string,mixed> */
    private function linkPayload(array $row, InventoryProduct $product): array
    {
        return [
            'inventory_product_id' => $product->getKey(),
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => $row['account_key'],
            'external_listing_id' => $row['mlm'],
            'external_variant_id' => $row['variation_id'],
            'external_product_id' => $row['catalog_product_id'],
            'external_url' => $row['external_url'],
            'remote_status' => $row['remote_status'],
            'remote_price' => $row['remote_price'],
            'remote_currency' => $row['remote_currency'],
            'last_synced_at' => $row['last_synced_at'],
            'metadata' => $row['metadata'],
        ];
    }
}
