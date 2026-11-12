<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePosCashMovement extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_pos_cash_movements';

    public const TYPE_IN = 'IN';

    public const TYPE_OUT = 'OUT';

    protected $fillable = [
        'automotive_pos_shift_id',
        'branch_id',
        'user_id',
        'type',
        'amount',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosShift::class, 'automotive_pos_shift_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }
}
