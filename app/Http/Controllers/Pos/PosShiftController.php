<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosShift;
use App\Services\Pos\PosReceiptService;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PosShiftController extends Controller
{
    public function __construct(
        private readonly PosShiftService $shiftService,
        private readonly PosReceiptService $receiptService,
        private readonly PosSaleService $saleService
    ) {}

    public function current(Request $request): JsonResponse
    {
        $locationId = $request->integer('location_id') ?: null;
        if (! $locationId) {
            $locationId = $this->saleService->getDefaultLocation()->id;
        }

        $shift = $this->shiftService->getActiveShift($request->user(), $locationId);

        if (! $shift) {
            return response()->json([
                'active' => false,
                'shift' => null,
                'summary' => null,
            ]);
        }

        $summary = $this->shiftService->calculateShiftSummary($shift);

        return response()->json([
            'active' => true,
            'shift' => $shift,
            'summary' => $summary,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $locationId = $validated['inventory_location_id'] ?? $this->saleService->getDefaultLocation()->id;
        $openingCash = (float) $validated['opening_cash'];
        $notes = $validated['notes'] ?? null;

        try {
            $shift = $this->shiftService->openShift(
                $request->user(),
                $locationId,
                $openingCash,
                $notes
            );

            $summary = $this->shiftService->calculateShiftSummary($shift);

            return response()->json([
                'ok' => true,
                'message' => "Turno #{$shift->id} abierto con un fondo de $".number_format($openingCash, 2).'.',
                'shift' => $shift->load(['location', 'cashier']),
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function movement(Request $request, PosShift $posShift): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:IN,OUT,in,out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:2', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $movement = $this->shiftService->addCashMovement(
                $posShift,
                $request->user(),
                strtoupper($validated['type']),
                (float) $validated['amount'],
                $validated['reason'],
                $validated['notes'] ?? null
            );

            $summary = $this->shiftService->calculateShiftSummary($posShift);

            $typeLabel = $movement->isIncome() ? 'Ingreso' : 'Retiro';

            return response()->json([
                'ok' => true,
                'message' => "{$typeLabel} de $".number_format((float) $movement->amount, 2).' registrado correctamente.',
                'movement' => $movement,
                'summary' => $summary,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function summary(PosShift $posShift): JsonResponse
    {
        $summary = $this->shiftService->calculateShiftSummary($posShift);

        return response()->json([
            'ok' => true,
            'summary' => $summary,
            'shift' => $posShift->load(['location', 'cashier']),
        ]);
    }

    public function close(Request $request, PosShift $posShift): JsonResponse
    {
        $validated = $request->validate([
            'closing_cash_counted' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paper_type' => ['nullable', 'string', 'in:58mm,80mm'],
        ]);

        $counted = (float) $validated['closing_cash_counted'];
        $notes = $validated['notes'] ?? null;
        $paperType = $validated['paper_type'] ?? '80mm';

        try {
            $closedShift = $this->shiftService->closeShift(
                $posShift,
                $request->user(),
                $counted,
                $notes
            );

            $receipt = $this->receiptService->formatShiftCutReceipt($closedShift, $paperType);

            return response()->json([
                'ok' => true,
                'message' => "Turno #{$closedShift->id} cerrado correctamente.",
                'shift' => $closedShift,
                'receipt' => $receipt,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function receipt(Request $request, PosShift $posShift): JsonResponse
    {
        $paperType = $request->input('paper_type', '80mm');
        $receipt = $this->receiptService->formatShiftCutReceipt($posShift, $paperType);

        return response()->json([
            'ok' => true,
            'receipt' => $receipt,
        ]);
    }

    public function drawer(): JsonResponse
    {
        $command = $this->receiptService->getDrawerKickCommand();

        return response()->json([
            'ok' => true,
            'escpos_base64' => $command,
            'message' => 'Pulso de apertura de cajón generado.',
        ]);
    }
}
