<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class AutomotiveBranch extends AutopartesModel
{
    protected $table = 'automotive_branches';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AutomotiveBranchMembership::class, 'branch_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(AutomotiveLocation::class, 'branch_id');
    }

    public function partSettings(): HasMany
    {
        return $this->hasMany(AutomotivePartBranchSetting::class, 'branch_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AutomotiveInventoryBalance::class, 'branch_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AutomotiveInventoryMovement::class, 'branch_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AutomotiveReceipt::class, 'branch_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(AutomotiveStockAdjustment::class, 'branch_id');
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(AutomotiveCashRegister::class, 'branch_id');
    }

    public function sellers(): HasMany
    {
        return $this->hasMany(AutomotiveSeller::class, 'branch_id');
    }

    public function posShifts(): HasMany
    {
        return $this->hasMany(AutomotivePosShift::class, 'branch_id');
    }

    public function posSales(): HasMany
    {
        return $this->hasMany(AutomotivePosSale::class, 'branch_id');
    }

    public function commercialPolicy(): HasOne
    {
        return $this->hasOne(AutomotiveBranchCommercialPolicy::class, 'branch_id');
    }

    public function ensureDefaultReceptionLocation(): AutomotiveLocation
    {
        $code = strtoupper($this->code).'-REC-01';
        $existing = AutomotiveLocation::query()->where('code', $code)->first();
        if ($existing) {
            if ((int) $existing->branch_id !== (int) $this->getKey()) {
                throw new InvalidArgumentException('El código de ubicación de recepción ya pertenece a otro almacén de Autopartes.');
            }

            return $existing;
        }

        $location = new AutomotiveLocation([
            'branch_id' => $this->getKey(),
            'code' => $code,
            'name' => strtoupper($this->name).' RECEPCIÓN AUTOPARTES',
            'description' => 'Ubicación física principal de recepción y despacho de Autopartes '.strtoupper($this->name),
            'is_active' => true,
        ]);
        $location->save();

        return $location;
    }

    public function ensureDefaultCommercialPolicy(): AutomotiveBranchCommercialPolicy
    {
        return AutomotiveBranchCommercialPolicy::query()->firstOrCreate(
            ['branch_id' => $this->getKey()],
            [
                'pos_sales_enabled' => (bool) config('autopartes.pos.sales_enabled_by_default', false),
                'fiscal_policy_validated' => (bool) config('autopartes.pos.fiscal_policy_validated_by_default', false),
                'tax_enabled' => (bool) config('autopartes.pos.tax_enabled', false),
                'tax_rate' => number_format((float) config('autopartes.pos.tax_rate', 0.00), 4, '.', ''),
                'manual_discounts_enabled' => (bool) config('autopartes.pos.manual_discounts_enabled_by_default', false),
                'cashier_max_discount_percent' => number_format((float) config('autopartes.pos.cashier_max_manual_discount_percent', 0.00), 2, '.', ''),
                'cashier_max_discount_amount' => number_format((float) config('autopartes.pos.cashier_max_manual_discount_amount', 0.00), 2, '.', ''),
                'default_thermal_width' => (string) config('autopartes.pos.default_thermal_width', '80mm'),
            ]
        );
    }

    public function ensureDefaultCashRegister(): AutomotiveCashRegister
    {
        $location = $this->ensureDefaultReceptionLocation();
        $code = strtoupper($this->code).'-CAJA-01';
        $existing = AutomotiveCashRegister::query()->where('code', $code)->first();
        if ($existing) {
            if ((int) $existing->branch_id !== (int) $this->getKey()) {
                throw new InvalidArgumentException('El código de caja ya pertenece a otra sucursal de Autopartes.');
            }

            return $existing;
        }

        return AutomotiveCashRegister::create([
            'branch_id' => $this->getKey(),
            'automotive_location_id' => $location->getKey(),
            'code' => $code,
            'name' => 'CAJA MOSTRADOR '.strtoupper($this->name).' 01',
            'description' => 'Caja principal de punto de venta para Autopartes '.strtoupper($this->name),
            'is_active' => true,
        ]);
    }
}
