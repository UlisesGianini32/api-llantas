<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePartMeliDraft extends Model
{
    public const STATUSES = ['draft', 'incomplete', 'pending_review', 'approved', 'rejected', 'stale'];

    public const VALIDATION_CODES = [
        'missing_approved_enrichment', 'missing_approved_category', 'stale_category_mapping',
        'missing_price_mxn', 'missing_exchange_rate', 'invalid_price_configuration',
        'invalid_stock', 'missing_images', 'missing_required_attribute',
        'missing_compatibility', 'invalid_title', 'invalid_description',
        'unsupported_currency', 'unsupported_condition', 'readiness_not_ready',
        'stale_source_data',
    ];

    protected $fillable = [
        'automotive_part_id', 'automotive_part_enrichment_review_id',
        'approved_category_candidate_id', 'version', 'category_id', 'category_name',
        'domain_id', 'title', 'description', 'price_mxn', 'stock', 'currency',
        'condition', 'prepared_attributes', 'prepared_compatibilities',
        'prepared_images', 'source_snapshot', 'fingerprint', 'status',
        'blocking_errors', 'warnings', 'reviewed_by', 'review_notes',
        'generated_at', 'reviewed_at', 'approved_at',
    ];

    protected $casts = [
        'price_mxn' => 'decimal:2',
        'prepared_attributes' => 'array',
        'prepared_compatibilities' => 'array',
        'prepared_images' => 'array',
        'source_snapshot' => 'array',
        'blocking_errors' => 'array',
        'warnings' => 'array',
        'generated_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class);
    }

    public function enrichmentReview(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartEnrichmentReview::class, 'automotive_part_enrichment_review_id');
    }

    public function approvedCategoryCandidate(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartMeliCategoryCandidate::class, 'approved_category_candidate_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliDraftEvent::class)->latest('id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliPublication::class);
    }

    public function hasBlockingErrors(): bool
    {
        return ($this->blocking_errors ?? []) !== [];
    }
}
