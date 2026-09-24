<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class InventoryLocation extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(InventoryProduct::class, 'primary_location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_location_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'inventory_location_id');
    }

    public function setCodeAttribute($value): void
    {
        $code = mb_strtoupper(trim((string) $value));
        if ($code === '') {
            throw new InvalidArgumentException('El código de ubicación no puede estar vacío.');
        }

        $this->attributes['code'] = $code;
    }
}
