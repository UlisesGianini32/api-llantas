<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliPublicationAttempt extends Model
{
    protected $guarded = [];
    protected $casts = ['sanitized_request' => 'array', 'sanitized_response' => 'array', 'transient' => 'boolean', 'ambiguous_result' => 'boolean', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    public function publication(): BelongsTo { return $this->belongsTo(AutomotivePartMeliPublication::class, 'publication_id'); }
}
