<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveCashRegister;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePosCashMovement;
use App\Models\AutomotivePosPayment;
use App\Models\AutomotivePosReturn;
use App\Models\AutomotivePosSale;
use App\Models\AutomotivePosShift;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomotiveShiftService
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
    ) {}

    /** @return Collection<int, AutomotiveCashRegister> */
    public function listRegisters(AutomotiveBranch $branch): Collection
    {
        $branch->ensureDefaultCashRegister();

        return AutomotiveCashRegister::query()
            ->with('location:id,code,name,branch_id')
            ->where('branch_id', $branch->getKey())
            ->orderBy('code')
            ->get();
    }

    public function createRegister(AutomotiveBranch $branch, array $data, User $actor): AutomotiveCashRegister
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_SHIFT_MANAGE);

        $code = preg_replace('/[^A-Z0-9_-]+/', '', mb_strtoupper(trim((string) ($data['code'] ?? '')))) ?? '';
        $name = trim((string) ($data['name'] ?? ''));

        if ($code === '' || mb_strlen($code) < 3) {
            throw ValidationException::withMessages([
                'code' => 'El código de la caja es obligatorio (mínimo 3 caracteres).',
            ]);
        }

        if ($name === '' || mb_strlen($name) < 3) {
            throw ValidationException::withMessages([
                'name' => 'El nombre de la caja es obligatorio.',
            ]);
        }

        $locationId = ! empty($data['automotive_location_id'])
            ? (int) $data['automotive_location_id']
            : $branch->ensureDefaultReceptionLocation()->getKey();

        $location = AutomotiveLocation::query()
            ->whereKey($locationId)
            ->where('branch_id', $branch->getKey())
            ->where('is_active', true)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'automotive_location_id' => 'La ubicación física debe estar activa y pertenecer a la sucursal '.$branch->code.'.',
            ]);
        }

        if (AutomotiveCashRegister::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => "El código de caja [{$code}] ya existe.",
            ]);
        }

        return AutomotiveCashRegister::create([
            'branch_id' => $branch->getKey(),
            'automotive_location_id' => $location->getKey(),
            'code' => $code,
            'name' => $name,
            'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                ? trim((string) $data['description'])
                : null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
        ]);
    }

    public function getActiveShiftForBranch(AutomotiveBranch $branch, ?User $cashier = null, ?int $cashRegisterId = null): ?AutomotivePosShift
    {
        $query = AutomotivePosShift::query()
            ->with(['cashRegister:id,code,name,automotive_location_id', 'location:id,code,name'])
            ->where('branch_id', $branch->getKey())
            ->where('status', AutomotivePosShift::STATUS_OPEN);

        if ($cashRegisterId !== null) {
            $query->where('cash_register_id', $cashRegisterId);
        } elseif ($cashier !== null) {
            $query->where('cashier_id', $cashier->getKey());
        }

        $shift = $query->latest('opened_at')->first();
        if ($shift) {
            $shift->setAttribute('cashier_name', AutopartesUserDirectory::nameFor((int) $shift->cashier_id));
        }

        return $shift;
    }

    public function openShift(AutomotiveBranch $branch, array $data, User $actor): AutomotivePosShift
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_SHIFT_MANAGE);

        $openingCash = isset($data['opening_cash']) && is_numeric($data['opening_cash'])
            ? round((float) $data['opening_cash'], 2)
            : -1.0;

        if ($openingCash < 0) {
            throw ValidationException::withMessages([
                'opening_cash' => 'El fondo inicial de caja debe ser un importe mayor o igual a $0.00 MXN.',
            ]);
        }

        return AutopartesModel::transaction(function () use ($branch, $data, $actor, $openingCash): AutomotivePosShift {
            $registerId = ! empty($data['cash_register_id'])
                ? (int) $data['cash_register_id']
                : $branch->ensureDefaultCashRegister()->getKey();

            $register = AutomotiveCashRegister::query()
                ->whereKey($registerId)
                ->where('branch_id', $branch->getKey())
                ->lockForUpdate()
                ->first();

            if (! $register || ! $register->is_active) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'La caja seleccionada no existe, no está activa o no pertenece a '.$branch->code.'.',
                ]);
            }

            $existingOnRegister = AutomotivePosShift::query()
                ->where('cash_register_id', $register->getKey())
                ->where('status', AutomotivePosShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existingOnRegister) {
                throw ValidationException::withMessages([
                    'cash_register_id' => "La caja [{$register->code}] ya tiene un turno abierto ({$existingOnRegister->folio}).",
                ]);
            }

            $existingForCashier = AutomotivePosShift::query()
                ->where('branch_id', $branch->getKey())
                ->where('cashier_id', $actor->getKey())
                ->where('status', AutomotivePosShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($existingForCashier) {
                throw ValidationException::withMessages([
                    'shift' => "El usuario ya cuenta con un turno abierto ({$existingForCashier->folio}) en {$branch->code}.",
                ]);
            }

            do {
                $folio = 'TUR-'.strtoupper($branch->code).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
            } while (AutomotivePosShift::query()->where('folio', $folio)->exists());

            $shift = AutomotivePosShift::create([
                'folio' => $folio,
                'branch_id' => $branch->getKey(),
                'cash_register_id' => $register->getKey(),
                'automotive_location_id' => $register->automotive_location_id,
                'cashier_id' => $actor->getKey(),
                'status' => AutomotivePosShift::STATUS_OPEN,
                'opened_at' => now(),
                'opening_cash' => number_format($openingCash, 2, '.', ''),
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            ])->load(['cashRegister:id,code,name,automotive_location_id', 'location:id,code,name']);

            $shift->setAttribute('cashier_name', $actor->name);

            return $shift;
        });
    }

    public function addCashMovement(
        AutomotiveBranch $branch,
        AutomotivePosShift $shift,
        array $data,
        User $actor,
    ): AutomotivePosCashMovement {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_SHIFT_MANAGE);

        if ((int) $shift->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'shift' => 'El turno de caja no pertenece a esta sucursal.',
            ]);
        }

        $type = strtoupper(trim((string) ($data['type'] ?? '')));
        if (! in_array($type, [AutomotivePosCashMovement::TYPE_IN, AutomotivePosCashMovement::TYPE_OUT], true)) {
            throw ValidationException::withMessages([
                'type' => 'El tipo de movimiento de caja debe ser IN o OUT.',
            ]);
        }

        $amount = isset($data['amount']) && is_numeric($data['amount'])
            ? round((float) $data['amount'], 2)
            : 0.0;

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El importe del movimiento de caja debe ser mayor a $0.00 MXN.',
            ]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo del movimiento de efectivo es obligatorio (mínimo 5 caracteres).',
            ]);
        }

        return AutopartesModel::transaction(function () use ($branch, $shift, $type, $amount, $reason, $data, $actor): AutomotivePosCashMovement {
            $lockedShift = AutomotivePosShift::query()->lockForUpdate()->findOrFail($shift->getKey());

            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'No se pueden registrar movimientos de efectivo en un turno cerrado.',
                ]);
            }

            if ($type === AutomotivePosCashMovement::TYPE_OUT) {
                $summary = $this->calculateShiftSummary($lockedShift);
                $expectedCashCents = AutomotiveDiscountService::toCents($summary['expected_cash']);
                $outCents = AutomotiveDiscountService::toCents($amount);
                if ($outCents > $expectedCashCents) {
                    throw ValidationException::withMessages([
                        'amount' => 'El retiro de efectivo supera el efectivo disponible en caja ($'.$summary['expected_cash'].' MXN).',
                    ]);
                }
            }

            $movement = AutomotivePosCashMovement::create([
                'automotive_pos_shift_id' => $lockedShift->getKey(),
                'branch_id' => $branch->getKey(),
                'user_id' => $actor->getKey(),
                'type' => $type,
                'amount' => number_format($amount, 2, '.', ''),
                'reason' => $reason,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            ]);

            if ($type === AutomotivePosCashMovement::TYPE_IN) {
                $lockedShift->update([
                    'total_cash_in' => number_format(round((float) $lockedShift->total_cash_in + $amount, 2), 2, '.', ''),
                ]);
            } else {
                $lockedShift->update([
                    'total_cash_out' => number_format(round((float) $lockedShift->total_cash_out + $amount, 2), 2, '.', ''),
                ]);
            }

            $movement->setAttribute('user_name', $actor->name);

            return $movement;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateShiftSummary(AutomotivePosShift $shift): array
    {
        $activeSalesIds = AutomotivePosSale::query()
            ->where('automotive_pos_shift_id', $shift->getKey())
            ->where('status', '!=', AutomotivePosSale::STATUS_CANCELLED)
            ->pluck('id')
            ->all();

        $salesCount = count($activeSalesIds);
        $salesTotalCents = 0;
        $salesCashCents = 0;
        $salesCardCents = 0;
        $salesTransferCents = 0;

        if (! empty($activeSalesIds)) {
            $salesTotal = (float) AutomotivePosSale::query()
                ->whereIn('id', $activeSalesIds)
                ->sum('total');
            $salesTotalCents = AutomotiveDiscountService::toCents($salesTotal);

            $payments = AutomotivePosPayment::query()
                ->whereIn('automotive_pos_sale_id', $activeSalesIds)
                ->get();

            foreach ($payments as $payment) {
                $amtCents = AutomotiveDiscountService::toCents((float) $payment->amount);
                if ($payment->method === AutomotivePosSale::PAYMENT_CASH) {
                    $salesCashCents += $amtCents;
                } elseif ($payment->method === AutomotivePosSale::PAYMENT_CARD) {
                    $salesCardCents += $amtCents;
                } elseif ($payment->method === AutomotivePosSale::PAYMENT_TRANSFER) {
                    $salesTransferCents += $amtCents;
                }
            }
        }

        $cashReturns = AutomotivePosReturn::query()
            ->with('sale:id,automotive_pos_shift_id,status')
            ->where('automotive_pos_shift_id', $shift->getKey())
            ->where('refund_method', AutomotivePosReturn::REFUND_CASH)
            ->get();

        $refundsCashCents = 0;
        foreach ($cashReturns as $ret) {
            $saleInThisShift = (int) ($ret->sale?->automotive_pos_shift_id ?? 0) === (int) $shift->getKey();
            $saleWasCancelled = ($ret->sale?->status ?? '') === AutomotivePosSale::STATUS_CANCELLED;
            if ($saleInThisShift && $saleWasCancelled) {
                continue;
            }
            $refundsCashCents += AutomotiveDiscountService::toCents((float) $ret->refund_amount);
        }

        $cashInCents = AutomotiveDiscountService::toCents((float) AutomotivePosCashMovement::query()
            ->where('automotive_pos_shift_id', $shift->getKey())
            ->where('type', AutomotivePosCashMovement::TYPE_IN)
            ->sum('amount'));

        $cashOutCents = AutomotiveDiscountService::toCents((float) AutomotivePosCashMovement::query()
            ->where('automotive_pos_shift_id', $shift->getKey())
            ->where('type', AutomotivePosCashMovement::TYPE_OUT)
            ->sum('amount'));

        $openingCashCents = AutomotiveDiscountService::toCents((float) $shift->opening_cash);
        $expectedCashCents = $openingCashCents + $salesCashCents + $cashInCents - $cashOutCents - $refundsCashCents;

        $movements = AutomotivePosCashMovement::query()
            ->where('automotive_pos_shift_id', $shift->getKey())
            ->latest('id')
            ->get();
        $userNames = AutopartesUserDirectory::mapByIds($movements->pluck('user_id')->all());
        $movements->each(fn (AutomotivePosCashMovement $m) => $m->setAttribute('user_name', $userNames->get((int) $m->user_id)?->name));

        return [
            'shift_id' => $shift->getKey(),
            'folio' => $shift->folio,
            'status' => $shift->status,
            'opening_cash' => AutomotiveDiscountService::fromCents($openingCashCents),
            'sales_cash' => AutomotiveDiscountService::fromCents($salesCashCents),
            'sales_card' => AutomotiveDiscountService::fromCents($salesCardCents),
            'sales_transfer' => AutomotiveDiscountService::fromCents($salesTransferCents),
            'sales_total' => AutomotiveDiscountService::fromCents($salesTotalCents),
            'sales_count' => $salesCount,
            'cash_in' => AutomotiveDiscountService::fromCents($cashInCents),
            'cash_out' => AutomotiveDiscountService::fromCents($cashOutCents),
            'refunds_cash' => AutomotiveDiscountService::fromCents($refundsCashCents),
            'expected_cash' => AutomotiveDiscountService::fromCents($expectedCashCents),
            'movements' => $movements,
        ];
    }

    public function closeShift(
        AutomotiveBranch $branch,
        AutomotivePosShift $shift,
        array $data,
        User $actor,
    ): AutomotivePosShift {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_SHIFT_MANAGE);

        if ((int) $shift->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'shift' => 'El turno de caja no pertenece a esta sucursal.',
            ]);
        }

        $countedCash = isset($data['closing_cash_counted']) && is_numeric($data['closing_cash_counted'])
            ? round((float) $data['closing_cash_counted'], 2)
            : -1.0;

        if ($countedCash < 0) {
            throw ValidationException::withMessages([
                'closing_cash_counted' => 'El efectivo físico contado debe ser mayor o igual a $0.00 MXN.',
            ]);
        }

        return AutopartesModel::transaction(function () use ($shift, $countedCash, $data, $actor): AutomotivePosShift {
            $lockedShift = AutomotivePosShift::query()->lockForUpdate()->findOrFail($shift->getKey());

            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'El turno de caja ya se encuentra cerrado.',
                ]);
            }

            $summary = $this->calculateShiftSummary($lockedShift);
            $expectedCents = AutomotiveDiscountService::toCents($summary['expected_cash']);
            $countedCents = AutomotiveDiscountService::toCents($countedCash);
            $differenceCents = $countedCents - $expectedCents;

            $combinedNotes = $lockedShift->notes;
            if (! empty($data['notes']) && trim((string) $data['notes']) !== '') {
                $combinedNotes = $combinedNotes
                    ? $combinedNotes."\n".trim((string) $data['notes'])
                    : trim((string) $data['notes']);
            }

            $lockedShift->update([
                'status' => AutomotivePosShift::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->getKey(),
                'closing_cash_expected' => AutomotiveDiscountService::fromCents($expectedCents),
                'closing_cash_counted' => AutomotiveDiscountService::fromCents($countedCents),
                'difference' => AutomotiveDiscountService::fromCents($differenceCents),
                'total_sales_cash' => $summary['sales_cash'],
                'total_sales_card' => $summary['sales_card'],
                'total_sales_transfer' => $summary['sales_transfer'],
                'total_sales_amount' => $summary['sales_total'],
                'total_sales_count' => $summary['sales_count'],
                'total_cash_in' => $summary['cash_in'],
                'total_cash_out' => $summary['cash_out'],
                'total_refunds_cash' => $summary['refunds_cash'],
                'notes' => $combinedNotes,
            ]);

            $fresh = $lockedShift->fresh(['cashRegister', 'location', 'movements']);
            $users = AutopartesUserDirectory::mapByIds([
                $fresh->cashier_id,
                $fresh->closed_by,
                ...$fresh->movements->pluck('user_id')->all(),
            ]);
            $fresh->setAttribute('cashier_name', $users->get((int) $fresh->cashier_id)?->name);
            $fresh->setAttribute('closed_by_name', $users->get((int) $fresh->closed_by)?->name);

            return $fresh;
        });
    }

    /** @return Collection<int, AutomotivePosShift> */
    public function listShiftHistory(AutomotiveBranch $branch, User $actor, int $limit = 30): Collection
    {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SHIFT_MANAGE)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::REPORTS_VIEW)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_SHIFT_MANAGE);
        }

        $shifts = AutomotivePosShift::query()
            ->with(['cashRegister:id,code,name', 'location:id,code,name'])
            ->where('branch_id', $branch->getKey())
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $userIds = [];
        foreach ($shifts as $s) {
            $userIds[] = $s->cashier_id;
            $userIds[] = $s->closed_by;
        }
        $users = AutopartesUserDirectory::mapByIds($userIds);
        foreach ($shifts as $s) {
            $s->setAttribute('cashier_name', $users->get((int) $s->cashier_id)?->name);
            $s->setAttribute('closed_by_name', $users->get((int) $s->closed_by)?->name);
        }

        return $shifts;
    }
}
