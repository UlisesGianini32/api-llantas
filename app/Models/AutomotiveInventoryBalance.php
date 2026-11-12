<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutomotiveInventoryBalance extends AutopartesModel
{
    protected $table = 'automotive_inventory_balances';

    protected $fillable = [
        'branch_id',
        'automotive_part_id',
        'automotive_location_id',
        'physical_quantity',
        'reserved_quantity',
    ];

    protected function casts(): array
    {
        return [
            'physical_quantity' => 'integer',
            'reserved_quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $balance): void {
            if ((int) $balance->physical_quantity < 0 || (int) $balance->reserved_quantity < 0) {
                throw ValidationException::withMessages([
                    'physical_quantity' => 'Las existencias físicas y reservadas de Autopartes nunca pueden ser negativas.',
                ]);
            }

            if ((int) $balance->reserved_quantity > (int) $balance->physical_quantity) {
                throw ValidationException::withMessages([
                    'reserved_quantity' => 'La cantidad reservada no puede exceder el stock físico disponible.',
                ]);
            }
        });
    }

    public function availableQuantity(): int
    {
        return max(0, (int) $this->physical_quantity - (int) $this->reserved_quantity);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'automotive_location_id');
    }
}
