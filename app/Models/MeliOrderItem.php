<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeliOrderItem extends Model
{
    protected $table = 'meli_order_items';

    protected $fillable = [
        'meli_order_id',
        'item_id',
        'sku',
        'title',
        'variation_text',
        'quantity',
        'unit_price',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(MeliOrder::class, 'meli_order_id');
    }
}