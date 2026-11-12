<?php

namespace App\Services\Autopartes;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartBranchSetting;
use App\Models\AutomotiveStockAdjustment;
use App\Models\AutopartesModel;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AutomotiveStockAdjustmentService
{
    public function __construct(
        private BranchCatalogAccess $access,
        private AutomotiveMovementService $movements,
    ) {}

    /**
     * Create an audited stock adjustment for a branch, optionally approving and applying it immediately
     * when the actor also holds the ADJUSTMENT_APPROVE capability.
     *
     * @param array{
     *     automotive_part_id: int,
     *     automotive_location_id?: ?int,
     *     type: string,
     *     quantity: mixed,
     *     reason: string,
     *     notes?: ?string,
     *     idempotency_key: string,
     *     auto_approve?: bool
     * } $data
     */
    public function create(User $user, AutomotiveBranch $branch, array $data): AutomotiveStockAdjustment
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::ADJUSTMENT_CREATE);

        $autoApprove = (bool) ($data['auto_approve'] ?? true);
        if ($autoApprove) {
            $this->access->authorize($user, $branch, BranchCatalogAccess::ADJUSTMENT_APPROVE);
        }

        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, AutomotiveStockAdjustment::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'El tipo de ajuste debe ser ADJUSTMENT_IN o ADJUSTMENT_OUT.',
            ]);
        }

        $quantity = $this->validatePositiveQuantity($data['quantity'] ?? null);

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo del ajuste es obligatorio y debe tener al menos 5 caracteres.',
            ]);
        }
        if (mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo del ajuste no puede exceder 1000 caracteres.',
            ]);
        }

        $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
            ? mb_substr(trim((string) $data['notes']), 0, 2000)
            : null;

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Se requiere una clave de idempotencia válida para registrar el ajuste.',
            ]);
        }

        $partId = (int) ($data['automotive_part_id'] ?? 0);
        $targetLocationId = isset($data['automotive_location_id']) && $data['automotive_location_id'] !== null && $data['automotive_location_id'] !== ''
            ? (int) $data['automotive_location_id']
            : (int) $branch->ensureDefaultReceptionLocation()->getKey();

        return AutopartesModel::transaction(function () use ($user, $branch, $partId, $targetLocationId, $type, $quantity, $reason, $notes, $idempotencyKey, $autoApprove): AutomotiveStockAdjustment {
            $location = AutomotiveLocation::query()->lockForUpdate()->find($targetLocationId);

            if (! $location || (int) $location->branch_id !== (int) $branch->getKey() || ! $location->is_active) {
                throw ValidationException::withMessages([
                    'automotive_location_id' => 'La ubicación seleccionada no pertenece a la sucursal autorizada o está inactiva.',
                ]);
            }

            $payloadHash = hash('sha256', json_encode([
                'branch_id' => (int) $branch->getKey(),
                'location_id' => (int) $location->getKey(),
                'automotive_part_id' => $partId,
                'type' => $type,
                'quantity' => $quantity,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR));

            $existing = AutomotiveStockAdjustment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $lockedExisting = AutomotiveStockAdjustment::query()->lockForUpdate()->findOrFail($existing->getKey());
                if ((int) $lockedExisting->branch_id === (int) $branch->getKey() && $lockedExisting->payload_hash === $payloadHash) {
                    if ($autoApprove && $lockedExisting->status === AutomotiveStockAdjustment::STATUS_PENDING) {
                        return $this->applyLockedAdjustment($user, $branch, $lockedExisting);
                    }

                    return $lockedExisting->fresh(['part', 'location', 'movement']);
                }

                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave de idempotencia ya fue utilizada con parámetros distintos.',
                ]);
            }

            $part = AutomotivePart::query()->lockForUpdate()->find($partId);
            if (! $part) {
                throw ValidationException::withMessages([
                    'automotive_part_id' => 'La autoparte seleccionada no pertenece al catálogo exclusivo de Autopartes.',
                ]);
            }

            if (! $part->isReceivable()) {
                throw ValidationException::withMessages([
                    'automotive_part_id' => 'La autoparte tiene identidad ambigua pendiente de verificación y no admite ajustes de stock físico.',
                ]);
            }

            $folio = $this->nextFolio($branch);

            $adjustment = AutomotiveStockAdjustment::create([
                'branch_id' => $branch->getKey(),
                'automotive_location_id' => $location->getKey(),
                'automotive_part_id' => $part->getKey(),
                'folio' => $folio,
                'status' => AutomotiveStockAdjustment::STATUS_PENDING,
                'type' => $type,
                'quantity' => $quantity,
                'reason' => $reason,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'created_by' => $user->getKey(),
            ]);

            if ($autoApprove) {
                return $this->applyLockedAdjustment($user, $branch, $adjustment);
            }

            return $adjustment->fresh(['part', 'location', 'movement']);
        });
    }

    public function approve(User $approver, AutomotiveBranch $branch, AutomotiveStockAdjustment $adjustment): AutomotiveStockAdjustment
    {
        $this->access->authorize($approver, $branch, BranchCatalogAccess::ADJUSTMENT_APPROVE);

        if ((int) $adjustment->branch_id !== (int) $branch->getKey()) {
            abort(404);
        }

        return AutopartesModel::transaction(function () use ($approver, $branch, $adjustment): AutomotiveStockAdjustment {
            $locked = AutomotiveStockAdjustment::query()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->branch_id !== (int) $branch->getKey()) {
                abort(404);
            }

            if ($locked->status === AutomotiveStockAdjustment::STATUS_APPROVED) {
                return $locked->fresh(['part', 'location', 'movement']);
            }

            if ($locked->status !== AutomotiveStockAdjustment::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'adjustment' => 'Solo se pueden aprobar ajustes en estado pendiente.',
                ]);
            }

            return $this->applyLockedAdjustment($approver, $branch, $locked);
        });
    }

    private function applyLockedAdjustment(User $approver, AutomotiveBranch $branch, AutomotiveStockAdjustment $locked): AutomotiveStockAdjustment
    {
        $part = AutomotivePart::query()
            ->lockForUpdate()
            ->findOrFail($locked->automotive_part_id);

        $location = AutomotiveLocation::query()
            ->lockForUpdate()
            ->findOrFail($locked->automotive_location_id);

        if (! $part->isReceivable()) {
            throw ValidationException::withMessages([
                'automotive_part_id' => 'La autoparte no es válida o tiene identidad ambigua pendiente de verificación.',
            ]);
        }

        if ((int) $location->branch_id !== (int) $branch->getKey() || ! $location->is_active) {
            throw ValidationException::withMessages([
                'automotive_location_id' => 'La ubicación no pertenece a la sucursal o está inactiva.',
            ]);
        }

        $signedQuantity = $locked->type === AutomotiveStockAdjustment::TYPE_IN
            ? (int) $locked->quantity
            : -((int) $locked->quantity);

        try {
            $movement = $this->movements->record([
                'branch_id' => (int) $branch->getKey(),
                'automotive_part_id' => (int) $part->getKey(),
                'automotive_location_id' => (int) $location->getKey(),
                'type' => $locked->type,
                'quantity' => $signedQuantity,
                'reference_type' => 'automotive_adjustment',
                'reference_id' => (int) $locked->getKey(),
                'reference' => $locked->folio,
                'notes' => $locked->notes ? "{$locked->reason} | {$locked->notes}" : $locked->reason,
                'metadata' => [
                    'automotive_branch_id' => (int) $branch->getKey(),
                    'branch_code' => $branch->code,
                    'adjustment_id' => (int) $locked->getKey(),
                    'adjustment_folio' => $locked->folio,
                    'reason' => $locked->reason,
                    'created_by' => (int) $locked->created_by,
                    'approved_by' => (int) $approver->getKey(),
                ],
                'external_key' => "automotive-adjustment:{$locked->getKey()}",
            ], $approver);
        } catch (InventoryInsufficientStockException $e) {
            throw ValidationException::withMessages([
                'quantity' => $e->getMessage(),
            ]);
        }

        $locked->update([
            'status' => AutomotiveStockAdjustment::STATUS_APPROVED,
            'stock_before' => $movement->stock_before,
            'stock_after' => $movement->stock_after,
            'automotive_movement_id' => $movement->getKey(),
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ]);

        $branchSetting = AutomotivePartBranchSetting::query()->firstOrCreate(
            [
                'automotive_part_id' => $part->getKey(),
                'branch_id' => $branch->getKey(),
            ],
            [
                'primary_location_id' => $location->getKey(),
                'is_sellable' => false,
            ]
        );

        if ($branchSetting->primary_location_id === null) {
            $branchSetting->update([
                'primary_location_id' => $location->getKey(),
            ]);
        }

        return $locked->fresh(['part', 'location', 'movement']);
    }

    private function validatePositiveQuantity(mixed $value): int
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser un número entero mayor a cero.']);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! preg_match('/^-?\d+$/', $trimmed)) {
                throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser un número entero sin decimales.']);
            }
            $value = (int) $trimmed;
        } elseif (is_float($value)) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad no admite decimales; debe ser un número entero.']);
        }

        if (! is_int($value) || $value <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad del ajuste debe ser mayor a cero.']);
        }

        $max = (int) config('autopartes.max_adjustment_quantity', 5000);
        if ($value > $max) {
            throw ValidationException::withMessages(['quantity' => "La cantidad del ajuste no puede exceder {$max} unidades."]);
        }

        return $value;
    }

    private function nextFolio(AutomotiveBranch $branch): string
    {
        $datePrefix = now()->format('Ymd');
        $prefix = "ADJ-{$branch->code}-{$datePrefix}-";

        $count = AutomotiveStockAdjustment::query()
            ->where('branch_id', $branch->getKey())
            ->where('folio', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->count();

        do {
            $count++;
            $folio = $prefix.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
        } while (AutomotiveStockAdjustment::query()->where('folio', $folio)->exists());

        return $folio;
    }
}
