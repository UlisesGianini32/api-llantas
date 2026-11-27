<?php

namespace App\Services\Inventory\Dispatches;

use App\Exceptions\WarehouseDispatchConflictException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchEvent;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProvisionalItemLinkService
{
    public function __construct(
        private readonly WarehouseDispatchFeatureGuard $guard,
        private readonly WarehouseDispatchCutoverEvaluator $cutoverEvaluator,
        private readonly DeadlockRetryRunner $deadlockRetry,
    ) {}

    public function createProvisional(array $data, User $actor): InventoryProvisionalItem
    {
        $this->guard->assertWritable();

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('El nombre del producto provisional es obligatorio.');
        }

        $uom = strtoupper(trim((string) ($data['unit_of_measure'] ?? InventoryProvisionalItem::UOM_UNIT)));
        if (! in_array($uom, InventoryProvisionalItem::UNITS_OF_MEASURE, true)) {
            throw new InvalidArgumentException('Unidad de medida inválida para producto provisional.');
        }

        $defaultConversion = isset($data['default_units_per_container']) && $data['default_units_per_container'] !== ''
            ? (int) $data['default_units_per_container']
            : null;
        if (InventoryProvisionalItem::isIndividualUnit($uom)) {
            $defaultConversion = 1;
        } elseif ($defaultConversion !== null && $defaultConversion < 2) {
            throw new InvalidArgumentException('La equivalencia de una caja o paquete debe ser un entero mayor o igual a 2.');
        }

        $status = (string) ($data['status'] ?? InventoryProvisionalItem::STATUS_PENDING_LINK);
        if (! in_array($status, [
            InventoryProvisionalItem::STATUS_PENDING_LINK,
            InventoryProvisionalItem::STATUS_AMBIGUOUS_REVIEW,
        ], true)) {
            $status = InventoryProvisionalItem::STATUS_PENDING_LINK;
        }

        return $this->deadlockRetry->run(function () use ($data, $actor, $name, $uom, $defaultConversion, $status): InventoryProvisionalItem {
            $attempts = 0;

            while (true) {
                $attempts++;

                try {
                    return DB::transaction(function () use ($data, $actor, $name, $uom, $defaultConversion, $status): InventoryProvisionalItem {
                        $nextCode = $this->generateNextProvisionalCode();
                        $now = now('UTC');

                        $item = new InventoryProvisionalItem;
                        $item->forceFill([
                            'provisional_code' => $nextCode,
                            'barcode' => $this->nullableTrim($data['barcode'] ?? null),
                            'known_sku' => $this->nullableTrim($data['known_sku'] ?? null),
                            'name' => $name,
                            'brand' => $this->nullableTrim($data['brand'] ?? null),
                            'line' => $this->nullableTrim($data['line'] ?? null),
                            'tone' => $this->nullableTrim($data['tone'] ?? null),
                            'presentation' => $this->nullableTrim($data['presentation'] ?? null),
                            'unit_of_measure' => $uom,
                            'default_units_per_container' => $defaultConversion,
                            'notes' => $this->nullableTrim($data['notes'] ?? null),
                            'status' => $status,
                            'created_by' => $actor->getKey(),
                        ]);
                        $item->save();

                        // Root canonical points to self for O(1) resolution and cycle-free invariants.
                        $item->forceFill(['canonical_provisional_id' => $item->getKey()])->save();

                        $this->recordEvent(
                            provisionalItemId: (int) $item->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_PROVISIONAL_CREATED,
                            fromStatus: null,
                            toStatus: $status,
                            actor: $actor,
                            reason: null,
                            payload: [
                                'provisional_code' => $item->provisional_code,
                                'unit_of_measure' => $item->unit_of_measure,
                            ],
                            occurredAt: $now,
                        );

                        return $item->fresh();
                    });
                } catch (QueryException $exception) {
                    if ($attempts >= 5 || ! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }
                    usleep(random_int(10, 35) * 1000);
                }
            }
        });
    }

    /**
     * Link a canonical provisional item to an existing SIMPLE master InventoryProduct.
     * NEVER creates InventoryMovement rows and NEVER dispatches Mercado Libre syncs.
     * Preserves original provisional_item_id and captured_* snapshots on all dispatch items.
     */
    public function linkToMasterProduct(
        InventoryProvisionalItem $provisional,
        InventoryProduct $product,
        User $actor,
        ?string $notes = null,
    ): InventoryProvisionalItem {
        $this->guard->assertWritable();

        if ($product->isKit() || $product->isBundleOrKit()) {
            throw new InvalidArgumentException('No se puede vincular un producto provisional a un kit o paquete en Fase 1.');
        }

        return $this->deadlockRetry->run(function () use ($provisional, $product, $actor, $notes): InventoryProvisionalItem {
            return DB::transaction(function () use ($provisional, $product, $actor, $notes): InventoryProvisionalItem {
                // Consistent lock order: lock target provisional root & any merged members in id ASC, then master product.
                $locked = InventoryProvisionalItem::query()
                    ->lockForUpdate()
                    ->findOrFail($provisional->getKey());

                if ($locked->status === InventoryProvisionalItem::STATUS_ARCHIVED) {
                    throw new WarehouseDispatchConflictException('No se puede vincular una ficha provisional archivada.');
                }

                if ($locked->status === InventoryProvisionalItem::STATUS_MERGED) {
                    throw new WarehouseDispatchConflictException('Esta ficha provisional fue fusionada; vincula la ficha canónica destino.');
                }

                if ($locked->status === InventoryProvisionalItem::STATUS_LINKED) {
                    if ((int) $locked->linked_inventory_product_id === (int) $product->getKey()) {
                        return $locked->fresh();
                    }

                    throw new WarehouseDispatchConflictException('Esta ficha provisional ya está vinculada a otro producto maestro.');
                }

                $memberIds = InventoryProvisionalItem::query()
                    ->where('canonical_provisional_id', $locked->getKey())
                    ->orWhere('id', $locked->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $master = InventoryProduct::query()
                    ->lockForUpdate()
                    ->findOrFail($product->getKey());

                if ($master->isKit() || $master->isBundleOrKit()) {
                    throw new InvalidArgumentException('No se puede vincular un producto provisional a un kit o paquete en Fase 1.');
                }

                $now = now('UTC');
                $fromStatus = (string) $locked->status;

                $locked->forceFill([
                    'status' => InventoryProvisionalItem::STATUS_LINKED,
                    'linked_inventory_product_id' => $master->getKey(),
                    'linked_at' => $now,
                    'linked_by_user_id' => $actor->getKey(),
                    'link_notes' => $this->nullableTrim($notes),
                ])->save();

                // Update historical dispatch items belonging to this canonical provisional identity,
                // preserving provisional_item_id and captured_* snapshots. ZERO inventory movements created.
                $items = InventoryDispatchItem::query()
                    ->with('dispatch')
                    ->whereIn('provisional_item_id', $memberIds)
                    ->whereNull('inventory_product_id')
                    ->orderBy('inventory_dispatch_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $linkedItemsCount = 0;
                foreach ($items as $item) {
                    $dispatch = $item->dispatch;
                    if (! $dispatch) {
                        continue;
                    }

                    if ($dispatch->status === InventoryDispatch::STATUS_DRAFT) {
                        $item->forceFill([
                            'inventory_product_id' => $master->getKey(),
                            'identification_status' => InventoryDispatchItem::ID_REGISTERED,
                            'linked_at' => $now,
                            'linked_by_user_id' => $actor->getKey(),
                        ])->save();
                        $linkedItemsCount++;

                        continue;
                    }

                    if ($dispatch->status === InventoryDispatch::STATUS_CONFIRMED) {
                        $cutover = $this->cutoverEvaluator->evaluate(
                            (int) $master->getKey(),
                            $item->inventory_location_id ? (int) $item->inventory_location_id : null,
                            $dispatch->effective_at->copy()->utc(),
                        );

                        $applyStatus = $cutover['decision'] === WarehouseDispatchCutoverEvaluator::DECISION_SKIPPED_PRE_COUNT
                            ? InventoryDispatchItem::APPLY_SKIPPED_PRE_PHYSICAL_COUNT
                            : InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION;

                        $note = $cutover['decision'] === WarehouseDispatchCutoverEvaluator::DECISION_SKIPPED_PRE_COUNT
                            ? $cutover['reason']
                            : 'Vinculado posteriormente al catálogo maestro; no se descontó inventario automáticamente y queda sujeto a conciliación supervisada.';

                        $item->forceFill([
                            'inventory_product_id' => $master->getKey(),
                            'identification_status' => InventoryDispatchItem::ID_LINKED_POST_DISPATCH,
                            'linked_at' => $now,
                            'linked_by_user_id' => $actor->getKey(),
                            'inventory_apply_status' => $applyStatus,
                            'cutover_evaluated_at' => $now,
                            'cutover_reference_type' => $cutover['reference_type'],
                            'cutover_reference_id' => $cutover['reference_id'],
                            'cutover_boundary_at' => $cutover['boundary_at'],
                            'inventory_resolution_notes' => $note,
                        ])->save();

                        $this->recordEvent(
                            provisionalItemId: (int) $locked->getKey(),
                            eventType: InventoryDispatchEvent::EVENT_PROVISIONAL_LINKED,
                            fromStatus: InventoryDispatchItem::APPLY_SKIPPED_PROVISIONAL,
                            toStatus: $applyStatus,
                            actor: $actor,
                            reason: $this->nullableTrim($notes),
                            payload: [
                                'linked_inventory_product_id' => (int) $master->getKey(),
                                'linked_sku' => (string) $master->sku,
                                'ledger_movements_created' => 0,
                            ],
                            occurredAt: $now,
                            dispatchId: (int) $dispatch->getKey(),
                            dispatchItemId: (int) $item->getKey(),
                        );

                        $linkedItemsCount++;
                    }
                }

                $this->recordEvent(
                    provisionalItemId: (int) $locked->getKey(),
                    eventType: InventoryDispatchEvent::EVENT_PROVISIONAL_LINKED,
                    fromStatus: $fromStatus,
                    toStatus: InventoryProvisionalItem::STATUS_LINKED,
                    actor: $actor,
                    reason: $this->nullableTrim($notes),
                    payload: [
                        'linked_inventory_product_id' => (int) $master->getKey(),
                        'linked_sku' => (string) $master->sku,
                        'affected_dispatch_items' => $linkedItemsCount,
                        'ledger_movements_created' => 0,
                    ],
                    occurredAt: $now,
                );

                return $locked->fresh('linkedProduct');
            });
        });
    }

    /**
     * Merge $source provisional item into $target canonical provisional item.
     * Preserves inventory_dispatch_items.provisional_item_id immutably (H08).
     * Acquires locks in ascending ID order to prevent cross-merge deadlocks.
     */
    public function mergeProvisionalItems(
        InventoryProvisionalItem $source,
        InventoryProvisionalItem $target,
        User $actor,
        string $reason,
    ): InventoryProvisionalItem {
        $this->guard->assertWritable();

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new InvalidArgumentException('El motivo de la fusión es obligatorio.');
        }

        if ((int) $source->getKey() === (int) $target->getKey()) {
            throw new WarehouseDispatchConflictException('No se puede fusionar una ficha provisional consigo misma.');
        }

        return $this->deadlockRetry->run(function () use ($source, $target, $actor, $trimmedReason): InventoryProvisionalItem {
            return DB::transaction(function () use ($source, $target, $actor, $trimmedReason): InventoryProvisionalItem {
                $idsToLock = [(int) $source->getKey(), (int) $target->getKey()];
                sort($idsToLock, SORT_NUMERIC);

                $lockedMap = InventoryProvisionalItem::query()
                    ->whereIn('id', $idsToLock)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                /** @var InventoryProvisionalItem|null $lockedSource */
                $lockedSource = $lockedMap->get((int) $source->getKey());
                /** @var InventoryProvisionalItem|null $lockedTarget */
                $lockedTarget = $lockedMap->get((int) $target->getKey());

                if (! $lockedSource || ! $lockedTarget) {
                    throw new WarehouseDispatchConflictException('Alguna de las fichas provisionales no existe.');
                }

                if ($lockedSource->status === InventoryProvisionalItem::STATUS_ARCHIVED
                    || $lockedTarget->status === InventoryProvisionalItem::STATUS_ARCHIVED) {
                    throw new WarehouseDispatchConflictException('No se pueden fusionar fichas provisionales archivadas.');
                }

                if ($lockedSource->status === InventoryProvisionalItem::STATUS_MERGED) {
                    throw new WarehouseDispatchConflictException('La ficha origen ya fue fusionada previamente.');
                }

                if ($lockedTarget->status === InventoryProvisionalItem::STATUS_MERGED) {
                    throw new WarehouseDispatchConflictException('La ficha destino ya fue fusionada; selecciona su ficha canónica raíz.');
                }

                // Prevent cycles across the canonical chain
                $canonicalTargetId = $lockedTarget->resolvedCanonicalId();
                if ($canonicalTargetId === (int) $lockedSource->getKey()
                    || (int) $lockedTarget->merged_into_provisional_id === (int) $lockedSource->getKey()) {
                    throw new WarehouseDispatchConflictException('Fusión rechazada: generaría un ciclo en la cadena canónica.');
                }

                // Prevent merging items linked to different master products
                if ($lockedSource->linked_inventory_product_id !== null
                    && $lockedTarget->linked_inventory_product_id !== null
                    && (int) $lockedSource->linked_inventory_product_id !== (int) $lockedTarget->linked_inventory_product_id) {
                    throw new WarehouseDispatchConflictException('No se pueden fusionar fichas provisionales vinculadas a productos maestros diferentes.');
                }

                if ($lockedSource->linked_inventory_product_id !== null && $lockedTarget->linked_inventory_product_id === null) {
                    throw new WarehouseDispatchConflictException('La ficha origen ya está vinculada a un producto maestro; fusiona la ficha sin vincular hacia la ficha vinculada.');
                }

                $this->assertCompatibleAttributes($lockedSource, $lockedTarget);

                // Lock any prior children of source (if any existed) in deterministic id order and flatten canonical pointer
                $children = InventoryProvisionalItem::query()
                    ->where('canonical_provisional_id', $lockedSource->getKey())
                    ->where('id', '!=', $lockedSource->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($children as $child) {
                    if ((int) $child->getKey() === $canonicalTargetId) {
                        throw new WarehouseDispatchConflictException('Fusión rechazada: conflicto cíclico en cadena canónica.');
                    }
                    $child->forceFill(['canonical_provisional_id' => $canonicalTargetId])->save();
                }

                $now = now('UTC');
                $fromStatus = (string) $lockedSource->status;

                $lockedSource->forceFill([
                    'status' => InventoryProvisionalItem::STATUS_MERGED,
                    'merged_into_provisional_id' => $lockedTarget->getKey(),
                    'canonical_provisional_id' => $canonicalTargetId,
                    'merged_at' => $now,
                    'merged_by_user_id' => $actor->getKey(),
                    'merge_reason' => $trimmedReason,
                ])->save();

                // Note (H08): We intentionally DO NOT rewrite inventory_dispatch_items.provisional_item_id.
                // Historical dispatch items preserve the exact original capture identity while reports/links
                // resolve canonical identity via canonical_provisional_id.

                $this->recordEvent(
                    provisionalItemId: (int) $lockedSource->getKey(),
                    eventType: InventoryDispatchEvent::EVENT_PROVISIONAL_MERGED,
                    fromStatus: $fromStatus,
                    toStatus: InventoryProvisionalItem::STATUS_MERGED,
                    actor: $actor,
                    reason: $trimmedReason,
                    payload: [
                        'source_provisional_id' => (int) $lockedSource->getKey(),
                        'source_code' => $lockedSource->provisional_code,
                        'target_provisional_id' => (int) $lockedTarget->getKey(),
                        'target_code' => $lockedTarget->provisional_code,
                        'canonical_provisional_id' => $canonicalTargetId,
                        'ledger_movements_created' => 0,
                    ],
                    occurredAt: $now,
                );

                return $lockedTarget->fresh();
            });
        });
    }

    private function assertCompatibleAttributes(InventoryProvisionalItem $source, InventoryProvisionalItem $target): void
    {
        $sourceTone = $this->normalizeTone($source->tone);
        $targetTone = $this->normalizeTone($target->tone);
        if ($sourceTone !== null && $targetTone !== null && $sourceTone !== $targetTone) {
            throw new WarehouseDispatchConflictException(
                "No se pueden fusionar fichas con tonos incompatibles ('{$source->tone}' vs '{$target->tone}')."
            );
        }

        $sourcePres = $this->normalizeSimpleText($source->presentation);
        $targetPres = $this->normalizeSimpleText($target->presentation);
        if ($sourcePres !== null && $targetPres !== null && $sourcePres !== $targetPres) {
            throw new WarehouseDispatchConflictException(
                "No se pueden fusionar fichas con presentaciones incompatibles ('{$source->presentation}' vs '{$target->presentation}')."
            );
        }

        $sourceBrand = $this->normalizeSimpleText($source->brand);
        $targetBrand = $this->normalizeSimpleText($target->brand);
        if ($sourceBrand !== null && $targetBrand !== null && $sourceBrand !== $targetBrand) {
            throw new WarehouseDispatchConflictException(
                "No se pueden fusionar fichas de marcas distintas ('{$source->brand}' vs '{$target->brand}')."
            );
        }
    }

    private function normalizeTone(?string $tone): ?string
    {
        $clean = trim((string) ($tone ?? ''));
        if ($clean === '') {
            return null;
        }

        return strtoupper(str_replace('/', '-', preg_replace('/\s+/', '', $clean) ?? $clean));
    }

    private function normalizeSimpleText(?string $value): ?string
    {
        $clean = trim((string) ($value ?? ''));
        if ($clean === '') {
            return null;
        }

        return mb_strtolower(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }

    private function generateNextProvisionalCode(): string
    {
        $latest = InventoryProvisionalItem::query()
            ->where('provisional_code', 'like', 'PROV-%')
            ->orderByDesc('id')
            ->value('provisional_code');

        $next = 1;
        if (is_string($latest) && preg_match('/^PROV-(\d+)$/', $latest, $matches) === 1) {
            $next = ((int) $matches[1]) + 1;
        } else {
            $next = ((int) InventoryProvisionalItem::query()->max('id')) + 1;
        }

        return sprintf('PROV-%06d', $next);
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
        int $provisionalItemId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        User $actor,
        ?string $reason,
        array $payload,
        \Carbon\Carbon $occurredAt,
        ?int $dispatchId = null,
        ?int $dispatchItemId = null,
    ): void {
        $event = new InventoryDispatchEvent;
        $event->forceFill([
            'inventory_dispatch_id' => $dispatchId,
            'inventory_dispatch_item_id' => $dispatchItemId,
            'provisional_item_id' => $provisionalItemId,
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
