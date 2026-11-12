<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomotivePart extends AutopartesModel
{
    protected $table = 'automotive_parts';

    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_VERIFIED = 'verified';

    public const STATUSES = [
        self::STATUS_PROVISIONAL,
        self::STATUS_AMBIGUOUS,
        self::STATUS_VERIFIED,
    ];

    public static function normalizeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(trim($value)));

        return $normalized === '' ? null : $normalized;
    }

    protected $fillable = [
        'code',
        'source_row_id',
        'identity_status',
        'item_number',
        'manufacturer_part_number',
        'vendor_name',
        'category',
        'subcategory',
        'description',
        'applicable_models',
        'verified_barcode',
        'allow_receipt',
        'verified_by',
        'verified_at',
        'verification_evidence',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allow_receipt' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $part): void {
            if ($part->exists && $part->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'El código interno único de la autoparte no se puede reasignar.']);
            }
            if (blank($part->code)) {
                $part->code = 'AP-'.Str::uuid();
            }
            if (! $part->exists && blank($part->identity_status)) {
                $part->identity_status = self::STATUS_PROVISIONAL;
            }
            if (! in_array((string) $part->identity_status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['identity_status' => 'Estado de identidad de autoparte no válido.']);
            }
        });
    }

    public function isReceivable(): bool
    {
        return (bool) ($this->allow_receipt ?? true) && $this->identity_status !== self::STATUS_AMBIGUOUS;
    }

    public function sourceRow(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCatalogRow::class, 'source_row_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(AutomotivePartIdentifier::class, 'automotive_part_id');
    }

    public function branchSettings(): HasMany
    {
        return $this->hasMany(AutomotivePartBranchSetting::class, 'automotive_part_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AutomotiveInventoryBalance::class, 'automotive_part_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AutomotiveInventoryMovement::class, 'automotive_part_id');
    }
}
