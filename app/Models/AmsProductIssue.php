<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AmsProductIssue extends Model
{
    protected $table = 'ams_product_issues';

    protected $fillable = [
        'item_id',
        'sku',
        'title',
        'current_image_url',
        'order_id',
        'shipping_id',
        'issue_type',
        'notes',
        'status',
        'reported_by_user_id',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_notes',
        'applied_changes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'applied_changes' => 'array',
    ];

    public const TYPE_SIN_IMAGEN = 'sin_imagen';
    public const TYPE_IMAGEN_INCORRECTA = 'imagen_incorrecta';
    public const TYPE_SKU_INCORRECTO = 'sku_incorrecto';
    public const TYPE_TITULO_INCORRECTO = 'titulo_incorrecto';
    public const TYPE_OTRO = 'otro';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    public static function issueTypeLabels(): array
    {
        return [
            self::TYPE_SIN_IMAGEN => 'Sin imagen',
            self::TYPE_IMAGEN_INCORRECTA => 'Imagen incorrecta',
            self::TYPE_SKU_INCORRECTO => 'SKU incorrecto / N/A',
            self::TYPE_TITULO_INCORRECTO => 'Título incorrecto',
            self::TYPE_OTRO => 'Otro',
        ];
    }

    public function getIssueTypeLabelAttribute(): string
    {
        return self::issueTypeLabels()[$this->issue_type] ?? ucfirst($this->issue_type);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'ml', 'item_id');
    }

    public function publication(): HasOne
    {
        return $this->hasOne(MeliPublication::class, 'mlm', 'item_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }
}
