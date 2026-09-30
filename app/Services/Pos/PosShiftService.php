<?php

namespace App\Services\Pos;

use App\Models\PosCashMovement;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PosShiftService
{
    public function getActiveShift(User $user, ?int $locationId = null): ?PosShift
    {
        $query = PosShift::query()
            ->with(['location:id,code,name', 'cashier:id,name'])
            ->where('status', PosShift::STATUS_OPEN)
            ->where('cashier_id', $user->id);

        if ($locationId !== null) {
            $query->where('inventory_location_id', $locationId);
        }

        return $query->latest('opened_at')->first();
    }

    public function openShift(User $cashier, int $locationId, float $openingCash, ?string $notes = null): PosShift
    {
        $existing = $this->getActiveShift($cashier, $locationId);
        if ($existing) {
            throw new InvalidArgumentException('El cajero ya tiene un turno de caja abierto en esta ubicación.');
        }

        if ($openingCash < 0) {
            throw new InvalidArgumentException('El fondo de apertura no puede ser negativo.');
        }

        return PosShift::create([
            'inventory_location_id' => $locationId,
            'cashier_id' => $cashier->id,
            'status' => PosShift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'notes' => $notes,
        ]);
    }

    public function addCashMovement(
        PosShift $shift,
        User $user,
        string $type,
        float $amount,
        string $reason,
        ?string $notes = null
    ): PosCashMovement {
        if (! $shift->isOpen()) {
            throw new InvalidArgumentException('No se pueden registrar movimientos en un turno cerrado.');
        }

        $type = strtoupper(trim($type));
        if (! in_array($type, [PosCashMovement::TYPE_IN, PosCashMovement::TYPE_OUT], true)) {
            throw new InvalidArgumentException("Tipo de movimiento inválido: {$type}. Use IN o OUT.");
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('El importe del movimiento debe ser mayor a cero.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('El motivo del movimiento es obligatorio.');
        }

        return DB::transaction(function () use ($shift, $user, $type, $amount, $reason, $notes) {
            $movement = PosCashMovement::create([
                'pos_shift_id' => $shift->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'notes' => $notes,
            ]);

            if ($type === PosCashMovement::TYPE_IN) {
                $shift->increment('total_cash_in', $amount);
            } else {
                $shift->increment('total_cash_out', $amount);
            }

            return $movement;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateShiftSummary(PosShift $shift): array
    {
        $sales = PosSale::query()
            ->where('pos_shift_id', $shift->id)
            ->where('status', PosSale::STATUS_COMPLETED)
            ->get();

        $salesCash = (float) $sales->where('payment_method', PosSale::PAYMENT_CASH)->sum('total');
        $salesCard = (float) $sales->where('payment_method', PosSale::PAYMENT_CARD)->sum('total');
        $salesTransfer = (float) $sales->where('payment_method', PosSale::PAYMENT_TRANSFER)->sum('total');
        $salesOther = (float) $sales->whereNotIn('payment_method', [
            PosSale::PAYMENT_CASH,
            PosSale::PAYMENT_CARD,
            PosSale::PAYMENT_TRANSFER,
        ])->sum('total');

        $salesTotal = (float) $sales->sum('total');
        $salesCount = $sales->count();

        $cashIn = (float) PosCashMovement::query()
            ->where('pos_shift_id', $shift->id)
            ->where('type', PosCashMovement::TYPE_IN)
            ->sum('amount');

        $cashOut = (float) PosCashMovement::query()
            ->where('pos_shift_id', $shift->id)
            ->where('type', PosCashMovement::TYPE_OUT)
            ->sum('amount');

        $openingCash = (float) $shift->opening_cash;
        $expectedCash = $openingCash + $salesCash + $cashIn - $cashOut;

        $movements = PosCashMovement::query()
            ->with('user:id,name')
            ->where('pos_shift_id', $shift->id)
            ->latest('id')
            ->get();

        return [
            'shift_id' => $shift->id,
            'opening_cash' => $openingCash,
            'sales_cash' => $salesCash,
            'sales_card' => $salesCard,
            'sales_transfer' => $salesTransfer,
            'sales_other' => $salesOther,
            'sales_total' => $salesTotal,
            'sales_count' => $salesCount,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $expectedCash,
            'movements' => $movements,
        ];
    }

    public function closeShift(
        PosShift $shift,
        User $closingUser,
        float $countedCash,
        ?string $notes = null
    ): PosShift {
        if (! $shift->isOpen()) {
            throw new InvalidArgumentException('El turno ya se encuentra cerrado.');
        }

        if ($countedCash < 0) {
            throw new InvalidArgumentException('El conteo físico de efectivo no puede ser negativo.');
        }

        return DB::transaction(function () use ($shift, $closingUser, $countedCash, $notes) {
            $summary = $this->calculateShiftSummary($shift);
            $expected = (float) $summary['expected_cash'];
            $difference = round($countedCash - $expected, 2);

            $combinedNotes = $shift->notes;
            if ($notes !== null && trim($notes) !== '') {
                $combinedNotes = $combinedNotes ? ($combinedNotes."\n".trim($notes)) : trim($notes);
            }

            $shift->update([
                'status' => PosShift::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $closingUser->id,
                'closing_cash_expected' => $expected,
                'closing_cash_counted' => $countedCash,
                'difference' => $difference,
                'total_sales_cash' => $summary['sales_cash'],
                'total_sales_card' => $summary['sales_card'],
                'total_sales_transfer' => $summary['sales_transfer'],
                'total_sales_other' => $summary['sales_other'],
                'total_sales_amount' => $summary['sales_total'],
                'total_sales_count' => $summary['sales_count'],
                'total_cash_in' => $summary['cash_in'],
                'total_cash_out' => $summary['cash_out'],
                'notes' => $combinedNotes,
            ]);

            return $shift->fresh(['location', 'cashier', 'closedBy', 'movements']);
        });
    }
}
