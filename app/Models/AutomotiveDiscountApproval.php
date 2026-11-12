<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveDiscountApproval extends AutopartesModel
{
    protected $table = 'automotive_discount_approvals';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_INVALIDATED = 'invalidated';

    protected $fillable = [
        'branch_id',
        'token',
        'cart_hash',
        'requested_by',
        'approved_by',
        'status',
        'discount_type',
        'discount_value',
        'discount_percentage_effective',
        'computed_discount_amount',
        'cart_subtotal_before_manual',
        'reason',
        'approved_at',
        'expires_at',
        'consumed_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'discount_percentage_effective' => 'decimal:4',
            'computed_discount_amount' => 'decimal:2',
            'cart_subtotal_before_manual' => 'decimal:2',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }
}
