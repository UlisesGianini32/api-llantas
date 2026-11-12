<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveWholesaleDiscountRule extends AutopartesModel
{
    protected $table = 'automotive_wholesale_discount_rules';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED_AMOUNT = 'fixed_amount';

    public const SCOPE_SAME_PRODUCT = 'same_product';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_ELIGIBLE_CART_TOTAL = 'eligible_cart_total';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED_AMOUNT,
    ];

    public const SCOPES = [
        self::SCOPE_SAME_PRODUCT,
        self::SCOPE_CATEGORY,
        self::SCOPE_ELIGIBLE_CART_TOTAL,
    ];

    protected $fillable = [
        'branch_id',
        'name',
        'is_active',
        'min_quantity',
        'max_quantity',
        'discount_type',
        'discount_value',
        'scope_type',
        'eligible_categories',
        'eligible_part_ids',
        'starts_at',
        'ends_at',
        'priority',
        'stackable',
        'allow_manual_combination',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'discount_value' => 'decimal:2',
            'eligible_categories' => 'array',
            'eligible_part_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'stackable' => 'boolean',
            'allow_manual_combination' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }
}
