<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlantaComparisonDecision extends Model
{
    protected $fillable = [
        'llanta_a_id',
        'llanta_b_id',
        'score',
        'reasons',
        'differences',
        'status',
        'decided_by',
        'decided_at',
        'last_detected_at',
    ];

    protected $casts = [
        'score' => 'float',
        'reasons' => 'array',
        'differences' => 'array',
        'decided_at' => 'datetime',
        'last_detected_at' => 'datetime',
    ];

    public function llantaA()
    {
        return $this->belongsTo(Llanta::class, 'llanta_a_id');
    }

    public function llantaB()
    {
        return $this->belongsTo(Llanta::class, 'llanta_b_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
