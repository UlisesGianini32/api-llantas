<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class AutomotivePartBranchSetting extends AutopartesModel
{
    protected $table = 'automotive_part_branch_settings';

    public const PRICE_STATUS_UNPRICED = 'unpriced';

    public const PRICE_STATUS_PENDING_APPROVAL = 'pending_approval';

    public const PRICE_STATUS_APPROVED = 'approved';

    public const PRICE_STATUSES = [
        self::PRICE_STATUS_UNPRICED,
        self::PRICE_STATUS_PENDING_APPROVAL,
        self::PRICE_STATUS_APPROVED,
    ];

    protected $fillable = [
        'automotive_part_id',
        'branch_id',
        'primary_location_id',
        'secondary_location_id',
        'is_sellable',
        'sale_price_mxn',
        'price_status',
        'price_updated_by',
        'price_updated_at',
        'price_approved_by',
        'price_approved_at',
        'price_reason',
        'sale_blocked',
        'sale_block_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'sale_price_mxn' => 'decimal:2',
            'price_updated_at' => 'datetime',
            'price_approved_at' => 'datetime',
            'sale_blocked' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            foreach (['primary_location_id', 'secondary_location_id'] as $field) {
                if ($setting->$field !== null && ! AutomotiveLocation::query()
                    ->whereKey($setting->$field)->where('branch_id', $setting->branch_id)->exists()) {
                    throw ValidationException::withMessages([$field => 'La ubicación debe pertenecer a la sucursal de Autopartes.']);
                }
            }

            if ($setting->sale_price_mxn === null || (float) $setting->sale_price_mxn <= 0) {
                $setting->is_sellable = false;
                $setting->price_status = self::PRICE_STATUS_UNPRICED;
            } elseif (($setting->attributes['price_status'] ?? null) === null) {
                $setting->price_status = self::PRICE_STATUS_APPROVED;
            }
        });
    }

    public function getPriceStatusAttribute(?string $value): string
    {
        return $value ?: self::PRICE_STATUS_UNPRICED;
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function primaryLocation(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'primary_location_id');
    }

    public function secondaryLocation(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'secondary_location_id');
    }

    public function hasApprovedMxnPrice(): bool
    {
        return $this->sale_price_mxn !== null
            && (float) $this->sale_price_mxn > 0
            && ($this->price_status ?? self::PRICE_STATUS_UNPRICED) === self::PRICE_STATUS_APPROVED;
    }
}
