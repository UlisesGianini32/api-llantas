<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutomotiveSourceProductDecision extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_source_part_decisions';

    protected $fillable = ['source_row_id', 'automotive_part_id', 'supersedes_id', 'decision', 'reason', 'decided_by'];

    protected static function booted(): void
    {
        static::creating(function (self $decision): void {
            if (! in_array($decision->decision, ['linked', 'ambiguous', 'unlinked'], true) || blank($decision->reason)) {
                throw ValidationException::withMessages(['decision' => 'La decisión requiere estado y motivo explícitos.']);
            }
            if (($decision->decision === 'linked') !== ($decision->automotive_part_id !== null)) {
                throw ValidationException::withMessages(['automotive_part_id' => 'Solo una asociación confirmada puede indicar una autoparte.']);
            }
            if ($decision->automotive_part_id !== null && ! AutomotivePart::query()
                ->whereKey($decision->automotive_part_id)->exists()) {
                throw ValidationException::withMessages(['automotive_part_id' => 'Se requiere una autoparte válida del catálogo exclusivo.']);
            }
            if ($decision->supersedes_id !== null && ! self::query()->whereKey($decision->supersedes_id)
                ->where('source_row_id', $decision->source_row_id)->exists()) {
                throw ValidationException::withMessages(['supersedes_id' => 'Solo se puede sustituir una decisión de la misma fila.']);
            }
        });
    }

    public function sourceRow(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCatalogRow::class, 'source_row_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
