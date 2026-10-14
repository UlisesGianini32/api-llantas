<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryShelfAuditItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_shelf_audit_id',
        'inventory_product_id',
        'system_quantity',
        'counted_quantity',
        'difference',
        'is_assigned_location',
        'notes',
        'adjusted',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'difference' => 'integer',
            'is_assigned_location' => 'boolean',
            'adjusted' => 'boolean',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(InventoryShelfAudit::class, 'inventory_shelf_audit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
