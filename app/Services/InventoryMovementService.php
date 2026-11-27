<?php

namespace App\Services;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Policies\WarehouseDispatchPolicy;
use App\Services\Inventory\Dispatches\WarehouseDispatchFeatureGuard;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryMovementService
{
    /**
     * Record a movement with its already-signed quantity.
     * Reserved warehouse dispatch types (DISPATCH_OUT / DISPATCH_RETURN) cannot be
     * created through this generic entry point.
     *
     * @param array{
     *     inventory_product_id: int,
     *     inventory_location_id: int,
     *     type: string,
     *     quantity: int,
     *     reference_type?: ?string,
     *     reference_id?: ?int,
     *     reference?: ?string,
     *     notes?: ?string,
     *     metadata?: ?array,
     *     occurred_at?: \DateTimeInterface|string|null,
     *     external_key?: ?string,
     *     created_by?: ?int
     * } $data
     */
    public function record(array $data, ?User $user = null): InventoryMovement
    {
        return $this->persistMovement($data, $user, allowDispatchReserved: false);
    }

    /**
     * Exclusive, verified entry point for recording Phase 1 warehouse dispatch deductions.
     * Validates feature flags, actor authorization, DB-locked document & item state,
     * active non-kit product, location, normalized quantity, reference, and external_key.
     * DISPATCH_RETURN remains strictly blocked in Phase 1.
     */
    public function recordForDispatch(
        InventoryDispatch $dispatch,
        InventoryDispatchItem $item,
        int $physicalQuantity,
        ?User $user = null,
    ): InventoryMovement {
        $guard = app(WarehouseDispatchFeatureGuard::class);
        $guard->assertWritable();

        if (! $guard->isApplyLedgerEnabled()) {
            throw new InvalidArgumentException('El descuento automático al ledger de Salidas de Almacén está deshabilitado.');
        }

        if (! $user instanceof User) {
            throw new AuthorizationException('Se requiere un usuario autenticado y autorizado para generar movimientos DISPATCH_OUT.');
        }

        if (! $dispatch->exists || ! $item->exists) {
            throw new InvalidArgumentException('El documento y la partida de salida deben existir antes de descontar inventario.');
        }

        if ($physicalQuantity <= 0) {
            throw new InvalidArgumentException('La cantidad física solicitada debe ser un entero mayor que cero.');
        }

        return DB::transaction(function () use ($dispatch, $item, $physicalQuantity, $user): InventoryMovement {
            $freshDispatch = InventoryDispatch::query()
                ->lockForUpdate()
                ->find($dispatch->getKey());
            $freshItem = InventoryDispatchItem::query()
                ->lockForUpdate()
                ->find($item->getKey());

            if (! $freshDispatch || ! $freshItem) {
                throw new InvalidArgumentException('El documento o la partida de salida no existen en la base de datos.');
            }

            if (! app(WarehouseDispatchPolicy::class)->confirm($user, $freshDispatch)) {
                throw new AuthorizationException('El usuario no tiene permisos para generar movimientos de salida de almacén.');
            }

            if ((int) $freshItem->inventory_dispatch_id !== (int) $freshDispatch->getKey()) {
                throw new InvalidArgumentException('La partida no pertenece al documento de salida indicado.');
            }

            if ($freshDispatch->status !== InventoryDispatch::STATUS_DRAFT) {
                throw new InvalidArgumentException('Solo se permite generar movimientos DISPATCH_OUT durante la confirmación de un borrador válido.');
            }

            if (trim((string) $freshDispatch->dispatch_number) === '') {
                throw new InvalidArgumentException('El documento de salida carece de un folio válido para referenciar el movimiento.');
            }

            if ($freshDispatch->effective_at === null || $freshDispatch->effective_at->copy()->utc()->isFuture()) {
                throw new InvalidArgumentException('La fecha efectiva del documento de salida no es válida para generar un movimiento.');
            }

            if ($freshItem->identification_status !== InventoryDispatchItem::ID_REGISTERED) {
                throw new InvalidArgumentException('Solo las partidas con producto maestro registrado pueden descontar inventario.');
            }

            if ($freshItem->inventory_apply_status !== InventoryDispatchItem::APPLY_PENDING
                || $freshItem->inventory_movement_id !== null) {
                throw new InvalidArgumentException('La partida ya fue procesada o no está pendiente de aplicación al ledger.');
            }

            if ($freshItem->normalized_physical_quantity === null
                || (int) $freshItem->normalized_physical_quantity !== $physicalQuantity) {
                throw new InvalidArgumentException('La cantidad física solicitada no coincide con la cantidad normalizada de la partida.');
            }

            if (! $freshItem->inventory_product_id || ! $freshItem->inventory_location_id) {
                throw new InvalidArgumentException('La partida requiere producto maestro y ubicación física para descontar inventario.');
            }

            $product = InventoryProduct::query()
                ->lockForUpdate()
                ->find($freshItem->inventory_product_id);
            if (! $product) {
                throw (new ModelNotFoundException)->setModel(InventoryProduct::class);
            }
            if (! $product->is_active) {
                throw new InvalidArgumentException('No se permite descontar inventario para un producto maestro inactivo.');
            }
            if ($product->isKit() || $product->isBundleOrKit()) {
                throw new InvalidArgumentException('Los kits y paquetes no pueden descontar inventario desde Salidas de Almacén en Fase 1.');
            }

            $expectedKey = sprintf('warehouse-dispatch:%d:item:%d:out', (int) $freshDispatch->getKey(), (int) $freshItem->getKey());

            return $this->persistMovement([
                'inventory_product_id' => (int) $freshItem->inventory_product_id,
                'inventory_location_id' => (int) $freshItem->inventory_location_id,
                'type' => InventoryMovement::DISPATCH_OUT,
                'quantity' => -$physicalQuantity,
                'reference_type' => 'inventory_dispatch',
                'reference_id' => (int) $freshDispatch->getKey(),
                'reference' => (string) $freshDispatch->dispatch_number,
                'notes' => $freshItem->line_notes ?: $freshDispatch->notes,
                'metadata' => [
                    'dispatch_id' => (int) $freshDispatch->getKey(),
                    'dispatch_number' => (string) $freshDispatch->dispatch_number,
                    'dispatch_item_id' => (int) $freshItem->getKey(),
                    'destination_id' => (int) $freshDispatch->destination_id,
                    'destination_code' => $freshDispatch->destination_code_snapshot,
                    'operation_type' => (string) $freshDispatch->operation_type,
                    'effective_at' => $freshDispatch->effective_at->copy()->utc()->toIso8601String(),
                ],
                'occurred_at' => $freshDispatch->effective_at->copy()->utc(),
                'external_key' => $expectedKey,
                'created_by' => $user->getKey(),
            ], $user, allowDispatchReserved: true);
        });
    }

    /**
     * Record a manual movement from a positive magnitude.
     */
    public function recordManual(array $data, ?User $user = null): InventoryMovement
    {
        $type = (string) ($data['type'] ?? '');
        if (InventoryMovement::isDispatchReservedType($type)) {
            throw new InvalidArgumentException('Los movimientos DISPATCH_OUT y DISPATCH_RETURN están reservados al módulo de Salidas de Almacén.');
        }

        $magnitude = $data['quantity'] ?? null;

        if (is_string($magnitude) && filter_var($magnitude, FILTER_VALIDATE_INT) !== false) {
            $magnitude = (int) $magnitude;
        }
        if (! is_int($magnitude) || $magnitude <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser un entero mayor que cero.');
        }

        $data['quantity'] = in_array($type, InventoryMovement::POSITIVE_TYPES, true)
            ? $magnitude
            : -$magnitude;

        return $this->persistMovement($data, $user, allowDispatchReserved: false);
    }

    /**
     * Canonical ledger persistence shared by all authorized callers.
     */
    private function persistMovement(array $data, ?User $user, bool $allowDispatchReserved): InventoryMovement
    {
        return DB::transaction(function () use ($data, $user, $allowDispatchReserved): InventoryMovement {
            $type = (string) ($data['type'] ?? '');
            $quantity = $data['quantity'] ?? null;

            $this->validateTypeAndQuantity($type, $quantity, $allowDispatchReserved);

            $product = InventoryProduct::query()
                ->lockForUpdate()
                ->find($data['inventory_product_id'] ?? null);
            if (! $product) {
                throw (new ModelNotFoundException)->setModel(InventoryProduct::class);
            }
            if ($product->isKit()) {
                throw new InvalidArgumentException('Los kits no tienen inventario físico propio; el inventario se administra mediante sus componentes.');
            }

            $location = InventoryLocation::query()
                ->find($data['inventory_location_id'] ?? null);
            if (! $location) {
                throw (new ModelNotFoundException)->setModel(InventoryLocation::class);
            }

            $externalKey = $data['external_key'] ?? null;
            if ($externalKey !== null) {
                $existing = InventoryMovement::query()
                    ->where('external_key', $externalKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            if ($quantity < 0) {
                $physical = (int) InventoryMovement::query()
                    ->where('inventory_product_id', $product->getKey())
                    ->where('inventory_location_id', $location->getKey())
                    ->sum('quantity');
                $reserved = (int) InventoryReservation::query()
                    ->active()
                    ->where('inventory_product_id', $product->getKey())
                    ->where(function ($query) use ($location): void {
                        $query->where('inventory_location_id', $location->getKey())
                            ->orWhereNull('inventory_location_id');
                    })
                    ->sum('quantity');
                $available = $physical - $reserved;
                $requested = abs((int) $quantity);
                if ($available + $quantity < 0) {
                    throw new InventoryInsufficientStockException(
                        $available,
                        $requested,
                        (string) $location->code,
                    );
                }
            }

            return InventoryMovement::create([
                'inventory_product_id' => $product->getKey(),
                'inventory_location_id' => $location->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $data['created_by'] ?? $user?->getKey(),
                'occurred_at' => isset($data['occurred_at']) && $data['occurred_at'] !== ''
                    ? Carbon::parse($data['occurred_at'])
                    : now(),
                'external_key' => $externalKey,
            ]);
        });
    }

    private function validateTypeAndQuantity(string $type, mixed $quantity, bool $allowDispatchReserved): void
    {
        if (! in_array($type, InventoryMovement::types(), true)) {
            throw new InvalidArgumentException('Tipo de movimiento inválido.');
        }

        if (InventoryMovement::isDispatchReservedType($type)) {
            if (! $allowDispatchReserved) {
                throw new InvalidArgumentException('Los movimientos DISPATCH_OUT y DISPATCH_RETURN solo pueden generarse desde el flujo autorizado de Salidas de Almacén.');
            }

            if ($type === InventoryMovement::DISPATCH_RETURN) {
                throw new InvalidArgumentException('La generación de movimientos DISPATCH_RETURN está bloqueada durante la Fase 1.');
            }
        }

        if (! is_int($quantity) || $quantity === 0) {
            throw new InvalidArgumentException('La cantidad debe ser un entero distinto de cero.');
        }

        $mustBePositive = in_array($type, InventoryMovement::POSITIVE_TYPES, true);
        if (($mustBePositive && $quantity < 0) || (! $mustBePositive && $quantity > 0)) {
            throw new InvalidArgumentException('El signo de la cantidad no corresponde al tipo de movimiento.');
        }
    }
}
