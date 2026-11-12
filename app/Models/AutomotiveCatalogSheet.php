<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use App\Support\AutomotiveSourceSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class AutomotiveCatalogSheet extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_catalog_sheets';

    protected $fillable = ['file_id', 'sheet_index', 'name', 'header_row_number', 'headers', 'header_cells', 'data_row_count', 'column_count'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'header_cells' => 'array',
            'sheet_index' => 'integer',
            'header_row_number' => 'integer',
            'data_row_count' => 'integer',
            'column_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sheet): void {
            if ($sheet->headers !== AutomotiveSourceSchema::HEADERS || $sheet->column_count !== 21
                || $sheet->sheet_index < 0 || $sheet->header_row_number < 1 || $sheet->data_row_count < 0) {
                throw ValidationException::withMessages(['headers' => 'El formato debe conservar los 21 encabezados exactos y dimensiones válidas.']);
            }
        });
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCatalogFile::class, 'file_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AutomotiveCatalogRow::class, 'sheet_id');
    }
}
