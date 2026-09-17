<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliSharedStockMember extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'meli_account_id',
        'meli_publication_id',
        'member_key',
        'mlm',
        'variation_id',
        'sku',
        'role',
        'match_method',
        'is_active',
        'is_fulfillment',
        'last_push_at',
        'last_push_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_fulfillment' => 'boolean',
            'last_push_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MeliSharedStockGroup::class, 'group_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(MeliPublication::class, 'meli_publication_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class, 'meli_account_id');
    }
}
