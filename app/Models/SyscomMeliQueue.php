<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyscomMeliQueue extends Model
{
    protected $table = 'syscom_meli_queues';

    protected $fillable = [
        'user_id',
        'syscom_product_id',
        'syscom_producto_id',
        'branch_code',
        'status',
        'price_scope',
        'price_mode',
        'price_locked_at',
        'desired_price',
        'mlm',
        'publish_error',
        'last_stock_synced_at',
        'last_price_synced_at',
    ];

    protected $casts = [
        'desired_price' => 'decimal:2',
        'price_locked_at' => 'datetime',
        'last_stock_synced_at' => 'datetime',
        'last_price_synced_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(SyscomProduct::class, 'syscom_product_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
