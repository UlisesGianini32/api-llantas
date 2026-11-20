<?php

namespace App\Services\Purchasing;

use App\Models\InventoryChannelLink;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves purchase order pictures from already-synced Mercado Libre listings.
 *
 * No outbound API calls, inventory mutations, or fuzzy name-based matching.
 */
class PurchaseOrderMeliImageResolver
{
    /** @return array<int, string> Purchase-order item ID => trusted picture URL */
    public function forOrder(PurchaseOrder $order): array
    {
        if (! Schema::hasTable('meli_publications')) {
            return [];
        }

        $items = $order->items;
        if ($items->isEmpty()) {
            return [];
        }

        $skus = $items->map(fn ($item): string => trim((string) ($item->product?->sku ?: $item->sku)))
            ->filter()->unique()->values()->all();

        $productIds = $items->pluck('inventory_product_id')->filter()->unique()->values()->all();

        $links = Schema::hasTable('inventory_channel_links') && $productIds !== []
            ? InventoryChannelLink::query()
                ->whereIn('inventory_product_id', $productIds)
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get(['id', 'inventory_product_id', 'account_key', 'external_listing_id', 'external_variant_id'])
            : collect();

        $mlms = $links->pluck('external_listing_id')->filter()->unique()->values()->all();

        if ($skus === [] && $mlms === []) {
            return [];
        }

        $publications = MeliPublication::query()
            ->where(function ($query) use ($skus, $mlms): void {
                if ($mlms !== []) {
                    $query->whereIn('mlm', $mlms);
                }
                if ($skus !== []) {
                    if ($mlms !== []) {
                        $query->orWhereIn('sku', $skus);
                    } else {
                        $query->whereIn('sku', $skus);
                    }
                }
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('last_sync_at')
            ->orderByDesc('id')
            ->get(['id', 'meli_account_id', 'sku', 'mlm', 'status', 'last_sync_at', 'raw']);

        $byMlm = $publications->groupBy(fn (MeliPublication $publication): string => strtoupper(trim((string) $publication->mlm)));
        $bySku = $publications->groupBy(fn (MeliPublication $publication): string => strtoupper(trim((string) $publication->sku)));
        $byProductId = $links->groupBy('inventory_product_id');
        $results = [];

        foreach ($items as $item) {
            $sku = trim((string) ($item->product?->sku ?: $item->sku));
            if ($sku === '') {
                continue;
            }

            // Prefer the explicit inventory-to-listing relationship, including its variant ID.
            foreach ($byProductId->get($item->inventory_product_id, collect()) as $link) {
                $mlm = strtoupper(trim((string) $link->external_listing_id));
                foreach ($byMlm->get($mlm, collect()) as $publication) {
                    if (ctype_digit((string) $link->account_key)
                        && (int) $link->account_key !== (int) $publication->meli_account_id) {
                        continue;
                    }

                    $url = $this->imageForPublication(
                        $publication,
                        $sku,
                        $link->external_variant_id ? (string) $link->external_variant_id : null,
                    );
                    if ($url !== null) {
                        $results[(int) $item->id] = $url;
                        continue 3;
                    }
                }
            }

            // Legacy listing links may only have an exact seller SKU, with no inventory link.
            foreach ($bySku->get(strtoupper($sku), collect()) as $publication) {
                $url = $this->imageForPublication($publication, $sku);
                if ($url !== null) {
                    $results[(int) $item->id] = $url;
                    break;
                }
            }
        }

        return $results;
    }

    public function imageForPublication(MeliPublication $publication, string $sku, ?string $variationId = null): ?string
    {
        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        if ($item === []) {
            return null;
        }

        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        $pictures = is_array($item['pictures'] ?? null) ? $item['pictures'] : [];

        if ($variationId !== null) {
            foreach ($variations as $variation) {
                if (! is_array($variation) || (string) ($variation['id'] ?? '') !== $variationId) {
                    continue;
                }

                $variationSku = $this->sellerSku($variation);
                if ($variationSku !== '' && strcasecmp($variationSku, $sku) !== 0) {
                    return null;
                }

                return $this->variationPicture($variation, $pictures);
            }

            return null;
        }

        if ($variations !== []) {
            // A generic listing cover can be the wrong color: require one exact SKU variant.
            $matches = array_values(array_filter($variations, fn ($variation): bool =>
                is_array($variation) && strcasecmp($this->sellerSku($variation), $sku) === 0
            ));

            return count($matches) === 1 ? $this->variationPicture($matches[0], $pictures) : null;
        }

        $sellerSku = $this->sellerSku($item);
        if ($sellerSku !== '' && strcasecmp($sellerSku, $sku) !== 0) {
            return null;
        }

        return $this->pictureUrl($pictures[0] ?? null) ?? $this->trustedUrl($item['thumbnail'] ?? null);
    }

    /** @param array<string, mixed> $variation
     *  @param array<int, mixed> $pictures
     */
    private function variationPicture(array $variation, array $pictures): ?string
    {
        $ids = array_map('strval', (array) ($variation['picture_ids'] ?? []));
        foreach ($ids as $id) {
            foreach ($pictures as $picture) {
                if (is_array($picture) && (string) ($picture['id'] ?? '') === $id) {
                    $url = $this->pictureUrl($picture);
                    if ($url !== null) {
                        return $url;
                    }
                }
            }
        }

        return $this->pictureUrl($variation['pictures'][0] ?? null);
    }

    /** @param array<string, mixed> $data */
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

    private function pictureUrl(mixed $picture): ?string
    {
        if (is_array($picture)) {
            return $this->trustedUrl($picture['secure_url'] ?? $picture['url'] ?? null);
        }

        return $this->trustedUrl($picture);
    }

    private function trustedUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = preg_replace('/^http:\/\//i', 'https://', trim($value));
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($host === 'mlstatic.com' || str_ends_with($host, '.mlstatic.com')) ? $url : null;
    }
}
