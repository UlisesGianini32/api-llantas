<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlantaSkuCandidate extends Model
{
    protected $fillable = [
        'llanta_id',
        'sku_new',
        'description_new',
        'score',
        'status',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'score' => 'float',
    ];

    public function llanta()
    {
        return $this->belongsTo(Llanta::class);
    }
}
