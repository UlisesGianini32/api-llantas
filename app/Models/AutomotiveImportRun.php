<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotiveImportRun extends AutopartesModel
{
    protected $table = 'automotive_import_runs';

    protected $fillable = [
        'file_id', 'parser_version', 'mapping_version', 'idempotency_key', 'status',
        'counters', 'requested_by', 'started_at', 'finished_at', 'branch_id', 'reference_file_id',
        'uploaded_name', 'preview', 'processed_rows', 'lock_token', 'lease_expires_at', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'counters' => 'array',
            'preview' => 'array',
            'processed_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCatalogFile::class, 'file_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AutomotiveImportIssue::class, 'import_run_id');
    }
}
