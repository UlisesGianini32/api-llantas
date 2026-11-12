<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePriceHistory extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_price_histories';

    protected $fillable = [
        'branch_id',
        'automotive_part_id',
        'previous_price_mxn',
        'new_price_mxn',
        'previous_price_status',
        'new_price_status',
        'previous_is_sellable',
        'new_is_sellable',
        'previous_sale_blocked',
        'new_sale_blocked',
        'operation_type',
        'batch_reference',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'previous_price_mxn' => 'decimal:2',
            'new_price_mxn' => 'decimal:2',
            'previous_is_sellable' => 'boolean',
            'new_is_sellable' => 'boolean',
            'previous_sale_blocked' => 'boolean',
            'new_sale_blocked' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }
}
