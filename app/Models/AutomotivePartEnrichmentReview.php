<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AutomotivePartEnrichmentReview extends Model
{
    public const STATUSES = ['pending', 'in_review', 'approved', 'rejected'];

    public const SOURCES = ['manual', 'rules', 'future_ai', 'openai'];

    protected $fillable = [
        'automotive_part_id',
        'status',
        'issue_codes',
        'proposed_title',
        'proposed_description',
        'proposed_brand',
        'proposed_category',
        'proposed_compatibility',
        'proposed_attributes',
        'confidence_score',
        'enrichment_source',
        'reviewer_notes',
        'reviewed_by',
        'reviewed_at',
        'metadata',
    ];

    protected $casts = [
        'issue_codes' => 'array',
        'proposed_compatibility' => 'array',
        'proposed_attributes' => 'array',
        'confidence_score' => 'decimal:4',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AutomotivePartAiRun::class, 'automotive_part_enrichment_review_id');
    }

    public function latestAiRun(): HasOne
    {
        return $this->hasOne(AutomotivePartAiRun::class, 'automotive_part_enrichment_review_id')->latestOfMany();
    }

    public function meliCategoryCandidates(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliCategoryCandidate::class);
    }

    public function meliDrafts(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliDraft::class);
    }
}
