<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'business_name',
        'phone',
        'email',
        'address',
        'credit_limit',
        'credit_days_default',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'credit_days_default' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'customer_id');
    }

    public function creditSales(): HasMany
    {
        return $this->sales()->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL]);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class, 'customer_id');
    }

    /**
     * Clientes activos con crédito vigente (con saldo pendiente al corriente)
     */
    public function scopeWithActiveCredit(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->whereHas('sales', function ($q) use ($today) {
            $q->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
                ->where('balance_due', '>', 0)
                ->where(function ($sub) use ($today) {
                    $sub->whereNull('credit_due_date')
                        ->orWhere('credit_due_date', '>=', $today);
                });
        });
    }

    /**
     * Clientes con crédito vencido (al menos una venta vencida con saldo pendiente)
     */
    public function scopeWithOverdueCredit(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->whereHas('sales', function ($q) use ($today) {
            $q->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
                ->where('balance_due', '>', 0)
                ->where('credit_due_date', '<', $today);
        });
    }

    /**
     * Clientes al día o sin deuda (sin saldos pendientes)
     */
    public function scopeWithoutDebt(Builder $query): Builder
    {
        return $query->whereDoesntHave('sales', function ($q) {
            $q->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
                ->where('balance_due', '>', 0);
        });
    }

    public function getTotalDebtAttribute(): float
    {
        return (float) $this->sales()
            ->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
            ->where('status', '!=', PosSale::STATUS_CANCELLED)
            ->sum('balance_due');
    }

    public function getHasOverdueCreditAttribute(): bool
    {
        return $this->sales()
            ->whereIn('payment_status', [PosSale::PAYMENT_STATUS_CREDIT_PENDING, PosSale::PAYMENT_STATUS_CREDIT_PARTIAL])
            ->where('status', '!=', PosSale::STATUS_CANCELLED)
            ->where('balance_due', '>', 0)
            ->where('credit_due_date', '<', Carbon::today()->toDateString())
            ->exists();
    }

    public function getAvailableCreditAttribute(): float
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) {
            return 0.0;
        }

        return max(0.0, $limit - $this->total_debt);
    }
}
