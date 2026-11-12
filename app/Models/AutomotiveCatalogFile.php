<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class AutomotiveCatalogFile extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_catalog_files';

    protected $fillable = ['sha256', 'semantic_sha256', 'original_name', 'disk', 'storage_path', 'size_bytes', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $file): void {
            if (! preg_match('/^[a-f0-9]{64}$/D', (string) $file->sha256) || $file->size_bytes < 0) {
                throw ValidationException::withMessages(['sha256' => 'Se requiere SHA-256 hexadecimal y tamaño válido.']);
            }
        });
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(AutomotiveCatalogSheet::class, 'file_id');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(AutomotiveImportRun::class, 'file_id');
    }
}
