<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePartMedia extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'archived'];
    public const PROVENANCE_TYPES = ['user_upload', 'supplier_file', 'manufacturer_file', 'owned_photo'];

    protected $table = 'automotive_part_media';
    protected $guarded = [];
    protected $casts = [
        'is_primary' => 'boolean', 'metadata' => 'array', 'uploaded_at' => 'datetime',
        'approved_at' => 'datetime', 'rejected_at' => 'datetime',
    ];

    public function automotivePart(): BelongsTo { return $this->belongsTo(AutomotivePart::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejecter(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function events(): HasMany { return $this->hasMany(AutomotivePartMediaEvent::class); }
}
