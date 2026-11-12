<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomotiveBranchCommercialPolicy extends AutopartesModel
{
    protected $table = 'automotive_branch_commercial_policies';

    protected $fillable = [
        'branch_id',
        'pos_sales_enabled',
        'fiscal_policy_validated',
        'fiscal_regime_notes',
        'tax_enabled',
        'tax_rate',
        'manual_discounts_enabled',
        'cashier_max_discount_percent',
        'cashier_max_discount_amount',
        'default_thermal_width',
        'reason',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'pos_sales_enabled' => 'boolean',
            'fiscal_policy_validated' => 'boolean',
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:4',
            'manual_discounts_enabled' => 'boolean',
            'cashier_max_discount_percent' => 'decimal:2',
            'cashier_max_discount_amount' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function isReadyForRealSales(): bool
    {
        return (bool) $this->pos_sales_enabled && (bool) $this->fiscal_policy_validated;
    }
}
