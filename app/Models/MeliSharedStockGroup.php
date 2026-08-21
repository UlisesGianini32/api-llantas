<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliSharedStockGroup extends Model
{
    protected $fillable = [
        'user_id',
        'master_account_id',
        'group_key',
        'link_key',
        'sku',
        'master_mlm',
        'master_variation_id',
        'stock',
        'link_method',
        'is_enabled',
        'activated_at',
        'last_pushed_at',
        'last_reconciled_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'is_enabled' => 'boolean',
            'activated_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(MeliSharedStockMember::class, 'group_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MeliSharedStockMovement::class, 'group_id');
    }
}
