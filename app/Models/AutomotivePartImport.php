<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePartImport extends Model
{
    protected $table = 'automotive_part_imports';

    protected $fillable = [
        'original_filename',
        'stored_filename',
        'file_hash',
        'status',
        'total_rows',
        'imported_rows',
        'updated_rows',
        'duplicate_rows',
        'invalid_rows',
        'missing_compatibility_rows',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(AutomotivePartImportRow::class, 'automotive_part_import_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(AutomotivePartStockMovement::class, 'automotive_part_import_id');
    }
}
