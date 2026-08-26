<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliPriceManagerItem extends Model
{
    use HasFactory;

    public const CLASSIFICATION_STATUSES = ['categorized', 'suggested', 'uncategorized', 'ignored'];

    protected $fillable = [
        'meli_account_id', 'meli_item_id', 'sku', 'title', 'category_id', 'listing_type_id',
        'catalog_product_id', 'meli_brand', 'normalized_brand', 'brand_group_id',
        'suggested_brand_group_id', 'matched_brand_alias_id',
        'classification_status', 'classification_source', 'classification_confidence',
        'classification_metadata',
        'current_price', 'original_price', 'available_quantity', 'sold_quantity', 'currency_id',
        'status', 'permalink', 'thumbnail', 'raw_attributes', 'raw_item', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'classification_confidence' => 'decimal:4',
            'classification_metadata' => 'array',
            'current_price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'available_quantity' => 'integer',
            'sold_quantity' => 'integer',
            'raw_attributes' => 'array',
            'raw_item' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function brandGroup(): BelongsTo
    {
        return $this->belongsTo(MeliBrandGroup::class, 'brand_group_id');
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
