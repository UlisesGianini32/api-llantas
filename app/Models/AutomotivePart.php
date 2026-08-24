<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AutomotivePart extends Model
{
    protected $table = 'automotive_parts';

    protected $fillable = [
        'source_key',
        'item_number',
        'manufacturer_part_number',
        'vendor',
        'vendor_normalized',
        'category',
        'subcategory',
        'description_original',
        'description_normalized',
        'quantity',
        'original_currency',
        'retail_price_original',
        'min_model_year',
        'average_model_year',
        'max_model_year',
        'prevalent_model',
        'applicable_models_text',
        'length_inches',
        'width_inches',
        'height_inches',
        'cubic_inches',
        'weight_pounds',
        'length_cm',
        'width_cm',
        'height_cm',
        'weight_kg',
        'lifecycle',
        'data_status',
        'missing_fields',
        'last_import_id',
        'last_imported_at',
    ];

    protected $casts = [
        'retail_price_original' => 'decimal:4',
        'length_inches' => 'decimal:4',
        'width_inches' => 'decimal:4',
        'height_inches' => 'decimal:4',
        'cubic_inches' => 'decimal:4',
        'weight_pounds' => 'decimal:4',
        'length_cm' => 'decimal:4',
        'width_cm' => 'decimal:4',
        'height_cm' => 'decimal:4',
        'weight_kg' => 'decimal:4',
        'last_imported_at' => 'datetime',
        'missing_fields' => 'array',
    ];

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartImport::class, 'last_import_id');
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(AutomotivePartImportRow::class, 'automotive_part_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(AutomotivePartStockMovement::class, 'automotive_part_id');
    }

    public function enrichmentReview(): HasOne
    {
        return $this->hasOne(AutomotivePartEnrichmentReview::class, 'automotive_part_id');
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AutomotivePartAiRun::class, 'automotive_part_id');
    }

    public function meliCategoryCandidates(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliCategoryCandidate::class);
    }

    public function meliReadiness(): HasOne
    {
        return $this->hasOne(AutomotivePartMeliReadiness::class);
    }

    public function meliDrafts(): HasMany
    {
        return $this->hasMany(AutomotivePartMeliDraft::class);
    }

    public function latestMeliDraft(): HasOne
    {
        return $this->hasOne(AutomotivePartMeliDraft::class)->latestOfMany('version');
    }
}
