<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AutomotivePosSaleItem extends AutopartesModel
{
    protected $table = 'automotive_pos_sale_items';

    protected $fillable = [
        'automotive_pos_sale_id',
        'automotive_part_id',
        'automotive_location_id',
        'automotive_movement_id',
        'part_code_snapshot',
        'item_number_snapshot',
        'mfg_part_number_snapshot',
        'description_snapshot',
        'category_snapshot',
        'quantity',
        'returned_quantity',
        'unit_price_mxn',
        'line_gross_amount',
        'wholesale_discount_amount',
        'manual_discount_amount',
        'discount_amount',
        'tax_amount',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
            'unit_price_mxn' => 'decimal:2',
            'line_gross_amount' => 'decimal:2',
            'wholesale_discount_amount' => 'decimal:2',
            'manual_discount_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Las partidas de venta del POS de Autopartes no se pueden eliminar.');
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosSale::class, 'automotive_pos_sale_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'automotive_location_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(AutomotiveInventoryMovement::class, 'automotive_movement_id');
    }

    public function remainingReturnableQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }
}
