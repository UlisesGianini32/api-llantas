<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class AutomotiveCashRegister extends AutopartesModel
{
    protected $table = 'automotive_cash_registers';

    protected $fillable = [
        'branch_id',
        'automotive_location_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $register): void {
            if (! AutomotiveLocation::query()
                ->whereKey($register->automotive_location_id)
                ->where('branch_id', $register->branch_id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'automotive_location_id' => 'La ubicación de la caja debe pertenecer a la sucursal de Autopartes.',
                ]);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'automotive_location_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(AutomotivePosShift::class, 'cash_register_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(AutomotivePosSale::class, 'cash_register_id');
    }
}
