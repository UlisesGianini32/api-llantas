<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliCategoryCandidate extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'superseded'];

    public const SOURCES = ['deterministic', 'domain_discovery', 'category_predictor', 'manual'];

    protected $fillable = [
        'automotive_part_id', 'automotive_part_enrichment_review_id', 'status',
        'category_id', 'category_name', 'domain_id', 'source', 'query_text',
        'position', 'score', 'evidence', 'raw_payload', 'reviewed_by',
        'reviewed_at', 'review_notes',
    ];

    protected $casts = [
        'score' => 'decimal:4',
        'evidence' => 'array',
        'raw_payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class);
    }

    public function enrichmentReview(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartEnrichmentReview::class, 'automotive_part_enrichment_review_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
