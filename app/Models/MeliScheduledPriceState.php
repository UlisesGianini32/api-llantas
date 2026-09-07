<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliScheduledPriceState extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESTORE_PENDING = 'restore_pending';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RESTORE_PENDING, self::STATUS_RESTORED, self::STATUS_FAILED];

    protected $fillable = [
        'price_manager_item_id', 'meli_beauty_scheduled_discount_id', 'base_price', 'promotional_price',
        'last_confirmed_remote_price', 'last_observed_remote_price', 'status', 'applied_at',
        'restored_at', 'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'promotional_price' => 'decimal:2',
            'last_confirmed_remote_price' => 'decimal:2',
            'last_observed_remote_price' => 'decimal:2',
            'applied_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MeliPriceManagerItem::class, 'price_manager_item_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(MeliBeautyScheduledDiscount::class, 'meli_beauty_scheduled_discount_id');
    }
}
