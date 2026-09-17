<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MeliPublication extends Model
{
    protected $table = 'meli_publications';

    protected $fillable = [
        'user_id',
        'meli_account_id',
        'sku',
        'mlm',
        'source_mlm',
        'status',
        'sub_status',
        'permalink',
        'last_sync_at',
        'raw',
    ];

    protected $casts = [
        'sub_status' => 'array',
        'raw' => 'array',
        'pictures' => 'array',
        'last_sync_at' => 'datetime',
        'is_current' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function meliAccount()
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function llanta()
    {
        return $this->belongsTo(Llanta::class, 'sku', 'sku');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeProblematic(Builder $query): Builder
    {
        return $query->whereIn('status', ['closed', 'inactive', 'blocked', 'under_review', 'suspended', 'paused']);
    }

    public function scopeLatestBySku(Builder $query, string $sku): Builder
    {
        return $query->where('sku', $sku)->orderByDesc('id');
    }

    public function getIsBlockedAttribute(): bool
    {
        return in_array($this->status, ['closed', 'inactive', 'blocked', 'under_review', 'suspended'], true);
    }

    public function getBlockReasonAttribute(): ?string
    {
        if (!is_array($this->raw)) {
            return null;
        }

        $mods = $this->raw['moderations'] ?? null;
        if (!is_array($mods)) {
            return null;
        }

        return $mods['message'] ?? $mods['reason'] ?? null;
    }

    public function getSubStatusTextAttribute(): ?string
    {
        $sub = $this->sub_status;
        if (is_string($sub) && $sub !== '') {
            return $sub;
        }
        if (! is_array($sub) || $sub === []) {
            return null;
        }

        return implode(', ', array_map('strval', $sub));
    }

    public function getItemDataAttribute(): array
    {
        return self::itemArrayFromRaw($this->raw);
    }

    /**
     * Normaliza el JSON guardado en meli_publications.raw (plano o { item, moderations }).
     *
     * @return array<string, mixed>
     */
    public static function itemArrayFromRaw(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        if (isset($raw['item']) && is_array($raw['item'])) {
            return $raw['item'];
        }

        return $raw;
    }

    /**
     * Precio de venta en ML desde el snapshot guardado (items/{id}).
     */
    public static function listPriceFromRaw(mixed $raw): ?float
    {
        $item = self::itemArrayFromRaw($raw);
        if ($item === []) {
            return null;
        }

        foreach (['price', 'base_price'] as $key) {
            if (! isset($item[$key]) || ! is_numeric($item[$key])) {
                continue;
            }
            $p = round((float) $item[$key], 2);
            if ($p > 0) {
                return $p;
            }
        }

        $variations = $item['variations'] ?? null;
        if (is_array($variations)) {
            foreach ($variations as $var) {
                if (! is_array($var) || ! isset($var['price']) || ! is_numeric($var['price'])) {
                    continue;
                }
                $p = round((float) $var['price'], 2);
                if ($p > 0) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Etiqueta en español para la UI (SYSCOM → ML y similares).
     * Valores: ACTIVO, PAUSADA, BLOQUEADA, EN REVISION.
     */
    public static function etiquetaEstadoPublicacion(?string $mlStatus): ?string
    {
        $s = strtolower(trim((string) $mlStatus));

        return match ($s) {
            'active' => 'ACTIVO',
            'paused' => 'PAUSADA',
            'inactive' => 'INACTIVA',
            'closed' => 'CERRADA',
            'under_review' => 'EN REVISION',
            '' => null,
            default => 'BLOQUEADA',
        };
    }

    /**
     * ML solo permite PUT de precio/stock en publicaciones activas o pausadas (esta última al reactivar).
     */
    public static function permiteActualizarPrecioStock(?string $mlStatus): bool
    {
        $s = strtolower(trim((string) $mlStatus));

        return in_array($s, ['active', 'paused'], true);
    }

    /**
     * ¿Se permite crear una nueva publicación sustituyendo la referencia en cola?
     * No se permite si el ítem sigue activo o pausado (sigue siendo una publicación válida en ML).
     */
    public static function permiteRepublicarSegunEstadoMl(?string $mlStatus): bool
    {
        $s = strtolower(trim((string) $mlStatus));

        if ($s === '') {
            return true;
        }

        return ! in_array($s, ['active', 'paused'], true);
    }
}