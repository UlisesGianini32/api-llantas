<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliReadiness extends Model
{
    public const STATUSES = ['unmapped', 'category_pending', 'incomplete', 'ready_for_review', 'ready'];

    protected $table = 'automotive_part_meli_readiness';

    protected $fillable = [
        'automotive_part_id', 'approved_category_candidate_id', 'status',
        'proposed_attributes', 'missing_required_attributes',
        'missing_conditional_attributes', 'compatibility_requirements', 'warnings',
        'evaluation_fingerprint', 'reviewed_by', 'reviewed_at', 'review_notes',
        'last_evaluated_at',
    ];

    protected $casts = [
        'proposed_attributes' => 'array',
        'missing_required_attributes' => 'array',
        'missing_conditional_attributes' => 'array',
        'compatibility_requirements' => 'array',
        'warnings' => 'array',
        'reviewed_at' => 'datetime',
        'last_evaluated_at' => 'datetime',
    ];

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class);
    }

    public function approvedCategoryCandidate(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartMeliCategoryCandidate::class, 'approved_category_candidate_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
