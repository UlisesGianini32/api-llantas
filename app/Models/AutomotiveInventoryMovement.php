<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutomotiveInventoryMovement extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_inventory_movements';

    public const RECEIPT = 'RECEIPT';

    public const ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    public const SALE = 'SALE';

    public const RETURN = 'RETURN';

    public const CANCEL = 'CANCEL';

    public const TYPES = [
        self::RECEIPT,
        self::ADJUSTMENT_IN,
        self::ADJUSTMENT_OUT,
        self::SALE,
        self::RETURN,
        self::CANCEL,
    ];

    protected $fillable = [
        'branch_id',
        'automotive_part_id',
        'automotive_location_id',
        'type',
        'quantity',
        'stock_before',
        'stock_after',
        'reference_type',
        'reference_id',
        'reference',
        'notes',
        'metadata',
        'external_key',
        'created_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            if (! in_array($movement->type, self::TYPES, true) || (int) $movement->quantity === 0) {
                throw ValidationException::withMessages([
                    'type' => 'Movimiento de inventario inválido para Autopartes.',
                ]);
            }

            if ((int) $movement->stock_before < 0 || (int) $movement->stock_after < 0) {
                throw ValidationException::withMessages([
                    'stock_after' => 'El saldo de kardex en Autopartes nunca puede ser negativo.',
                ]);
            }
        });
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
