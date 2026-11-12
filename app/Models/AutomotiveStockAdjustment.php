<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AutomotiveStockAdjustment extends AutopartesModel
{
    protected $table = 'automotive_stock_adjustments';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const TYPE_IN = AutomotiveInventoryMovement::ADJUSTMENT_IN;

    public const TYPE_OUT = AutomotiveInventoryMovement::ADJUSTMENT_OUT;

    public const TYPES = [
        self::TYPE_IN,
        self::TYPE_OUT,
    ];

    protected $fillable = [
        'folio',
        'branch_id',
        'automotive_location_id',
        'automotive_part_id',
        'type',
        'quantity',
        'reason',
        'notes',
        'status',
        'idempotency_key',
        'payload_hash',
        'stock_before',
        'stock_after',
        'automotive_movement_id',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            if ($adjustment->getOriginal('status') === self::STATUS_APPROVED) {
                throw new LogicException('Un ajuste aprobado es inmutable.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Los ajustes de inventario auditados no se pueden eliminar.');
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

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(AutomotiveInventoryMovement::class, 'automotive_movement_id');
    }
}
