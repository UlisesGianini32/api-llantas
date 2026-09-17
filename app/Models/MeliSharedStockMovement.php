<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliSharedStockMovement extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'meli_account_id',
        'meli_order_id',
        'order_id',
        'movement_key',
        'type',
        'item_id',
        'variation_id',
        'sku',
        'applied_quantity',
        'last_adjustment',
        'last_status',
        'stock_before',
        'stock_after',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_quantity' => 'integer',
            'last_adjustment' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MeliSharedStockGroup::class, 'group_id');
    }
}
