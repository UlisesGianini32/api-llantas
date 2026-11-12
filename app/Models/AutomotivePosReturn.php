<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePosReturn extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_pos_returns';

    public const TYPE_CANCELLATION = 'cancellation';

    public const TYPE_RETURN = 'return';

    public const REFUND_CASH = 'cash';

    public const REFUND_CARD_REVERSAL = 'card_reversal';

    public const REFUND_TRANSFER_REVERSAL = 'transfer_reversal';

    public const REFUND_ORIGINAL_METHOD = 'original_method';

    public const REFUND_METHODS = [
        self::REFUND_CASH,
        self::REFUND_CARD_REVERSAL,
        self::REFUND_TRANSFER_REVERSAL,
        self::REFUND_ORIGINAL_METHOD,
    ];

    protected $fillable = [
        'folio',
        'automotive_pos_sale_id',
        'branch_id',
        'automotive_pos_shift_id',
        'processed_by',
        'type',
        'reason',
        'refund_method',
        'refund_reference',
        'refund_amount',
        'idempotency_key',
        'payload_hash',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosSale::class, 'automotive_pos_sale_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosShift::class, 'automotive_pos_shift_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AutomotivePosReturnItem::class, 'automotive_pos_return_id');
    }
}
