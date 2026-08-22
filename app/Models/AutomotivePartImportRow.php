<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartImportRow extends Model
{
    protected $table = 'automotive_part_import_rows';

    protected $fillable = [
        'automotive_part_import_id',
        'row_number',
        'source_key',
        'category_raw',
        'subcategory_raw',
        'item_number_raw',
        'manufacturer_part_number_raw',
        'vendor_raw',
        'description_raw',
        'quantity_raw',
        'retail_raw',
        'extended_retail_raw',
        'lifecycle_raw',
        'min_model_year_raw',
        'average_model_year_raw',
        'max_model_year_raw',
        'prevalent_model_raw',
        'applicable_models_raw',
        'length_raw',
        'width_raw',
        'height_raw',
        'cubic_inches_raw',
        'weight_raw',
        'extended_weight_raw',
        'normalized_payload',
        'validation_errors',
        'duplicate_of_row_id',
        'automotive_part_id',
    ];

    protected $casts = [
        'normalized_payload' => 'array',
        'validation_errors' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartImport::class, 'automotive_part_import_id');
    }

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_row_id');
    }
}
