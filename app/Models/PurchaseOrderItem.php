<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'purchase_order_id',
        'inventory_product_id',
        'sku',
        'product_name',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'subtotal',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public function quantityPending(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }
}
