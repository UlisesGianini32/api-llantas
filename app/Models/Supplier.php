<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rfc',
        'contact_name',
        'email',
        'phone',
        'address',
        'lead_time_days',
        'credit_days',
        'credit_limit',
        'payment_method_preferred',
        'website',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'credit_days' => 'integer',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function brands(): HasMany
    {
        return $this->hasMany(SupplierBrand::class)->orderBy('brand');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_name', 'name');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $s = trim((string) $search);
        if ($s === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($s) {
            $q->where('name', 'like', "%{$s}%")
                ->orWhere('rfc', 'like', "%{$s}%")
                ->orWhere('contact_name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhereHas('brands', function (Builder $bq) use ($s) {
                    $bq->where('brand', 'like', "%{$s}%");
                });
        });
    }

    /**
     * @return array<int, string>
     */
    public function getBrandNamesAttribute(): array
    {
        if ($this->relationLoaded('brands')) {
            return $this->brands->pluck('brand')->all();
        }

        return $this->brands()->pluck('brand')->all();
    }

    /**
     * Sincroniza las marcas asociadas al proveedor
     *
     * @param  array<int, mixed>  $brandsList  Lista de nombres de marcas (string) o arrays con detalle
     */
    public function syncBrands(array $brandsList): void
    {
        $existing = $this->brands()->get()->keyBy(fn ($b) => mb_strtoupper(trim($b->brand)));
        $seen = [];

        foreach ($brandsList as $item) {
            $brandName = is_array($item) ? trim((string) ($item['brand'] ?? '')) : trim((string) $item);
            if ($brandName === '') {
                continue;
            }

            $key = mb_strtoupper($brandName);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isPrimary = is_array($item) ? (bool) ($item['is_primary'] ?? true) : true;
            $leadTimeOverride = is_array($item) && isset($item['lead_time_override']) && $item['lead_time_override'] !== ''
                ? (int) $item['lead_time_override']
                : null;
            $notes = is_array($item) ? ($item['notes'] ?? null) : null;

            if ($existing->has($key)) {
                $record = $existing->get($key);
                $record->update([
                    'is_primary' => $isPrimary,
                    'lead_time_override' => $leadTimeOverride,
                    'notes' => $notes,
                ]);
            } else {
                $this->brands()->create([
                    'brand' => $key,
                    'is_primary' => $isPrimary,
                    'lead_time_override' => $leadTimeOverride,
                    'notes' => $notes,
                ]);
            }
        }

        // Eliminar las marcas que ya no estén en la lista
        foreach ($existing as $key => $record) {
            if (! isset($seen[$key])) {
                $record->delete();
            }
        }
    }
}
