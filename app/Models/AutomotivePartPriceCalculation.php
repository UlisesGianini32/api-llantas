<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartPriceCalculation extends Model
{
    protected $guarded = [];
    protected $casts = [
        'source_price' => 'decimal:4', 'exchange_rate' => 'decimal:6',
        'calculated_price_mxn' => 'decimal:2', 'calculation_breakdown' => 'array', 'calculated_at' => 'datetime',
    ];
    public function automotivePart(): BelongsTo { return $this->belongsTo(AutomotivePart::class); }
    public function rule(): BelongsTo { return $this->belongsTo(AutomotivePartPriceRule::class, 'price_rule_id'); }
}
