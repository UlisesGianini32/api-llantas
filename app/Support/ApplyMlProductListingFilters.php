<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Mismos filtros que ProductoController índice y export Shopify.
 *
 * @param  array<string, mixed>  $filters
 */
class ApplyMlProductListingFilters
{
    /**
     * @param  array{search?:string,official_store_id?:string,categories?:array<int|string,string>|string}  $filters
     */
    public static function to(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $officialStoreId = (string) ($filters['official_store_id'] ?? '');
        $categories = $filters['categories'] ?? [];

        if (! is_array($categories)) {
            $categories = array_filter(explode(',', (string) $categories));
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('ml', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('category_name', 'like', "%{$search}%")
                    ->orWhere('shopify_category_name', 'like', "%{$search}%");
            });
        }

        if ($officialStoreId !== '') {
            $query->where('official_store_id', $officialStoreId);
        }

        if (! empty($categories)) {
            $query->whereIn('category_name', $categories);
        }
    }
}
