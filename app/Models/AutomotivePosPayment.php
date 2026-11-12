<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePosPayment extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_pos_payments';

    protected $fillable = [
        'automotive_pos_sale_id',
        'method',
        'amount',
        'amount_tendered',
        'change_due',
        'reference',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_due' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosSale::class, 'automotive_pos_sale_id');
    }
}
