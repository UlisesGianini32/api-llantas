<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderReceipt extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'user_id',
        'inventory_location_id',
        'receipt_number',
        'received_at',
        'total_units_received',
        'carrier',
        'tracking_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'total_units_received' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptItem::class, 'purchase_order_receipt_id');
    }
}
