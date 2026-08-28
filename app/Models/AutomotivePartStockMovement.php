<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotivePartStockMovement extends Model
{
    protected $table = 'automotive_part_stock_movements';

    protected $fillable = [
        'automotive_part_id',
        'automotive_part_import_id',
        'previous_quantity',
        'new_quantity',
        'difference',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function automotivePart(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AutomotivePartImport::class, 'automotive_part_import_id');
    }
}
