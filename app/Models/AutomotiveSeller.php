<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AutomotiveSeller extends AutopartesModel
{
    protected $table = 'automotive_sellers';

    protected $fillable = [
        'branch_id',
        'user_id',
        'code',
        'full_name',
        'phone',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Los vendedores no se pueden eliminar para preservar la trazabilidad histórica; desactívelos en su lugar.');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(AutomotivePosSale::class, 'seller_id');
    }
}
