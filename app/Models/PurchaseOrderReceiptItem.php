<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderReceiptItem extends Model
{
    protected $fillable = [
        'purchase_order_receipt_id',
        'purchase_order_item_id',
        'inventory_product_id',
        'quantity_received',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceipt::class, 'purchase_order_receipt_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
