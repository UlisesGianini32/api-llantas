<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliBeautyScheduledDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'meli_account_id', 'brand_group_id', 'discount_percentage', 'starts_at', 'ends_at',
        'timezone', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function brandGroup(): BelongsTo
    {
        return $this->belongsTo(MeliBrandGroup::class, 'brand_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function priceStates(): HasMany
    {
        return $this->hasMany(MeliScheduledPriceState::class, 'meli_beauty_scheduled_discount_id');
    }
}
