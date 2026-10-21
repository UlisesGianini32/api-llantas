<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    use HasFactory;

    protected $table = 'supplier_products';

    protected $fillable = [
        'supplier_id',
        'supplier_name',
        'inventory_product_id',
        'supplier_sku',
        'supplier_product_name',
        'supplier_cost',
        'is_primary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'inventory_product_id' => 'integer',
            'supplier_cost' => 'decimal:2',
            'is_primary' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
