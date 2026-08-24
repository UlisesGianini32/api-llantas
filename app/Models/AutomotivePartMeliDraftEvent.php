<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartMeliDraftEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'automotive_part_meli_draft_id', 'action', 'from_status', 'to_status',
        'user_id', 'notes', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartMeliDraft::class, 'automotive_part_meli_draft_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
