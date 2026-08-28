<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePartMeliPublication extends Model
{
    public const STATUSES = ['draft', 'local_invalid', 'local_valid', 'validating', 'validation_failed', 'validated', 'final_approved', 'queued', 'uploading_pictures', 'publishing', 'item_created', 'description_pending', 'published', 'published_pending_compatibility', 'partial_failure', 'reconciliation_required', 'failed', 'cancelled', 'stale'];

    protected $guarded = [];
    protected $casts = [
        'local_payload' => 'array', 'validation_payload' => 'array', 'validation_response' => 'array',
        'publication_response' => 'array', 'metadata' => 'array', 'remote_validated_at' => 'datetime',
        'remote_validation_expires_at' => 'datetime', 'final_approved_at' => 'datetime',
        'published_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function automotivePart(): BelongsTo { return $this->belongsTo(AutomotivePart::class); }
    public function draft(): BelongsTo { return $this->belongsTo(AutomotivePartMeliDraft::class, 'automotive_part_meli_draft_id'); }
    public function account(): BelongsTo { return $this->belongsTo(MeliAccount::class, 'meli_account_id'); }
    public function finalApprover(): BelongsTo { return $this->belongsTo(User::class, 'final_approved_by'); }
    public function attempts(): HasMany { return $this->hasMany(AutomotivePartMeliPublicationAttempt::class, 'publication_id'); }
    public function pictureUploads(): HasMany { return $this->hasMany(AutomotivePartMeliPictureUpload::class, 'publication_id'); }
    public function events(): HasMany { return $this->hasMany(AutomotivePartMeliPublicationEvent::class, 'publication_id')->latest('id'); }
}
