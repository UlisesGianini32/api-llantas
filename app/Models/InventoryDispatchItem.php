<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryDispatchItem extends Model
{
    public const CAPTURE_BARCODE_SCAN = 'BARCODE_SCAN';

    public const CAPTURE_SKU_SEARCH = 'SKU_SEARCH';

    public const CAPTURE_TEXT_SEARCH = 'TEXT_SEARCH';

    public const CAPTURE_MANUAL_PROVISIONAL = 'MANUAL_PROVISIONAL';

    /** @var list<string> */
    public const CAPTURE_METHODS = [
        self::CAPTURE_BARCODE_SCAN,
        self::CAPTURE_SKU_SEARCH,
        self::CAPTURE_TEXT_SEARCH,
        self::CAPTURE_MANUAL_PROVISIONAL,
    ];

    public const ID_REGISTERED = 'REGISTERED';

    public const ID_PROVISIONAL = 'PROVISIONAL';

    public const ID_AMBIGUOUS_PENDING = 'AMBIGUOUS_PENDING';

    public const ID_LINKED_POST_DISPATCH = 'LINKED_POST_DISPATCH';

    public const APPLY_PENDING = 'PENDING';

    public const APPLY_APPLIED = 'APPLIED';

    public const APPLY_SKIPPED_PROVISIONAL = 'SKIPPED_PROVISIONAL';

    public const APPLY_SKIPPED_LEDGER_DISABLED = 'SKIPPED_LEDGER_DISABLED';

    public const APPLY_SKIPPED_UNKNOWN_CONVERSION = 'SKIPPED_UNKNOWN_CONVERSION';

    public const APPLY_SKIPPED_PRE_PHYSICAL_COUNT = 'SKIPPED_PRE_PHYSICAL_COUNT';

    public const APPLY_DISCREPANCY_INSUFFICIENT_STOCK = 'DISCREPANCY_INSUFFICIENT_STOCK';

    public const APPLY_NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    public const APPLY_CANCELLED_NO_MOVEMENT = 'CANCELLED_NO_MOVEMENT';

    public const MELI_NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const MELI_NOT_AUTHORIZED = 'NOT_AUTHORIZED';

    /** @var list<string> */
    public const APPLY_STATUSES = [
        self::APPLY_PENDING,
        self::APPLY_APPLIED,
        self::APPLY_SKIPPED_PROVISIONAL,
        self::APPLY_SKIPPED_LEDGER_DISABLED,
        self::APPLY_SKIPPED_UNKNOWN_CONVERSION,
        self::APPLY_SKIPPED_PRE_PHYSICAL_COUNT,
        self::APPLY_DISCREPANCY_INSUFFICIENT_STOCK,
        self::APPLY_NEEDS_RECONCILIATION,
        self::APPLY_CANCELLED_NO_MOVEMENT,
    ];

    protected $fillable = [
        'line_notes',
    ];

    protected function casts(): array
    {
        return [
            'inventory_dispatch_id' => 'integer',
            'line_order' => 'integer',
            'inventory_product_id' => 'integer',
            'provisional_item_id' => 'integer',
            'inventory_location_id' => 'integer',
            'captured_quantity' => 'integer',
            'unit_conversion_factor' => 'integer',
            'normalized_physical_quantity' => 'integer',
            'quantity_returned' => 'integer',
            'unit_cost_snapshot' => 'decimal:2',
            'unit_price_stylist_snapshot' => 'decimal:2',
            'linked_at' => 'datetime',
            'linked_by_user_id' => 'integer',
            'cutover_evaluated_at' => 'datetime',
            'cutover_reference_id' => 'integer',
            'cutover_boundary_at' => 'datetime',
            'inventory_movement_id' => 'integer',
            'inventory_applied_at' => 'datetime',
            'inventory_applied_by_user_id' => 'integer',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(InventoryDispatch::class, 'inventory_dispatch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public function provisionalItem(): BelongsTo
    {
        return $this->belongsTo(InventoryProvisionalItem::class, 'provisional_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inventory_applied_by_user_id');
    }
}
