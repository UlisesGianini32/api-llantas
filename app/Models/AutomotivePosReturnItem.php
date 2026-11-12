<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePosReturnItem extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_pos_return_items';

    protected $fillable = [
        'automotive_pos_return_id',
        'automotive_pos_sale_item_id',
        'automotive_part_id',
        'automotive_location_id',
        'automotive_movement_id',
        'quantity',
        'unit_refund_amount',
        'refund_subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_refund_amount' => 'decimal:2',
            'refund_subtotal' => 'decimal:2',
        ];
    }

    public function returnRecord(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosReturn::class, 'automotive_pos_return_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosSaleItem::class, 'automotive_pos_sale_item_id');
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
}
