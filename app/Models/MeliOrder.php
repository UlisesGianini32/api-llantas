<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliOrder extends Model
{
    protected $table = 'meli_orders';

    protected $fillable = [
        'meli_account_id',
        'order_id',
        'topic',
        'resource',
        'status',
        'shipping_id',
        'shipping_status',
        'shipping_substatus',
        'shipping_mode',
        'shipping_type',
        'shipping_logistic_type',
        'shipping_process_date',
        'display_id',
        'shipping_raw',
        'raw',
        'processed_at',
        'stock_applied_at',
        'syscom_order_folio',
        'syscom_order_synced_at',
        'syscom_order_error',
        'syscom_order_raw',
        'syscom_order_cancelled_at',
        'syscom_order_cancel_error',
        'syscom_order_cancel_raw',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'shipping_raw' => 'array',
        'shipping_process_date' => 'date',
        'processed_at' => 'datetime',
        'stock_applied_at' => 'datetime',
        'syscom_order_synced_at' => 'datetime',
        'syscom_order_raw' => 'array',
        'syscom_order_cancelled_at' => 'datetime',
        'syscom_order_cancel_raw' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MeliOrderItem::class, 'meli_order_id');
    }
}