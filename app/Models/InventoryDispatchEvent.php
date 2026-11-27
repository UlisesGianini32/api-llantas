<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryDispatchEvent extends Model
{
    public const EVENT_DRAFT_CREATED = 'DRAFT_CREATED';

    public const EVENT_DRAFT_UPDATED = 'DRAFT_UPDATED';

    public const EVENT_DRAFT_CANCELLED = 'DRAFT_CANCELLED';

    public const EVENT_CONFIRMED = 'CONFIRMED';

    public const EVENT_CONFIRMED_CANCELLED = 'CONFIRMED_CANCELLED';

    public const EVENT_INVENTORY_APPLIED = 'INVENTORY_APPLIED';

    public const EVENT_INVENTORY_SKIPPED_LEDGER_DISABLED = 'INVENTORY_SKIPPED_LEDGER_DISABLED';

    public const EVENT_INVENTORY_SKIPPED_PRE_COUNT = 'INVENTORY_SKIPPED_PRE_COUNT';

    public const EVENT_INVENTORY_NEEDS_RECONCILIATION = 'INVENTORY_NEEDS_RECONCILIATION';

    public const EVENT_INVENTORY_DISCREPANCY_LOGGED = 'INVENTORY_DISCREPANCY_LOGGED';

    public const EVENT_PROVISIONAL_CREATED = 'PROVISIONAL_CREATED';

    public const EVENT_PROVISIONAL_LINKED = 'PROVISIONAL_LINKED';

    public const EVENT_PROVISIONAL_MERGED = 'PROVISIONAL_MERGED';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'inventory_dispatch_id' => 'integer',
            'inventory_dispatch_item_id' => 'integer',
            'provisional_item_id' => 'integer',
            'actor_user_id' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(InventoryDispatch::class, 'inventory_dispatch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryDispatchItem::class, 'inventory_dispatch_item_id');
    }

    public function provisionalItem(): BelongsTo
    {
        return $this->belongsTo(InventoryProvisionalItem::class, 'provisional_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
