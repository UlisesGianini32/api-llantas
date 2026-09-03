<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MeliPriceManagerItem extends Model
{
    use HasFactory;

    public const CLASSIFICATION_STATUSES = ['categorized', 'suggested', 'uncategorized', 'ignored'];

    public const LISTING_TYPE_CLASSIC = 'gold_special';

    public const LISTING_TYPE_PREMIUM = 'gold_pro';

    public const SUPPORTED_LISTING_TYPE_IDS = [
        self::LISTING_TYPE_CLASSIC,
        self::LISTING_TYPE_PREMIUM,
    ];

    public static function listingTypeName(?string $listingTypeId): ?string
    {
        return match ($listingTypeId) {
            self::LISTING_TYPE_CLASSIC => 'Clásica',
            self::LISTING_TYPE_PREMIUM => 'Premium',
            default => null,
        };
    }

    protected $fillable = [
        'meli_account_id', 'meli_item_id', 'sku', 'title', 'category_id', 'listing_type_id',
        'catalog_product_id', 'user_product_id', 'inventory_id', 'catalog_listing',
        'price_sync_status', 'price_relation_ids', 'linked_synced_at',
        'meli_brand', 'normalized_brand', 'brand_group_id',
        'suggested_brand_group_id', 'matched_brand_alias_id',
        'classification_status', 'classification_source', 'classification_confidence',
        'classification_metadata',
        'current_price', 'estimated_receivable', 'estimated_receivable_price',
        'estimated_receivable_calculated_at', 'original_price', 'available_quantity', 'sold_quantity', 'currency_id',
        'status', 'permalink', 'thumbnail', 'raw_attributes', 'raw_item', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'classification_confidence' => 'decimal:4',
            'classification_metadata' => 'array',
            'current_price' => 'decimal:2',
            'estimated_receivable' => 'decimal:2',
            'estimated_receivable_price' => 'decimal:2',
            'estimated_receivable_calculated_at' => 'datetime',
            'original_price' => 'decimal:2',
            'available_quantity' => 'integer',
            'sold_quantity' => 'integer',
            'raw_attributes' => 'array',
            'raw_item' => 'array',
            'catalog_listing' => 'boolean',
            'price_relation_ids' => 'array',
            'linked_synced_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopeManagedCatalog(Builder $query): Builder
    {
        if (Schema::hasTable('llantas')) {
            $query->whereNotExists(function ($subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('llantas as managed_llantas')
                    ->where(function ($ownership): void {
                        $ownership
                            ->whereColumn('managed_llantas.MLM', 'meli_price_manager_items.meli_item_id')
                            ->orWhere(function ($skuMatch): void {
                                $skuMatch
                                    ->whereNotNull('managed_llantas.sku')
                                    ->whereRaw("TRIM(managed_llantas.sku) <> ''")
                                    ->whereNotNull('meli_price_manager_items.sku')
                                    ->whereRaw("TRIM(meli_price_manager_items.sku) <> ''")
                                    ->whereColumn('managed_llantas.sku', 'meli_price_manager_items.sku');
                            });
                    });
            });
        }

        if (Schema::hasTable('producto_compuestos')) {
            $query->whereNotExists(function ($subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('producto_compuestos as managed_compuestos')
                    ->where(function ($ownership): void {
                        $ownership
                            ->whereColumn('managed_compuestos.MLM', 'meli_price_manager_items.meli_item_id')
                            ->orWhere(function ($skuMatch): void {
                                $skuMatch
                                    ->whereNotNull('managed_compuestos.sku')
                                    ->whereRaw("TRIM(managed_compuestos.sku) <> ''")
                                    ->whereNotNull('meli_price_manager_items.sku')
                                    ->whereRaw("TRIM(meli_price_manager_items.sku) <> ''")
                                    ->whereColumn('managed_compuestos.sku', 'meli_price_manager_items.sku');
                            });
                    });
            });
        }

        if (Schema::hasTable('syscom_meli_queues')) {
            $query->whereNotExists(function ($subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('syscom_meli_queues as managed_syscom')
                    ->whereColumn(
                        'managed_syscom.mlm',
                        'meli_price_manager_items.meli_item_id'
                    );
            });
        }

        if (Schema::hasTable('automotive_part_meli_publications')) {
            $query->whereNotExists(function ($subquery): void {
                $subquery
                    ->selectRaw('1')
                    ->from('automotive_part_meli_publications as managed_autopartes')
                    ->whereColumn(
                        'managed_autopartes.meli_item_id',
                        'meli_price_manager_items.meli_item_id'
                    );
            });
        }

        return $query;
    }

    public function scopeFocusedCatalog(Builder $query): Builder
    {
        $query->managedCatalog();

        $allowedRoots = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            (array) config('meli_price_manager.focused_catalog.allowed_root_category_ids', []),
        )));
        $allowedCategories = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            (array) config('meli_price_manager.focused_catalog.allowed_category_ids', []),
        )));

        if ($allowedRoots === [] && $allowedCategories === []) {
            return $query;
        }

        return $query->where(function (Builder $focused) use ($allowedCategories, $allowedRoots): void {
            if ($allowedCategories !== []) {
                $focused->whereIn('meli_price_manager_items.category_id', $allowedCategories);
            }

            if (Schema::hasTable('meli_categories') && ($allowedRoots !== [] || $allowedCategories !== [])) {
                $focused->orWhereExists(function ($subquery) use ($allowedCategories, $allowedRoots): void {
                    $subquery->selectRaw('1')
                        ->from('meli_categories as focused_categories')
                        ->whereColumn('focused_categories.category_id', 'meli_price_manager_items.category_id')
                        ->where(function ($category) use ($allowedCategories, $allowedRoots): void {
                            if ($allowedRoots !== []) {
                                $category->whereIn('focused_categories.root_category_id', $allowedRoots);
                            }

                            if ($allowedCategories !== []) {
                                $method = $allowedRoots === [] ? 'whereRaw' : 'orWhereRaw';
                                $category->{$method}($this->ancestorCategorySql($allowedCategories), $allowedCategories);
                            }
                        });
                });
            }
        });
    }

    /** @param list<string> $categoryIds */
    private function ancestorCategorySql(array $categoryIds): string
    {
        $placeholders = implode(', ', array_fill(0, count($categoryIds), '?'));

        if (DB::connection()->getDriverName() === 'sqlite') {
            return "EXISTS (SELECT 1 FROM json_each(focused_categories.path_from_root) AS path_node WHERE json_extract(path_node.value, '$.id') IN ({$placeholders}))";
        }

        $checks = array_fill(
            0,
            count($categoryIds),
            "JSON_CONTAINS(focused_categories.path_from_root, JSON_OBJECT('id', ?))",
        );

        return '('.implode(' OR ', $checks).')';
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function brandGroup(): BelongsTo
    {
        return $this->belongsTo(MeliBrandGroup::class, 'brand_group_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MeliCategory::class, 'category_id', 'category_id');
    }

    public function suggestedBrandGroup(): BelongsTo
    {
        return $this->belongsTo(MeliBrandGroup::class, 'suggested_brand_group_id');
    }

    public function matchedBrandAlias(): BelongsTo
    {
        return $this->belongsTo(MeliBrandAlias::class, 'matched_brand_alias_id');
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(MeliPriceChange::class, 'price_manager_item_id');
    }
}
