<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AutomotivePartIdentifier extends AutopartesModel
{
    protected $table = 'automotive_part_identifiers';

    public const TYPE_VERIFIED_BARCODE = 'verified_barcode';

    public const TYPE_AUTHORIZED_ALIAS = 'authorized_alias';

    public const TYPE_SUPPLIER_LABEL = 'supplier_label';

    public const TYPES = [
        self::TYPE_VERIFIED_BARCODE,
        self::TYPE_AUTHORIZED_ALIAS,
        self::TYPE_SUPPLIER_LABEL,
    ];

    protected $fillable = [
        'automotive_part_id',
        'branch_id',
        'identifier_type',
        'identifier_value',
        'identifier_normalized',
        'is_verified',
        'is_active',
        'evidence',
        'verified_by',
        'verified_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Los identificadores auditados no se pueden eliminar; utilice revocación si aplica.');
        });
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(AutomotivePart::class, 'automotive_part_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }
}
