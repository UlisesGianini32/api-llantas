<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliAccountUserAccess extends Model
{
    protected $fillable = [
        'meli_account_id',
        'user_id',
        'can_claim_actions',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'can_claim_actions' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }
}
