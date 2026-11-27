<?php

namespace App\Services\Inventory\Dispatches;

use App\Exceptions\InventoryInsufficientStockException;
use App\Exceptions\WarehouseDispatchConflictException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchDestination;
use App\Models\InventoryDispatchEvent;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\User;
use App\Policies\WarehouseDispatchPolicy;
use App\Services\InventoryMovementService;
use App\Support\InventoryDateTime;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WarehouseDispatchService
{
    public function __construct(
        private readonly WarehouseDispatchFeatureGuard $guard,
        private readonly WarehouseDispatchPolicy $policy,
        private readonly WarehouseDispatchCutoverEvaluator $cutoverEvaluator,
        private readonly ProvisionalItemLinkService $provisionalService,
        private readonly InventoryMovementService $movementService,
        private readonly DeadlockRetryRunner $deadlockRetry,
    ) {}

    /**
     * Create a new DRAFT dispatch with idempotency protection (create_idempotency_key + canonical hash).
     */
    public function createDraft(array $data, User $actor): InventoryDispatch
    {
        $this->guard->assertWritable();

        if (! $this->policy->create($actor)) {
            throw new AuthorizationException('No tienes permiso para crear salidas de almacén.');
        }

        $createKey = $this->nullableTrim($data['create_idempotency_key'] ?? null);
        $effectiveAtUtc = $this->parseAndValidateEffectiveAt($data['effective_at'] ?? null);
        $nowUtc = now('UTC');
        $isRetroactive = $this->isRetroactiveTimestamp($effectiveAtUtc, $nowUtc);
        $retroReason = $this->nullableTrim($data['retroactive_reason'] ?? null);

        $this->assertRetroactivePermission($actor, $isRetroactive, $retroReason);

        $normalizedItems = $this->validateAndNormalizeItemsInput($data['items'] ?? []);
        $payloadHash = $this->buildCreatePayloadHash($data, $effectiveAtUtc, $normalizedItems);

        if ($createKey !== null) {
            $existing = InventoryDispatch::query()
                ->where('create_idempotency_key', $createKey)
                ->first();
            if ($existing) {
                return $this->resolveExistingCreateIdempotency($existing, $actor, $payloadHash);
            }
        }

        return $this->deadlockRetry->run(function () use (
            $data,
            $actor,
            $createKey,
            $payloadHash,
            $effectiveAtUtc,
            $nowUtc,
            $isRetroactive,
            $retroReason,
            $normalizedItems
        ): InventoryDispatch {
            $attempts = 0;

            while (true) {
                $attempts++;

                try {
                    return DB::transaction(function () use (
                        $data,
                        $actor,
                        $createKey,
                        $payloadHash,
                        $effectiveAtUtc,
                        $nowUtc,
                        $isRetroactive,
                        $retroReason,
                        $normalizedItems
                    ): InventoryDispatch {
                        if ($createKey !== null) {
                            $existingLocked = InventoryDispatch::query()
                                ->where('create_idempotency_key', $createKey)
                                ->lockForUpdate()
                                ->first();
                            if ($existingLocked) {
                                return $this->resolveExistingCreateIdempotency($existingLocked, $actor, $payloadHash);
                            }
                        }

                        $destination = InventoryDispatchDestination::query()
                            ->findOrFail((int) $data['destination_id']);
                        if (! $destination->is_active) {
                            throw new InvalidArgumentException("El destino '{$destination->name}' está inactivo y no admite nuevas salidas.");
                        }

                        $operationType = (string) $data['operation_type'];
                        if (! in_array($operationType, InventoryDispatch::OPERATION_TYPES, true)) {
                            throw new InvalidArgumentException('Tipo de salida inválido.');
                        }

                        $sourceLocationId = ! empty($data['source_location_id']) ? (int) $data['source_location_id'] : null;
                        if ($sourceLocationId !== null) {
                            InventoryLocation::query()->where('is_active', true)->findOrFail($sourceLocationId);
                        }

                        $dispatchNumber = $this->generateNextDispatchNumber($nowUtc);

                        $dispatch = new InventoryDispatch;
                        $dispatch->forceFill([
                            'dispatch_number' => $dispatchNumber,
                            'create_idempotency_key' => $createKey,
                            'create_payload_hash' => $createKey !== null ? $payloadHash : null,
                            'draft_version' => 1,
                            'destination_id' => $destination->getKey(),
                            'destination_code_snapshot' => $destination->code,
                            'destination_name_snapshot' => $destination->name,
                            'destination_type_snapshot' => $destination->destination_type,
                            'source_location_id' => $sourceLocationId,
                            'operation_type' => $operationType,
                            'commercial_status' => $operationType === InventoryDispatch::OP_SALON_SALE
                                ? InventoryDispatch::COMMERCIAL_REFERENCE_ONLY
                                : InventoryDispatch::COMMERCIAL_NOT_APPLICABLE,
                            'status' => InventoryDispatch::STATUS_DRAFT,
                            'effective_at' => $effectiveAtUtc,
                            'is_retroactive' => $isRetroactive,
                            'retroactive_reason' => $isRetroactive ? $retroReason : null,
                            'captured_at' => $nowUtc,
                            'delivered_by_user_id' => $actor->getKey(),
                            'delivered_by_name_snapshot' => $actor->name,
                            'received_by_name' => $this->nullableTrim($data['received_by_name'] ?? null),
                            'received_by_user_id' => ! empty($data['received_by_user_id']) ? (int) $data['received_by_user_id'] : null,
                            'notes' => $this->nullableTrim($data['notes'] ?? null),
                            'created_by' => $actor->getKey(),
                        ]);
                        $dispatch->save();

                        $this->persistDraftItems($dispatch, $normalizedItems, $actor);
                        $this->recalculateDraftTotals($dispatch);

                        $this->recordEvent(
                            dispatchId: (int) $dispatch->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_DRAFT_CREATED,
                            fromStatus: null,
                            toStatus: InventoryDispatch::STATUS_DRAFT,
                            actor: $actor,
                            reason: $isRetroactive ? $retroReason : null,
                            payload: [
                                'dispatch_number' => $dispatch->dispatch_number,
                                'destination_code' => $destination->code,
                                'operation_type' => $operationType,
                                'total_lines_count' => $dispatch->total_lines_count,
                                'total_physical_units' => $dispatch->total_physical_units,
                                'unconverted_containers_count' => $dispatch->unconverted_containers_count,
                            ],
                            occurredAt: $nowUtc,
                        );

                        return $dispatch->fresh(['destination', 'sourceLocation', 'items.product', 'items.provisionalItem', 'items.location']);
                    });
                } catch (QueryException $exception) {
                    if ($createKey !== null && $this->isUniqueConstraintViolation($exception)) {
                        $existingAfterRace = InventoryDispatch::query()
                            ->where('create_idempotency_key', $createKey)
                            ->first();
                        if ($existingAfterRace) {
                            return $this->resolveExistingCreateIdempotency($existingAfterRace, $actor, $payloadHash);
                        }
                    }

                    if ($attempts >= 5 || ! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }
                    usleep(random_int(10, 35) * 1000);
                }
            }
        });
    }

    /**
     * Update an existing DRAFT dispatch. Confirmed/cancelled documents are immutable.
     */
    public function updateDraft(InventoryDispatch $dispatch, array $data, User $actor): InventoryDispatch
    {
        $this->guard->assertWritable();

        if (! $this->policy->update($actor, $dispatch)) {
            throw new AuthorizationException('No tienes permiso para editar este borrador de salida.');
        }

        $effectiveAtUtc = $this->parseAndValidateEffectiveAt($data['effective_at'] ?? null);
        $nowUtc = now('UTC');
        $isRetroactive = $this->isRetroactiveTimestamp($effectiveAtUtc, $nowUtc);
        $retroReason = $this->nullableTrim($data['retroactive_reason'] ?? null);

        $this->assertRetroactivePermission($actor, $isRetroactive, $retroReason);

        $normalizedItems = $this->validateAndNormalizeItemsInput($data['items'] ?? []);

        return $this->deadlockRetry->run(function () use (
            $dispatch,
            $data,
            $actor,
            $effectiveAtUtc,
            $nowUtc,
            $isRetroactive,
            $retroReason,
            $normalizedItems
        ): InventoryDispatch {
            return DB::transaction(function () use (
                $dispatch,
                $data,
                $actor,
                $effectiveAtUtc,
                $nowUtc,
                $isRetroactive,
                $retroReason,
                $normalizedItems
            ): InventoryDispatch {
                $locked = InventoryDispatch::query()
                    ->lockForUpdate()
                    ->findOrFail($dispatch->getKey());

                if (! $locked->isDraft()) {
                    throw new WarehouseDispatchConflictException('No se puede editar una salida que ya fue confirmada o cancelada.');
                }

                if (! $this->policy->update($actor, $locked)) {
                    throw new AuthorizationException('No tienes permiso para editar este borrador de salida.');
                }

                if (isset($data['expected_draft_version'])
                    && (int) $data['expected_draft_version'] !== (int) $locked->draft_version) {
                    throw new WarehouseDispatchConflictException('El borrador fue modificado por otro proceso; recarga antes de guardar.');
                }

                $destination = InventoryDispatchDestination::query()
                    ->findOrFail((int) $data['destination_id']);
                if (! $destination->is_active) {
                    throw new InvalidArgumentException("El destino '{$destination->name}' está inactivo.");
                }

                $operationType = (string) $data['operation_type'];
                if (! in_array($operationType, InventoryDispatch::OPERATION_TYPES, true)) {
                    throw new InvalidArgumentException('Tipo de salida inválido.');
                }

                $sourceLocationId = ! empty($data['source_location_id']) ? (int) $data['source_location_id'] : null;
                if ($sourceLocationId !== null) {
                    InventoryLocation::query()->where('is_active', true)->findOrFail($sourceLocationId);
                }

                $locked->forceFill([
                    'draft_version' => ((int) $locked->draft_version) + 1,
                    'destination_id' => $destination->getKey(),
                    'destination_code_snapshot' => $destination->code,
                    'destination_name_snapshot' => $destination->name,
                    'destination_type_snapshot' => $destination->destination_type,
                    'source_location_id' => $sourceLocationId,
                    'operation_type' => $operationType,
                    'commercial_status' => $operationType === InventoryDispatch::OP_SALON_SALE
                        ? InventoryDispatch::COMMERCIAL_REFERENCE_ONLY
                        : InventoryDispatch::COMMERCIAL_NOT_APPLICABLE,
                    'effective_at' => $effectiveAtUtc,
                    'is_retroactive' => $isRetroactive,
                    'retroactive_reason' => $isRetroactive ? $retroReason : null,
                    'received_by_name' => $this->nullableTrim($data['received_by_name'] ?? null),
                    'received_by_user_id' => ! empty($data['received_by_user_id']) ? (int) $data['received_by_user_id'] : null,
                    'notes' => $this->nullableTrim($data['notes'] ?? null),
                ])->save();

                // Draft items have no audit/ledger references yet; replace cleanly inside the locked transaction.
                $locked->items()->delete();
                $this->persistDraftItems($locked, $normalizedItems, $actor);
                $this->recalculateDraftTotals($locked);

                $this->recordEvent(
                    dispatchId: (int) $locked->getKey(),
                    eventType: InventoryDispatchEvent::EVENT_DRAFT_UPDATED,
                    fromStatus: InventoryDispatch::STATUS_DRAFT,
                    toStatus: InventoryDispatch::STATUS_DRAFT,
                    actor: $actor,
                    reason: null,
                    payload: [
                        'draft_version' => (int) $locked->draft_version,
                        'total_lines_count' => $locked->total_lines_count,
                        'total_physical_units' => $locked->total_physical_units,
                    ],
                    occurredAt: $nowUtc,
                );

                return $locked->fresh(['destination', 'sourceLocation', 'items.product', 'items.provisionalItem', 'items.location']);
            });
        });
    }

    /**
     * Confirm a DRAFT dispatch atomically and idempotently.
     *
     * Key invariants:
     * - Separate confirm_idempotency_key + deterministic confirm_payload_hash computed over immutable draft inputs.
     * - Evaluates physical cutover for EVERY registered item (independent of 24h retroactive permission check).
     * - When apply_ledger = false, records SKIPPED_LEDGER_DISABLED with 0 inventory_movements.
     * - When apply_ledger = true, locks InventoryProduct BEFORE cutover check + InventoryMovementService::recordForDispatch().
     * - Catches EXCLUSIVELY InventoryInsufficientStockException (SAVEPOINT rollback) to log DISCREPANCY_INSUFFICIENT_STOCK;
     *   unexpected DB/SQL exceptions propagate and roll back the entire transaction.
     * - ZERO direct calls or queued jobs to Mercado Libre.
     */
    public function confirm(
        InventoryDispatch $dispatch,
        User $actor,
        string $confirmIdempotencyKey,
        ?int $expectedDraftVersion = null,
    ): InventoryDispatch {
        $this->guard->assertWritable();

        if (! $this->policy->confirm($actor, $dispatch)) {
            throw new AuthorizationException('No tienes permiso para confirmar salidas de almacén.');
        }

        $confirmKey = trim($confirmIdempotencyKey);
        if ($confirmKey === '') {
            throw new InvalidArgumentException('La clave de idempotencia de confirmación es obligatoria.');
        }

        return $this->deadlockRetry->run(function () use ($dispatch, $actor, $confirmKey, $expectedDraftVersion): InventoryDispatch {
            return DB::transaction(function () use ($dispatch, $actor, $confirmKey, $expectedDraftVersion): InventoryDispatch {
                // Reject reuse of a confirm key belonging to a DIFFERENT dispatch document.
                $otherUsingKey = InventoryDispatch::query()
                    ->where('confirm_idempotency_key', $confirmKey)
                    ->where('id', '!=', $dispatch->getKey())
                    ->lockForUpdate()
                    ->first();
                if ($otherUsingKey) {
                    throw new WarehouseDispatchConflictException('La clave de confirmación ya fue utilizada en otro documento de salida.');
                }

                /** @var InventoryDispatch $locked */
                $locked = InventoryDispatch::query()
                    ->with(['destination', 'deliveredBy', 'items'])
                    ->lockForUpdate()
                    ->findOrFail($dispatch->getKey());

                if (! $this->policy->confirm($actor, $locked)) {
                    throw new AuthorizationException('No tienes permiso para confirmar esta salida de almacén.');
                }

                $canonicalConfirmHash = $this->buildConfirmPayloadHash($locked, $expectedDraftVersion);

                // Idempotent replay check after commit or lost HTTP response
                if ($locked->isConfirmed()) {
                    if ($locked->confirm_idempotency_key === $confirmKey
                        && $locked->confirm_payload_hash === $canonicalConfirmHash) {
                        if ((int) $locked->confirmed_by_user_id !== (int) $actor->getKey()) {
                            throw new WarehouseDispatchConflictException('La clave de confirmación pertenece a otro usuario.');
                        }

                        return $locked->fresh(['destination', 'sourceLocation', 'items.product', 'items.provisionalItem', 'items.location', 'events.actor']);
                    }

                    throw new WarehouseDispatchConflictException('La salida ya fue confirmada previamente con otra clave o contenido diferente.');
                }

                if (! $locked->isDraft()) {
                    throw new WarehouseDispatchConflictException('Solo se pueden confirmar salidas en estado borrador.');
                }

                if ($expectedDraftVersion !== null && (int) $locked->draft_version !== $expectedDraftVersion) {
                    throw new WarehouseDispatchConflictException('El borrador cambió antes de confirmarse; revisa las partidas nuevamente.');
                }

                if ($locked->items->isEmpty()) {
                    throw new InvalidArgumentException('No se puede confirmar una salida sin partidas.');
                }

                $destination = InventoryDispatchDestination::query()
                    ->lockForUpdate()
                    ->findOrFail($locked->destination_id);
                if (! $destination->is_active) {
                    throw new WarehouseDispatchConflictException("El destino '{$destination->name}' fue desactivado antes de confirmar.");
                }

                $nowUtc = now('UTC');
                $effectiveUtc = $locked->effective_at->copy()->utc();
                if ($effectiveUtc->gt($nowUtc)) {
                    throw new InvalidArgumentException('La fecha efectiva de entrega no puede estar en el futuro.');
                }

                $isRetroactive = $this->isRetroactiveTimestamp($effectiveUtc, $nowUtc);
                $this->assertRetroactivePermission($actor, $isRetroactive, $locked->retroactive_reason);

                // Freeze header snapshots before processing items
                $locked->forceFill([
                    'destination_code_snapshot' => $destination->code,
                    'destination_name_snapshot' => $destination->name,
                    'destination_type_snapshot' => $destination->destination_type,
                    'delivered_by_name_snapshot' => $locked->deliveredBy?->name ?: $locked->delivered_by_name_snapshot,
                    'confirmed_by_name_snapshot' => $actor->name,
                    'is_retroactive' => $isRetroactive,
                ])->save();

                // Deterministic lock order: provisional items (id ASC), then registered items sorted by (product_id ASC, location_id ASC, id ASC).
                $provisionalIds = $locked->items
                    ->pluck('provisional_item_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($provisionalIds !== []) {
                    $provMap = InventoryProvisionalItem::query()
                        ->whereIn('id', $provisionalIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($provMap as $prov) {
                        if ($prov->status === InventoryProvisionalItem::STATUS_ARCHIVED) {
                            throw new WarehouseDispatchConflictException("La ficha provisional {$prov->provisional_code} fue archivada.");
                        }
                    }
                }

                $orderedItems = $locked->items->sortBy([
                    fn (InventoryDispatchItem $a, InventoryDispatchItem $b): int => ((int) ($a->inventory_product_id ?? PHP_INT_MAX)) <=> ((int) ($b->inventory_product_id ?? PHP_INT_MAX)),
                    fn (InventoryDispatchItem $a, InventoryDispatchItem $b): int => ((int) ($a->inventory_location_id ?? PHP_INT_MAX)) <=> ((int) ($b->inventory_location_id ?? PHP_INT_MAX)),
                    fn (InventoryDispatchItem $a, InventoryDispatchItem $b): int => ((int) $a->getKey()) <=> ((int) $b->getKey()),
                ])->values();

                $applyLedger = $this->guard->isApplyLedgerEnabled();
                $isSalonSale = $locked->operation_type === InventoryDispatch::OP_SALON_SALE;

                $costSum = 0.0;
                $costComplete = true;
                $hasAnyCost = false;
                $stylistSum = 0.0;
                $stylistComplete = true;
                $hasAnyStylist = false;
                $totalPhysicalUnits = 0;
                $unconvertedContainers = 0;

                foreach ($orderedItems as $item) {
                    $location = $item->inventory_location_id
                        ? InventoryLocation::query()->find($item->inventory_location_id, ['id', 'code', 'is_active'])
                        : null;
                    $locationCodeSnapshot = $location?->code ?: $item->location_code_snapshot;

                    if ($item->normalized_physical_quantity !== null) {
                        $totalPhysicalUnits += (int) $item->normalized_physical_quantity;
                    } else {
                        $unconvertedContainers++;
                    }

                    // Case 1: Provisional or ambiguous pending item -> 0 ledger movements
                    if ($item->inventory_product_id === null
                        || in_array($item->identification_status, [
                            InventoryDispatchItem::ID_PROVISIONAL,
                            InventoryDispatchItem::ID_AMBIGUOUS_PENDING,
                        ], true)) {
                        $costComplete = false;
                        if ($isSalonSale) {
                            $stylistComplete = false;
                        }

                        $item->forceFill([
                            'location_code_snapshot' => $locationCodeSnapshot,
                            'inventory_apply_status' => InventoryDispatchItem::APPLY_SKIPPED_PROVISIONAL,
                            'inventory_resolution_notes' => 'Partida provisional sin producto maestro; registrada únicamente como historial de entrega.',
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                        ])->save();

                        continue;
                    }

                    // Case 2: Registered SIMPLE product -> lock product BEFORE cutover & ledger operations
                    /** @var InventoryProduct $product */
                    $product = InventoryProduct::query()
                        ->lockForUpdate()
                        ->findOrFail($item->inventory_product_id);

                    if ($product->isKit() || $product->isBundleOrKit()) {
                        throw new InvalidArgumentException("El producto '{$product->name}' es un kit y está bloqueado en Fase 1.");
                    }

                    $unitCost = $product->cost !== null ? (float) $product->cost : null;
                    $unitStylist = ($isSalonSale && $product->price_stylist !== null) ? (float) $product->price_stylist : null;
                    $multiplier = $item->normalized_physical_quantity !== null ? (int) $item->normalized_physical_quantity : null;

                    if ($unitCost !== null && $multiplier !== null) {
                        $costSum += $unitCost * $multiplier;
                        $hasAnyCost = true;
                    } else {
                        $costComplete = false;
                    }

                    if ($isSalonSale) {
                        if ($unitStylist !== null && $multiplier !== null) {
                            $stylistSum += $unitStylist * $multiplier;
                            $hasAnyStylist = true;
                        } else {
                            $stylistComplete = false;
                        }
                    }

                    $item->forceFill([
                        'location_code_snapshot' => $locationCodeSnapshot,
                        'unit_cost_snapshot' => $unitCost,
                        'unit_price_stylist_snapshot' => $unitStylist,
                    ]);

                    // Subcase 2a: Unknown container conversion -> cannot deduct ledger
                    if ($item->normalized_physical_quantity === null) {
                        $item->forceFill([
                            'inventory_apply_status' => InventoryDispatchItem::APPLY_SKIPPED_UNKNOWN_CONVERSION,
                            'inventory_resolution_notes' => 'Unidad de contenedor sin equivalencia física validada; omitida del ledger.',
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                        ])->save();

                        continue;
                    }

                    // Subcase 2b: Record-only mode (apply_ledger = false) -> 0 ledger movements
                    if (! $applyLedger) {
                        $item->forceFill([
                            'inventory_apply_status' => InventoryDispatchItem::APPLY_SKIPPED_LEDGER_DISABLED,
                            'inventory_resolution_notes' => 'Modo operativo de registro sin descuento de inventario (inventory.warehouse_dispatches.apply_ledger=false).',
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                        ])->save();

                        $this->recordEvent(
                            dispatchId: (int) $locked->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_INVENTORY_SKIPPED_LEDGER_DISABLED,
                            fromStatus: InventoryDispatchItem::APPLY_PENDING,
                            toStatus: InventoryDispatchItem::APPLY_SKIPPED_LEDGER_DISABLED,
                            actor: $actor,
                            reason: 'apply_ledger=false',
                            payload: [
                                'inventory_product_id' => (int) $product->getKey(),
                                'inventory_location_id' => $item->inventory_location_id,
                                'normalized_physical_quantity' => (int) $item->normalized_physical_quantity,
                            ],
                            occurredAt: $nowUtc,
                            dispatchItemId: (int) $item->getKey(),
                        );

                        continue;
                    }

                    // Subcase 2c: Product inactive (e.g. controlled bootstrap Igora PR #12) -> never auto-deduct
                    if (! $product->is_active) {
                        $item->forceFill([
                            'inventory_apply_status' => InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION,
                            'inventory_resolution_notes' => 'Producto inactivo / en alta controlada; requiere conciliación manual antes de afectar existencias.',
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                        ])->save();

                        $this->recordEvent(
                            dispatchId: (int) $locked->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_INVENTORY_NEEDS_RECONCILIATION,
                            fromStatus: InventoryDispatchItem::APPLY_PENDING,
                            toStatus: InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION,
                            actor: $actor,
                            reason: 'inactive_product',
                            payload: ['inventory_product_id' => (int) $product->getKey()],
                            occurredAt: $nowUtc,
                            dispatchItemId: (int) $item->getKey(),
                        );

                        continue;
                    }

                    // Subcase 2d: Physical cutover evaluation (EVERY registered item, independent of 24h)
                    $cutover = $this->cutoverEvaluator->evaluate(
                        (int) $product->getKey(),
                        $item->inventory_location_id ? (int) $item->inventory_location_id : null,
                        $effectiveUtc,
                    );

                    $item->forceFill([
                        'cutover_evaluated_at' => $nowUtc,
                        'cutover_reference_type' => $cutover['reference_type'],
                        'cutover_reference_id' => $cutover['reference_id'],
                        'cutover_boundary_at' => $cutover['boundary_at'],
                    ]);

                    if ($cutover['decision'] !== WarehouseDispatchCutoverEvaluator::DECISION_CLEAR) {
                        $item->forceFill([
                            'inventory_apply_status' => $cutover['decision'],
                            'inventory_resolution_notes' => $cutover['reason'],
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                        ])->save();

                        $eventType = $cutover['decision'] === WarehouseDispatchCutoverEvaluator::DECISION_SKIPPED_PRE_COUNT
                            ? InventoryDispatchEvent::EVENT_INVENTORY_SKIPPED_PRE_COUNT
                            : InventoryDispatchEvent::EVENT_INVENTORY_NEEDS_RECONCILIATION;

                        $this->recordEvent(
                            dispatchId: (int) $locked->getKey(),
                            eventType: $eventType,
                            fromStatus: InventoryDispatchItem::APPLY_PENDING,
                            toStatus: $cutover['decision'],
                            actor: $actor,
                            reason: $cutover['reason'],
                            payload: [
                                'inventory_product_id' => (int) $product->getKey(),
                                'inventory_location_id' => $item->inventory_location_id,
                                'cutover_reference_type' => $cutover['reference_type'],
                                'cutover_reference_id' => $cutover['reference_id'],
                            ],
                            occurredAt: $nowUtc,
                            dispatchItemId: (int) $item->getKey(),
                        );

                        continue;
                    }

                    // Subcase 2e: Eligible & CLEAR -> deduct via canonical InventoryMovementService::recordForDispatch()
                    // Catch EXCLUSIVELY InventoryInsufficientStockException (SAVEPOINT rollback); never mask SQL errors!
                    try {
                        $movement = $this->movementService->recordForDispatch(
                            dispatch: $locked,
                            item: $item,
                            physicalQuantity: (int) $item->normalized_physical_quantity,
                            user: $actor,
                        );

                        $item->forceFill([
                            'inventory_apply_status' => InventoryDispatchItem::APPLY_APPLIED,
                            'inventory_movement_id' => $movement->getKey(),
                            'inventory_movement_external_key' => $movement->external_key,
                            'inventory_applied_at' => $nowUtc,
                            'inventory_applied_by_user_id' => $actor->getKey(),
                            'inventory_resolution_notes' => null,
                            // Phase 1 strictly blocks ML stock sync (fail-closed)
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_AUTHORIZED,
                        ])->save();

                        $this->recordEvent(
                            dispatchId: (int) $locked->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_INVENTORY_APPLIED,
                            fromStatus: InventoryDispatchItem::APPLY_PENDING,
                            toStatus: InventoryDispatchItem::APPLY_APPLIED,
                            actor: $actor,
                            reason: null,
                            payload: [
                                'inventory_product_id' => (int) $product->getKey(),
                                'inventory_location_id' => $item->inventory_location_id,
                                'inventory_movement_id' => (int) $movement->getKey(),
                                'quantity' => -(int) $item->normalized_physical_quantity,
                            ],
                            occurredAt: $nowUtc,
                            dispatchItemId: (int) $item->getKey(),
                        );
                    } catch (InventoryInsufficientStockException $stockException) {
                        $item->forceFill([
                            'inventory_apply_status' => InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK,
                            'inventory_resolution_notes' => $stockException->getMessage(),
                            'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                        ])->save();

                        $this->recordEvent(
                            dispatchId: (int) $locked->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_INVENTORY_DISCREPANCY_LOGGED,
                            fromStatus: InventoryDispatchItem::APPLY_PENDING,
                            toStatus: InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK,
                            actor: $actor,
                            reason: $stockException->getMessage(),
                            payload: [
                                'inventory_product_id' => (int) $product->getKey(),
                                'inventory_location_id' => $item->inventory_location_id,
                                'requested' => (int) $item->normalized_physical_quantity,
                            ],
                            occurredAt: $nowUtc,
                            dispatchItemId: (int) $item->getKey(),
                        );
                    }
                }

                $locked->forceFill([
                    'status' => InventoryDispatch::STATUS_CONFIRMED,
                    'confirm_idempotency_key' => $confirmKey,
                    'confirm_payload_hash' => $canonicalConfirmHash,
                    'confirmed_at' => $nowUtc,
                    'confirmed_by_user_id' => $actor->getKey(),
                    'total_lines_count' => $orderedItems->count(),
                    'total_physical_units' => $totalPhysicalUnits,
                    'unconverted_containers_count' => $unconvertedContainers,
                    'reference_subtotal_cost' => $hasAnyCost ? round($costSum, 2) : null,
                    'reference_cost_complete' => $costComplete && $orderedItems->isNotEmpty(),
                    'reference_subtotal_stylist' => ($isSalonSale && $hasAnyStylist) ? round($stylistSum, 2) : null,
                    'reference_stylist_complete' => $isSalonSale && $stylistComplete && $orderedItems->isNotEmpty(),
                ])->save();

                $this->recordEvent(
                    dispatchId: (int) $locked->getKey(),
                    eventType: InventoryDispatchEvent::EVENT_CONFIRMED,
                    fromStatus: InventoryDispatch::STATUS_DRAFT,
                    toStatus: InventoryDispatch::STATUS_CONFIRMED,
                    actor: $actor,
                    reason: $isRetroactive ? $locked->retroactive_reason : null,
                    payload: [
                        'apply_ledger_enabled' => $applyLedger,
                        'total_lines_count' => $orderedItems->count(),
                        'total_physical_units' => $totalPhysicalUnits,
                        'unconverted_containers_count' => $unconvertedContainers,
                    ],
                    occurredAt: $nowUtc,
                );

                return $locked->fresh(['destination', 'sourceLocation', 'items.product', 'items.provisionalItem', 'items.location', 'events.actor']);
            });
        });
    }

    /**
     * Cancel a DRAFT dispatch without touching inventory_movements.
     */
    public function cancelDraft(InventoryDispatch $dispatch, User $actor, ?string $reason = null): InventoryDispatch
    {
        $this->guard->assertWritable();

        if (! $this->policy->cancelDraft($actor, $dispatch)) {
            throw new AuthorizationException('No tienes permiso para cancelar este borrador.');
        }

        return $this->deadlockRetry->run(function () use ($dispatch, $actor, $reason): InventoryDispatch {
            return DB::transaction(function () use ($dispatch, $actor, $reason): InventoryDispatch {
                $locked = InventoryDispatch::query()->lockForUpdate()->findOrFail($dispatch->getKey());

                if (! $locked->isDraft()) {
                    throw new WarehouseDispatchConflictException('El documento ya no se encuentra en borrador.');
                }

                if (! $this->policy->cancelDraft($actor, $locked)) {
                    throw new AuthorizationException('No tienes permiso para cancelar este borrador.');
                }

                $nowUtc = now('UTC');
                $locked->forceFill([
                    'status' => InventoryDispatch::STATUS_CANCELLED,
                    'cancelled_at' => $nowUtc,
                    'cancelled_by_user_id' => $actor->getKey(),
                    'cancel_reason' => $this->nullableTrim($reason) ?: 'Borrador descartado.',
                    'cancel_physical_situation' => InventoryDispatch::CANCEL_SITUATION_DRAFT,
                ])->save();

                $locked->items()->update([
                    'inventory_apply_status' => InventoryDispatchItem::APPLY_CANCELLED_NO_MOVEMENT,
                    'updated_at' => $nowUtc,
                ]);

                $this->recordEvent(
                    dispatchId: (int) $locked->getKey(),
                    eventType: InventoryDispatchEvent::EVENT_DRAFT_CANCELLED,
                    fromStatus: InventoryDispatch::STATUS_DRAFT,
                    toStatus: InventoryDispatch::STATUS_CANCELLED,
                    actor: $actor,
                    reason: $locked->cancel_reason,
                    payload: ['physical_situation' => InventoryDispatch::CANCEL_SITUATION_DRAFT],
                    occurredAt: $nowUtc,
                );

                return $locked->fresh(['destination', 'items', 'events.actor']);
            });
        });
    }

    /**
     * Cancel an erroneous CONFIRMED dispatch ONLY when ZERO items applied movements to inventory_movements (H03).
     * Compensatory DISPATCH_RETURN reversals are strictly blocked in Phase 1.
     */
    public function cancelConfirmedWithoutLedger(
        InventoryDispatch $dispatch,
        User $actor,
        string $reason,
        string $physicalSituation,
    ): InventoryDispatch {
        $this->guard->assertWritable();

        if (! $this->policy->cancelConfirmedWithoutLedger($actor, $dispatch)) {
            throw new AuthorizationException('No tienes permiso para anular salidas confirmadas.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new InvalidArgumentException('El motivo de anulación es obligatorio.');
        }

        if ($physicalSituation !== InventoryDispatch::CANCEL_SITUATION_NEVER_DELIVERED) {
            throw new WarehouseDispatchConflictException(
                'Si la mercancía sí fue entregada físicamente al salón, no puede anularse como error; corresponde a una devolución física (Fase 2).'
            );
        }

        return $this->deadlockRetry->run(function () use ($dispatch, $actor, $trimmedReason, $physicalSituation): InventoryDispatch {
            return DB::transaction(function () use ($dispatch, $actor, $trimmedReason, $physicalSituation): InventoryDispatch {
                $locked = InventoryDispatch::query()
                    ->with('items')
                    ->lockForUpdate()
                    ->findOrFail($dispatch->getKey());

                if (! $locked->isConfirmed()) {
                    throw new WarehouseDispatchConflictException('Solo se pueden anular salidas en estado CONFIRMED.');
                }

                $hasAppliedLedger = $locked->items->contains(
                    fn (InventoryDispatchItem $item): bool => $item->inventory_apply_status === InventoryDispatchItem::APPLY_APPLIED
                        || $item->inventory_movement_id !== null
                );

                if ($hasAppliedLedger) {
                    throw new WarehouseDispatchConflictException(
                        'Esta salida ya descontó movimientos del inventario (DISPATCH_OUT). Las reversiones compensatorias (DISPATCH_RETURN) están bloqueadas en Fase 1 para evitar existencias ficticias.'
                    );
                }

                $nowUtc = now('UTC');

                $locked->forceFill([
                    'status' => InventoryDispatch::STATUS_CANCELLED,
                    'cancelled_at' => $nowUtc,
                    'cancelled_by_user_id' => $actor->getKey(),
                    'cancel_reason' => $trimmedReason,
                    'cancel_physical_situation' => $physicalSituation,
                ])->save();

                foreach ($locked->items as $item) {
                    $item->forceFill([
                        'inventory_apply_status' => InventoryDispatchItem::APPLY_CANCELLED_NO_MOVEMENT,
                        'inventory_resolution_notes' => "Anulación por error sin movimiento de inventario: {$trimmedReason}",
                    ])->save();
                }

                $this->recordEvent(
                    dispatchId: (int) $locked->getKey(),
                    eventType: InventoryDispatchEvent::EVENT_CONFIRMED_CANCELLED,
                    fromStatus: InventoryDispatch::STATUS_CONFIRMED,
                    toStatus: InventoryDispatch::STATUS_CANCELLED,
                    actor: $actor,
                    reason: $trimmedReason,
                    payload: [
                        'physical_situation' => $physicalSituation,
                        'ledger_movements_created' => 0,
                    ],
                    occurredAt: $nowUtc,
                );

                return $locked->fresh(['destination', 'items', 'events.actor']);
            });
        });
    }

    /**
     * Deterministic hash computed strictly from immutable draft inputs prior to confirmation.
     * Does NOT include timestamps mutated during confirmation.
     */
    public function buildConfirmPayloadHash(InventoryDispatch $dispatch, ?int $expectedDraftVersion = null): string
    {
        $itemsDigest = $dispatch->items
            ->sortBy('id')
            ->map(fn (InventoryDispatchItem $item): array => [
                'id' => (int) $item->getKey(),
                'product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
                'provisional_item_id' => $item->provisional_item_id ? (int) $item->provisional_item_id : null,
                'location_id' => $item->inventory_location_id ? (int) $item->inventory_location_id : null,
                'captured_quantity' => (int) $item->captured_quantity,
                'captured_unit_of_measure' => (string) $item->captured_unit_of_measure,
                'unit_conversion_factor' => $item->unit_conversion_factor !== null ? (int) $item->unit_conversion_factor : null,
                'normalized_physical_quantity' => $item->normalized_physical_quantity !== null ? (int) $item->normalized_physical_quantity : null,
            ])
            ->values()
            ->all();

        $canonical = [
            'dispatch_id' => (int) $dispatch->getKey(),
            'draft_version' => $expectedDraftVersion ?? (int) $dispatch->draft_version,
            'destination_id' => (int) $dispatch->destination_id,
            'operation_type' => (string) $dispatch->operation_type,
            'effective_at' => $dispatch->effective_at?->copy()->utc()->toIso8601String(),
            'items' => $itemsDigest,
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    private function resolveExistingCreateIdempotency(
        InventoryDispatch $existing,
        User $actor,
        string $payloadHash,
    ): InventoryDispatch {
        // Always authorize before revealing an existing idempotent document (Section 4.2).
        if (! $this->policy->view($actor, $existing)) {
            throw new AuthorizationException('No tienes permiso para acceder al documento asociado a esta clave de idempotencia.');
        }

        if ((int) $existing->created_by !== (int) $actor->getKey()) {
            throw new WarehouseDispatchConflictException('La clave de idempotencia de creación pertenece a otro usuario.');
        }

        if ($existing->create_payload_hash !== null && hash_equals($existing->create_payload_hash, $payloadHash)) {
            return $existing->fresh(['destination', 'sourceLocation', 'items.product', 'items.provisionalItem', 'items.location']);
        }

        throw new WarehouseDispatchConflictException('La clave de idempotencia de creación ya fue utilizada con un contenido diferente.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function validateAndNormalizeItemsInput(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Debes agregar al menos una partida a la salida.');
        }

        $normalized = [];

        foreach (array_values($items) as $index => $raw) {
            $kind = (string) ($raw['kind'] ?? (! empty($raw['inventory_product_id']) ? 'registered' : 'provisional'));
            $qty = (int) ($raw['captured_quantity'] ?? $raw['quantity'] ?? 0);
            if ($qty <= 0) {
                throw new InvalidArgumentException('La cantidad de cada partida debe ser un entero mayor que cero.');
            }

            $locationId = ! empty($raw['inventory_location_id']) ? (int) $raw['inventory_location_id'] : null;
            $uom = strtoupper(trim((string) ($raw['captured_unit_of_measure'] ?? $raw['unit_of_measure'] ?? InventoryProvisionalItem::UOM_UNIT)));
            if (! in_array($uom, InventoryProvisionalItem::UNITS_OF_MEASURE, true)) {
                throw new InvalidArgumentException('Unidad de medida inválida en partida #'.($index + 1).'.');
            }

            $captureMethod = (string) ($raw['capture_method'] ?? ($kind === 'registered'
                ? InventoryDispatchItem::CAPTURE_TEXT_SEARCH
                : InventoryDispatchItem::CAPTURE_MANUAL_PROVISIONAL));
            if (! in_array($captureMethod, InventoryDispatchItem::CAPTURE_METHODS, true)) {
                $captureMethod = InventoryDispatchItem::CAPTURE_MANUAL_PROVISIONAL;
            }

            if ($kind === 'registered') {
                $productId = (int) ($raw['inventory_product_id'] ?? 0);
                if ($productId <= 0) {
                    throw new InvalidArgumentException('Partida registrada sin inventory_product_id válido.');
                }

                if (! InventoryProvisionalItem::isIndividualUnit($uom)) {
                    throw new InvalidArgumentException('Los productos registrados solo pueden capturarse en unidades físicas individuales durante Fase 1.');
                }

                $normalized[] = [
                    'kind' => 'registered',
                    'line_order' => $index + 1,
                    'inventory_product_id' => $productId,
                    'provisional_item_id' => null,
                    'inventory_location_id' => $locationId,
                    'capture_method' => $captureMethod,
                    'captured_quantity' => $qty,
                    'captured_unit_of_measure' => $uom,
                    'unit_conversion_factor' => 1,
                    'normalized_physical_quantity' => $qty,
                    'line_notes' => $this->nullableTrim($raw['line_notes'] ?? null),
                ];

                continue;
            }

            // Provisional or ambiguous pending line
            $provisionalId = ! empty($raw['provisional_item_id']) ? (int) $raw['provisional_item_id'] : null;
            $conversionFactor = isset($raw['unit_conversion_factor']) && $raw['unit_conversion_factor'] !== ''
                ? (int) $raw['unit_conversion_factor']
                : null;

            if (InventoryProvisionalItem::isIndividualUnit($uom)) {
                $conversionFactor = 1;
                $normalizedQty = $qty;
            } else {
                // BOX / PACK (H09): require explicit >= 2 conversion or explicitly leave unconverted (NULL)
                if ($conversionFactor !== null && $conversionFactor < 2) {
                    throw new InvalidArgumentException('La equivalencia de caja/paquete debe ser un entero mayor o igual a 2, o dejarse vacía si se desconoce.');
                }
                $normalizedQty = $conversionFactor !== null ? ($qty * $conversionFactor) : null;
            }

            $isAmbiguous = (bool) ($raw['is_ambiguous'] ?? false);

            $normalized[] = [
                'kind' => 'provisional',
                'line_order' => $index + 1,
                'inventory_product_id' => null,
                'provisional_item_id' => $provisionalId,
                'inventory_location_id' => $locationId,
                'capture_method' => $captureMethod,
                'captured_barcode' => $this->nullableTrim($raw['captured_barcode'] ?? $raw['barcode'] ?? null),
                'captured_sku' => $this->nullableTrim($raw['captured_sku'] ?? $raw['known_sku'] ?? null),
                'captured_name' => trim((string) ($raw['captured_name'] ?? $raw['name'] ?? '')),
                'captured_brand' => $this->nullableTrim($raw['captured_brand'] ?? $raw['brand'] ?? null),
                'captured_line' => $this->nullableTrim($raw['captured_line'] ?? $raw['line'] ?? null),
                'captured_tone' => $this->nullableTrim($raw['captured_tone'] ?? $raw['tone'] ?? null),
                'captured_presentation' => $this->nullableTrim($raw['captured_presentation'] ?? $raw['presentation'] ?? null),
                'captured_quantity' => $qty,
                'captured_unit_of_measure' => $uom,
                'unit_conversion_factor' => $conversionFactor,
                'normalized_physical_quantity' => $normalizedQty,
                'is_ambiguous' => $isAmbiguous,
                'line_notes' => $this->nullableTrim($raw['line_notes'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedItems
     */
    private function persistDraftItems(InventoryDispatch $dispatch, array $normalizedItems, User $actor): void
    {
        foreach ($normalizedItems as $row) {
            $locationId = $row['inventory_location_id'] ?? $dispatch->source_location_id;
            $locationCode = null;
            if ($locationId !== null) {
                $loc = InventoryLocation::query()->where('is_active', true)->findOrFail($locationId);
                $locationCode = (string) $loc->code;
            }

            if ($row['kind'] === 'registered') {
                $product = InventoryProduct::query()->findOrFail($row['inventory_product_id']);
                if ($product->isKit() || $product->isBundleOrKit()) {
                    throw new InvalidArgumentException("El producto '{$product->name}' es un kit o paquete; en Fase 1 captura únicamente piezas físicas individuales.");
                }

                $item = new InventoryDispatchItem;
                $item->forceFill([
                    'inventory_dispatch_id' => $dispatch->getKey(),
                    'line_order' => $row['line_order'],
                    'inventory_product_id' => $product->getKey(),
                    'provisional_item_id' => null,
                    'inventory_location_id' => $locationId,
                    'location_code_snapshot' => $locationCode,
                    'capture_method' => $row['capture_method'],
                    'captured_barcode' => $product->barcode,
                    'captured_sku' => $product->sku,
                    'captured_name' => $product->name,
                    'captured_brand' => $product->brand,
                    'captured_quantity' => $row['captured_quantity'],
                    'captured_unit_of_measure' => $row['captured_unit_of_measure'],
                    'unit_conversion_factor' => 1,
                    'normalized_physical_quantity' => $row['normalized_physical_quantity'],
                    'quantity_returned' => 0,
                    'line_notes' => $row['line_notes'],
                    'identification_status' => InventoryDispatchItem::ID_REGISTERED,
                    'inventory_apply_status' => InventoryDispatchItem::APPLY_PENDING,
                    'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
                ]);
                $item->save();

                continue;
            }

            // Provisional item
            $provisional = null;
            if ($row['provisional_item_id'] !== null) {
                $candidate = InventoryProvisionalItem::query()->findOrFail($row['provisional_item_id']);
                if ($candidate->status === InventoryProvisionalItem::STATUS_ARCHIVED) {
                    throw new InvalidArgumentException("La ficha provisional {$candidate->provisional_code} está archivada.");
                }
                if ($candidate->status === InventoryProvisionalItem::STATUS_MERGED && $candidate->canonical_provisional_id) {
                    $candidate = InventoryProvisionalItem::query()->findOrFail($candidate->canonical_provisional_id);
                }
                $provisional = $candidate;
            } else {
                if ($row['captured_name'] === '') {
                    throw new InvalidArgumentException('El nombre descriptivo es obligatorio para productos provisionales.');
                }

                $provisional = $this->provisionalService->createProvisional([
                    'barcode' => $row['captured_barcode'],
                    'known_sku' => $row['captured_sku'],
                    'name' => $row['captured_name'],
                    'brand' => $row['captured_brand'],
                    'line' => $row['captured_line'],
                    'tone' => $row['captured_tone'],
                    'presentation' => $row['captured_presentation'],
                    'unit_of_measure' => $row['captured_unit_of_measure'],
                    'default_units_per_container' => $row['unit_conversion_factor'],
                    'status' => $row['is_ambiguous']
                        ? InventoryProvisionalItem::STATUS_AMBIGUOUS_REVIEW
                        : InventoryProvisionalItem::STATUS_PENDING_LINK,
                ], $actor);
            }

            $item = new InventoryDispatchItem;
            $item->forceFill([
                'inventory_dispatch_id' => $dispatch->getKey(),
                'line_order' => $row['line_order'],
                'inventory_product_id' => $provisional->linked_inventory_product_id ?: null,
                'provisional_item_id' => $provisional->getKey(),
                'inventory_location_id' => $locationId,
                'location_code_snapshot' => $locationCode,
                'capture_method' => $row['capture_method'],
                'captured_barcode' => $row['captured_barcode'] ?? $provisional->barcode,
                'captured_sku' => $row['captured_sku'] ?? $provisional->known_sku,
                'captured_name' => $row['captured_name'] !== '' ? $row['captured_name'] : $provisional->name,
                'captured_brand' => $row['captured_brand'] ?? $provisional->brand,
                'captured_line' => $row['captured_line'] ?? $provisional->line,
                'captured_tone' => $row['captured_tone'] ?? $provisional->tone,
                'captured_presentation' => $row['captured_presentation'] ?? $provisional->presentation,
                'captured_quantity' => $row['captured_quantity'],
                'captured_unit_of_measure' => $row['captured_unit_of_measure'],
                'unit_conversion_factor' => $row['unit_conversion_factor'],
                'normalized_physical_quantity' => $row['normalized_physical_quantity'],
                'quantity_returned' => 0,
                'line_notes' => $row['line_notes'],
                'identification_status' => $provisional->linked_inventory_product_id
                    ? InventoryDispatchItem::ID_REGISTERED
                    : ($row['is_ambiguous']
                        ? InventoryDispatchItem::ID_AMBIGUOUS_PENDING
                        : InventoryDispatchItem::ID_PROVISIONAL),
                'inventory_apply_status' => InventoryDispatchItem::APPLY_PENDING,
                'meli_sync_status' => InventoryDispatchItem::MELI_NOT_ELIGIBLE,
            ]);
            $item->save();
        }
    }

    private function recalculateDraftTotals(InventoryDispatch $dispatch): void
    {
        $items = $dispatch->items()->get();
        $totalPhysical = 0;
        $unconverted = 0;

        foreach ($items as $item) {
            if ($item->normalized_physical_quantity !== null) {
                $totalPhysical += (int) $item->normalized_physical_quantity;
            } else {
                $unconverted++;
            }
        }

        $dispatch->forceFill([
            'total_lines_count' => $items->count(),
            'total_physical_units' => $totalPhysical,
            'unconverted_containers_count' => $unconverted,
        ])->save();
    }

    private function parseAndValidateEffectiveAt(mixed $value): Carbon
    {
        $nowUtc = now('UTC');
        if ($value === null || trim((string) $value) === '') {
            return $nowUtc;
        }

        $parsed = InventoryDateTime::toUtc($value instanceof \DateTimeInterface ? $value : (string) $value);
        if (! $parsed) {
            throw new InvalidArgumentException('La fecha efectiva de entrega no es válida.');
        }

        if ($parsed->gt($nowUtc)) {
            throw new InvalidArgumentException('La fecha efectiva de entrega no puede estar en el futuro.');
        }

        return $parsed;
    }

    private function isRetroactiveTimestamp(Carbon $effectiveAtUtc, Carbon $referenceNowUtc): bool
    {
        $toleranceSeconds = $this->guard->retroactiveToleranceHours() * 3600;

        return abs($referenceNowUtc->getTimestamp() - $effectiveAtUtc->getTimestamp()) > $toleranceSeconds;
    }

    private function assertRetroactivePermission(User $actor, bool $isRetroactive, ?string $reason): void
    {
        if (! $isRetroactive) {
            return;
        }

        if (! $this->policy->createRetroactive($actor)) {
            throw new AuthorizationException('Las salidas con más de 24 horas de antigüedad requieren autorización de Operaciones o Administración.');
        }

        if ($reason === null || trim($reason) === '') {
            throw new InvalidArgumentException('Debes indicar el motivo de la fecha efectiva retroactiva (> 24 horas).');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedItems
     */
    private function buildCreatePayloadHash(array $data, Carbon $effectiveAtUtc, array $normalizedItems): string
    {
        $canonical = [
            'destination_id' => (int) ($data['destination_id'] ?? 0),
            'source_location_id' => ! empty($data['source_location_id']) ? (int) $data['source_location_id'] : null,
            'operation_type' => (string) ($data['operation_type'] ?? ''),
            'effective_at' => $effectiveAtUtc->copy()->utc()->toIso8601String(),
            'received_by_name' => $this->nullableTrim($data['received_by_name'] ?? null),
            'notes' => $this->nullableTrim($data['notes'] ?? null),
            'retroactive_reason' => $this->nullableTrim($data['retroactive_reason'] ?? null),
            'items' => $normalizedItems,
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }

    private function generateNextDispatchNumber(Carbon $nowUtc): string
    {
        $datePrefix = $nowUtc->copy()->setTimezone(InventoryDateTime::OPERATING_TIMEZONE)->format('Ymd');
        $prefix = "SAL-{$datePrefix}-";

        $latest = InventoryDispatch::query()
            ->where('dispatch_number', 'like', "{$prefix}%")
            ->orderByDesc('dispatch_number')
            ->value('dispatch_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches) === 1) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%04d', $prefix, $seq);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : null;
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        int $dispatchId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        User $actor,
        ?string $reason,
        array $payload,
        Carbon $occurredAt,
        ?int $dispatchItemId = null,
    ): void {
        $event = new InventoryDispatchEvent;
        $event->forceFill([
            'inventory_dispatch_id' => $dispatchId,
            'inventory_dispatch_item_id' => $dispatchItemId,
            'provisional_item_id' => null,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor->getKey(),
            'reason' => $reason,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
            'created_at' => now('UTC'),
        ]);
        $event->save();
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
