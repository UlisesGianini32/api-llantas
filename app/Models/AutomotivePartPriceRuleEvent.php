<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartPriceRuleEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function rule(): BelongsTo { return $this->belongsTo(AutomotivePartPriceRule::class, 'automotive_part_price_rule_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
