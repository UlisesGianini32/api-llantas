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

    public const PHOTO_MODE_AUTO = null;

    public const PHOTO_MODE_APPROVED_ML = 'approved_ml';

    public const PHOTO_MODE_MANUAL = 'manual';

    public const PHOTO_MODE_NONE = 'none';

    protected $fillable = [
        'purchase_order_id',
        'inventory_product_id',
        'sku',
        'supplier_sku',
        'photo_source_mode',
        'approved_mlm',
        'approved_variation_id',
        'approved_meli_account_id',
        'approved_photo_url',
        'manual_photo_path',
        'manual_photo_mime',
        'photo_notes',
        'rejected_mlms',
        'photo_updated_by',
        'photo_updated_at',
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
            'approved_meli_account_id' => 'integer',
            'rejected_mlms' => 'array',
            'photo_updated_by' => 'integer',
            'photo_updated_at' => 'datetime',
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function photoUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photo_updated_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public function inventoryProduct(): BelongsTo
    {
        return $this->product();
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
