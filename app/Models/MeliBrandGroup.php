<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliBrandGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(MeliBrandAlias::class, 'brand_group_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MeliPriceManagerItem::class, 'brand_group_id');
    }

    public function priceChangeBatches(): HasMany
    {
        return $this->hasMany(MeliPriceChangeBatch::class, 'brand_group_id');
    }
}
