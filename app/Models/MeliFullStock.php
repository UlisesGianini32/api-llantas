<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliFullStock extends Model
{
    protected $fillable = [
        'user_id',
        'meli_account_id',
        'meli_publication_id',
        'stock_key',
        'mlm',
        'variation_id',
        'sku',
        'title',
        'variation_label',
        'thumbnail',
        'permalink',
        'publication_status',
        'publication_sub_status',
        'publication_tags',
        'inventory_id',
        'user_product_id',
        'stock_source',
        'full_available_quantity',
        'full_not_available_quantity',
        'full_total_quantity',
        'not_available_detail',
        'raw_stock',
        'last_error',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'full_available_quantity' => 'integer',
            'full_not_available_quantity' => 'integer',
            'full_total_quantity' => 'integer',
            'not_available_detail' => 'array',
            'raw_stock' => 'array',
            'publication_sub_status' => 'array',
            'publication_tags' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(MeliPublication::class, 'meli_publication_id');
    }
}
