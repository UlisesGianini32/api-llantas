<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class InventoryDispatchDestination extends Model
{
    public const TYPE_SALON = 'SALON';

    public const TYPE_INTERNAL_AREA = 'INTERNAL_AREA';

    public const TYPE_BRANCH = 'BRANCH';

    public const TYPE_OTHER = 'OTHER';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_SALON,
        self::TYPE_INTERNAL_AREA,
        self::TYPE_BRANCH,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'code',
        'name',
        'destination_type',
        'contact_name',
        'phone',
        'address',
        'customer_id',
        'default_operation_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'customer_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function setCodeAttribute(?string $value): void
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            throw new InvalidArgumentException('El código del destino no puede estar vacío.');
        }

        $this->attributes['code'] = $normalized;
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(InventoryDispatch::class, 'destination_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
