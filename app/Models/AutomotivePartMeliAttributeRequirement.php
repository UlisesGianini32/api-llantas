<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliAttributeRequirement extends Model
{
    protected $fillable = [
        'automotive_part_meli_category_id', 'attribute_id', 'name', 'value_type',
        'value_max_length', 'tags', 'allowed_values', 'hierarchy', 'is_required',
        'is_catalog_required', 'is_conditional_required', 'raw_payload',
    ];

    protected $casts = [
        'tags' => 'array',
        'allowed_values' => 'array',
        'is_required' => 'boolean',
        'is_catalog_required' => 'boolean',
        'is_conditional_required' => 'boolean',
        'raw_payload' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartMeliCategory::class, 'automotive_part_meli_category_id');
    }
}
