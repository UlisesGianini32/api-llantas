<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePartPriceRule extends Model
{
    public const SCOPES = ['global', 'category', 'vendor', 'automotive_part'];
    public const STATUSES = ['draft', 'active', 'inactive', 'superseded'];
    public const ROUNDING_MODES = ['none', 'nearest', 'up', 'down'];

    protected $guarded = [];
    protected $casts = [
        'usd_mxn_rate' => 'decimal:6', 'markup_percent' => 'decimal:4',
        'meli_fee_percent' => 'decimal:4', 'fixed_cost_mxn' => 'decimal:4',
        'rounding_increment' => 'decimal:4', 'minimum_price_mxn' => 'decimal:2',
        'maximum_price_mxn' => 'decimal:2', 'metadata' => 'array',
        'effective_from' => 'datetime', 'effective_until' => 'datetime', 'approved_at' => 'datetime',
    ];

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function events(): HasMany { return $this->hasMany(AutomotivePartPriceRuleEvent::class); }
    public function calculations(): HasMany { return $this->hasMany(AutomotivePartPriceCalculation::class, 'price_rule_id'); }
}
