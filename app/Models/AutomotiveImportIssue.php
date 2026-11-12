<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutomotiveImportIssue extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_import_issues';

    protected $fillable = ['import_run_id', 'source_row_id', 'column_reference', 'severity', 'code', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $issue): void {
            if (! in_array($issue->severity, ['error', 'warning'], true)) {
                throw ValidationException::withMessages(['severity' => 'Gravedad no válida.']);
            }
            if ($issue->source_row_id !== null) {
                $row = AutomotiveCatalogRow::with('sheet')->findOrFail($issue->source_row_id);
                $run = AutomotiveImportRun::findOrFail($issue->import_run_id);
                if ($row->sheet->file_id !== $run->file_id) {
                    throw ValidationException::withMessages(['source_row_id' => 'La fila debe pertenecer al archivo de la ejecución.']);
                }
            }
        });
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(AutomotiveImportRun::class, 'import_run_id');
    }

    public function sourceRow(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCatalogRow::class, 'source_row_id');
    }
}
