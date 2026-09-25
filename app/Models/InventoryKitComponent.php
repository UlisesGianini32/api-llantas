<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryKitComponent extends Model
{
    protected $fillable = [
        'kit_product_id',
        'component_product_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'kit_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'component_product_id');
    }
}
