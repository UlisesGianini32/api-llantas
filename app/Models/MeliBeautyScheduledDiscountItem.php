<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliBeautyScheduledDiscountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'meli_beauty_scheduled_discount_id',
        'price_manager_item_id',
        'discount_percentage',
    ];

    protected function casts(): array
    {
        return ['discount_percentage' => 'decimal:2'];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(MeliBeautyScheduledDiscount::class, 'meli_beauty_scheduled_discount_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MeliPriceManagerItem::class, 'price_manager_item_id');
    }
}
