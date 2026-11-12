<?php

namespace App\Models;

use App\Models\Concerns\ImmutableSourceRecord;
use App\Support\AutomotiveSourceSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class AutomotiveCatalogRow extends AutopartesModel
{
    use ImmutableSourceRecord;

    protected $table = 'automotive_catalog_rows';

    protected $fillable = [
        'sheet_id', 'row_number', 'cells', 'semantic_hash', 'identity_key',
        'item_number', 'manufacturer_part_number', 'description', 'category', 'subcategory', 'vendor_name', 'search_text',
    ];

    protected function casts(): array
    {
        return ['cells' => 'array', 'row_number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            AutomotiveSourceSchema::validateCells($row->cells);
            $sheet = AutomotiveCatalogSheet::findOrFail($row->sheet_id);
            if ($row->row_number <= $sheet->header_row_number) {
                throw ValidationException::withMessages(['row_number' => 'La fila de datos debe seguir al encabezado.']);
            }
            $row->row_hash = hash('sha256', json_encode($row->cells, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCatalogSheet::class, 'sheet_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AutomotiveSourceProductDecision::class, 'source_row_id');
    }

    public function currentDecision(): HasOne
    {
        return $this->hasOne(AutomotiveSourceProductDecision::class, 'source_row_id')->latestOfMany();
    }
}
