<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliAccount extends Model
{
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
}
