<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AutomotiveReceiptItem extends AutopartesModel
{
    protected $table = 'automotive_receipt_items';

    public const MODE_SCAN = 'scan';

    public const MODE_MANUAL = 'manual_assisted';

    public const MODE_DISAMBIGUATED = 'disambiguated_scan';

    public const MODES = [
        self::MODE_SCAN,
        self::MODE_MANUAL,
        self::MODE_DISAMBIGUATED,
    ];

    protected $fillable = [
        'automotive_receipt_id',
        'automotive_part_id',
        'scanned_code',
        'entry_mode',
        'identity_status_snapshot',
        'quantity',
        'scan_events_count',
        'count_detail',
        'stock_before',
        'stock_after',
        'automotive_movement_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'scan_events_count' => 'integer',
            'count_detail' => 'array',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $item): void {
            if ($item->automotive_movement_id !== null || $item->receipt?->status === AutomotiveReceipt::STATUS_CONFIRMED) {
                throw new LogicException('No se pueden eliminar líneas de una recepción confirmada.');
            }
        });
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(AutomotiveReceipt::class, 'automotive_receipt_id');
    }

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(AutomotiveInventoryMovement::class, 'automotive_movement_id');
    }
}
