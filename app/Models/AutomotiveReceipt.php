<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

class AutomotiveReceipt extends AutopartesModel
{
    protected $table = 'automotive_receipts';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'folio',
        'branch_id',
        'automotive_location_id',
        'status',
        'reference',
        'notes',
        'idempotency_key',
        'confirmation_hash',
        'created_by',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $receipt): void {
            $location = AutomotiveLocation::find($receipt->automotive_location_id);
            if (! $location || (int) $location->branch_id !== (int) $receipt->branch_id) {
                throw ValidationException::withMessages([
                    'automotive_location_id' => 'La ubicación de recepción debe pertenecer a la sucursal seleccionada.',
                ]);
            }
            if ($receipt->exists && $receipt->getOriginal('status') === self::STATUS_CONFIRMED) {
                throw new LogicException('Una recepción confirmada es inmutable.');
            }
        });

        static::deleting(function (self $receipt): void {
            if ($receipt->status === self::STATUS_CONFIRMED) {
                throw new LogicException('No se puede eliminar una recepción confirmada.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'automotive_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AutomotiveReceiptItem::class, 'automotive_receipt_id');
    }
}
