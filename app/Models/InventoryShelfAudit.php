<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryShelfAudit extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_APPLIED = 'APPLIED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const AUDIT_TYPE_SHELF = 'shelf';
    public const AUDIT_TYPE_BRAND = 'brand';

    protected $fillable = [
        'audit_number',
        'audit_type',
        'brand_name',
        'shelf_code',
        'inventory_location_id',
        'user_id',
        'status',
        'total_expected_products',
        'total_counted_products',
        'total_system_units',
        'total_counted_units',
        'total_difference_units',
        'matched_items_count',
        'surplus_items_count',
        'missing_items_count',
        'notes',
        'applied_at',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'total_expected_products' => 'integer',
            'total_counted_products' => 'integer',
            'total_system_units' => 'integer',
            'total_counted_units' => 'integer',
            'total_difference_units' => 'integer',
            'matched_items_count' => 'integer',
            'surplus_items_count' => 'integer',
            'missing_items_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryShelfAuditItem::class, 'inventory_shelf_audit_id');
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
