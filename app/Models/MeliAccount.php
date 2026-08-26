<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'meli_user_id',
        'nickname',
        'official_store_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'is_default',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_default' => 'boolean',
            'official_store_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MeliOrder::class);
    }

    public function chatFlows(): HasMany
    {
        return $this->hasMany(MeliChatFlow::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(MeliQuestion::class);
    }


    public function priceManagerItems(): HasMany
    {
        return $this->hasMany(MeliPriceManagerItem::class);
    }

    public function priceChangeBatches(): HasMany
    {
        return $this->hasMany(MeliPriceChangeBatch::class);
    }
}
