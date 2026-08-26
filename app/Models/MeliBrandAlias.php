<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliBrandAlias extends Model
{
    use HasFactory;

    public const MATCH_TYPES = ['exact', 'contains', 'starts_with', 'manual'];

    protected $fillable = ['brand_group_id', 'alias', 'normalized_alias', 'match_type', 'priority', 'active'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'active' => 'boolean'];
    }

    public function brandGroup(): BelongsTo
    {
        return $this->belongsTo(MeliBrandGroup::class, 'brand_group_id');
    }

    public function matchedItems(): HasMany
    {
        return $this->hasMany(MeliPriceManagerItem::class, 'matched_brand_alias_id');
    }
}
