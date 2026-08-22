<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartAiRun extends Model
{
    public const STATUSES = [
        'queued',
        'processing',
        'completed',
        'failed',
        'failed_validation',
        'refused',
        'skipped',
        'cancelled',
    ];

    protected $fillable = [
        'automotive_part_id',
        'automotive_part_enrichment_review_id',
        'status',
        'model',
        'prompt_version',
        'request_fingerprint',
        'input_snapshot',
        'output_payload',
        'response_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'attempt_count',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'input_snapshot' => 'array',
        'output_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class);
    }

    public function enrichmentReview(): BelongsTo
    {
        return $this->belongsTo(
            AutomotivePartEnrichmentReview::class,
            'automotive_part_enrichment_review_id',
        );
    }
}
