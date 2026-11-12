<?php

namespace App\Services\Autopartes;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\AutomotiveInventoryBalance;
use App\Models\AutomotiveInventoryMovement;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesConnectionGuard;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AutomotiveMovementService
{
    private const ALLOWED_REFERENCE_TYPES = [
        'automotive_receipt',
        'automotive_adjustment',
        'automotive_pos_sale',
        'automotive_pos_cancel',
        'automotive_pos_return',
    ];

    /**
     * Record a single immutable inventory movement on the dedicated 'autopartes' connection.
     *
     * @param array{
     *     branch_id?: ?int,
     *     automotive_part_id: int,
     *     automotive_location_id: int,
     *     type: string,
     *     quantity: int,
     *     reference_type?: ?string,
     *     reference_id?: ?int,
     *     reference?: ?string,
     *     notes?: ?string,
     *     metadata?: ?array<string, mixed>,
     *     occurred_at?: \DateTimeInterface|string|null,
     *     external_key?: ?string
     * } $data
     */
    public function record(array $data, ?User $actor = null): AutomotiveInventoryMovement
    {
        return $this->recordBatch([$data], $actor)->firstOrFail();
    }

    /**
     * Record one or more movements atomically using deterministic lock ordering
     * (part_id ASC, location_id ASC) under InnoDB REPEATABLE READ without pre-lock reads.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return Collection<int, AutomotiveInventoryMovement>
     */
    public function recordBatch(array $entries, ?User $actor = null): Collection
    {
        if ($entries === []) {
            return collect();
        }

        return AutopartesModel::transaction(function ($conn) use ($entries, $actor): Collection {
            AutopartesConnectionGuard::assertAutopartesTransactionConnection($conn, true);

            // 1. Validate inputs in PHP before opening any balance snapshots.
            $normalized = [];
            $partIds = [];
            $locationIds = [];
            $pairs = [];

            foreach ($entries as $idx => $entry) {
                $type = (string) ($entry['type'] ?? '');
                $quantity = $entry['quantity'] ?? null;
                $this->validateTypeAndQuantity($type, $quantity);

                $partId = (int) ($entry['automotive_part_id'] ?? 0);
                $locationId = (int) ($entry['automotive_location_id'] ?? 0);
                if ($partId <= 0 || $locationId <= 0) {
                    throw new InvalidArgumentException('Se requiere automotive_part_id y automotive_location_id válidos.');
                }

                $referenceType = $entry['reference_type'] ?? null;
                if ($referenceType !== null && ! in_array($referenceType, self::ALLOWED_REFERENCE_TYPES, true)) {
                    throw new InvalidArgumentException("reference_type '{$referenceType}' no autorizado para Autopartes.");
                }

                $externalKey = isset($entry['external_key']) && trim((string) $entry['external_key']) !== ''
                    ? trim((string) $entry['external_key'])
                    : null;

                $normalized[$idx] = [
                    'branch_id' => isset($entry['branch_id']) ? (int) $entry['branch_id'] : null,
                    'automotive_part_id' => $partId,
                    'automotive_location_id' => $locationId,
                    'type' => $type,
                    'quantity' => (int) $quantity,
                    'reference_type' => $referenceType,
                    'reference_id' => isset($entry['reference_id']) ? (int) $entry['reference_id'] : null,
                    'reference' => $entry['reference'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    'metadata' => is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [],
                    'occurred_at' => $entry['occurred_at'] ?? null,
                    'external_key' => $externalKey,
                ];

                $partIds[$partId] = $partId;
                $locationIds[$locationId] = $locationId;
                $pairKey = "{$partId}:{$locationId}";
                $pairs[$pairKey] = [
                    'part_id' => $partId,
                    'location_id' => $locationId,
                ];
            }

            // 2. Acquire deterministic locks FIRST on master rows (part_id ASC, location_id ASC)
            // so MariaDB InnoDB REPEATABLE READ never evaluates stale pre-lock snapshots.
            ksort($partIds, SORT_NUMERIC);
            ksort($locationIds, SORT_NUMERIC);

            $lockedParts = [];
            foreach ($partIds as $partId) {
                $part = AutomotivePart::query()->lockForUpdate()->find($partId);
                if (! $part) {
                    throw (new ModelNotFoundException)->setModel(AutomotivePart::class);
                }
                $lockedParts[$partId] = $part;
            }

            $lockedLocations = [];
            foreach ($locationIds as $locationId) {
                $location = AutomotiveLocation::query()->lockForUpdate()->find($locationId);
                if (! $location) {
                    throw (new ModelNotFoundException)->setModel(AutomotiveLocation::class);
                }
                $lockedLocations[$locationId] = $location;
            }

            foreach ($normalized as $entry) {
                $location = $lockedLocations[$entry['automotive_location_id']];
                if ($entry['branch_id'] !== null && (int) $entry['branch_id'] !== (int) $location->branch_id) {
                    throw new InvalidArgumentException(
                        "La ubicación '{$location->code}' pertenece a la sucursal #{$location->branch_id} y no coincide con #{$entry['branch_id']}."
                    );
                }
                $metaBranchId = $entry['metadata']['automotive_branch_id'] ?? null;
                if ($metaBranchId !== null && (int) $metaBranchId !== (int) $location->branch_id) {
                    throw new InvalidArgumentException('La metadata automotive_branch_id no coincide con la ubicación autorizada.');
                }
            }

            // 3. Check idempotency keys cleanly without FOR UPDATE gap-locks across distinct keys.
            $externalKeys = array_values(array_filter(array_column($normalized, 'external_key')));
            $existingByKey = [];
            if ($externalKeys !== []) {
                $existingRows = AutomotiveInventoryMovement::query()
                    ->whereIn('external_key', $externalKeys)
                    ->get();
                foreach ($existingRows as $existing) {
                    $existingByKey[(string) $existing->external_key] = $existing;
                }
            }

            // Filter pending entries (skip those already committed via external_key).
            $pendingEntries = [];
            $positivePairs = [];
            foreach ($normalized as $idx => $entry) {
                $key = $entry['external_key'];
                if ($key !== null && isset($existingByKey[$key])) {
                    continue;
                }
                $pendingEntries[$idx] = $entry;
                if ($entry['quantity'] > 0) {
                    $positivePairs["{$entry['automotive_part_id']}:{$entry['automotive_location_id']}"] = true;
                }
            }

            if ($pendingEntries === []) {
                $resolved = collect();
                foreach ($normalized as $entry) {
                    $resolved->push($existingByKey[$entry['external_key']]);
                }

                return $resolved;
            }

            // 4. Sort and lock target balance rows in strict ascending (part_id, location_id) order.
            $sortedPairs = array_values($pairs);
            usort($sortedPairs, static function (array $a, array $b): int {
                return [$a['part_id'], $a['location_id']] <=> [$b['part_id'], $b['location_id']];
            });

            $lockedBalances = [];
            $now = now();
            foreach ($sortedPairs as $pair) {
                $partId = $pair['part_id'];
                $locationId = $pair['location_id'];
                $pairKey = "{$partId}:{$locationId}";

                $balance = AutomotiveInventoryBalance::query()
                    ->where('automotive_part_id', $partId)
                    ->where('automotive_location_id', $locationId)
                    ->lockForUpdate()
                    ->first();

                // Initialize missing balance row safely on inflows while holding part_id X-lock.
                if (! $balance && isset($positivePairs[$pairKey])) {
                    $branchId = (int) $lockedLocations[$locationId]->branch_id;
                    $conn->table('automotive_inventory_balances')->insertOrIgnore([
                        'branch_id' => $branchId,
                        'automotive_part_id' => $partId,
                        'automotive_location_id' => $locationId,
                        'physical_quantity' => 0,
                        'reserved_quantity' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $balance = AutomotiveInventoryBalance::query()
                        ->where('automotive_part_id', $partId)
                        ->where('automotive_location_id', $locationId)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $lockedBalances[$pairKey] = $balance;
            }

            // 5. Apply each movement sequentially under lock, guaranteeing zero negative balances.
            $resultsByIdx = [];
            foreach ($normalized as $idx => $entry) {
                $extKey = $entry['external_key'];
                if ($extKey !== null && isset($existingByKey[$extKey])) {
                    $resultsByIdx[$idx] = $existingByKey[$extKey];

                    continue;
                }

                $partId = $entry['automotive_part_id'];
                $locationId = $entry['automotive_location_id'];
                $location = $lockedLocations[$locationId];
                $pairKey = "{$partId}:{$locationId}";
                /** @var AutomotiveInventoryBalance|null $balance */
                $balance = $lockedBalances[$pairKey] ?? null;

                $stockBefore = $balance ? (int) $balance->physical_quantity : 0;
                $reserved = $balance ? (int) $balance->reserved_quantity : 0;
                $available = max(0, $stockBefore - $reserved);
                $delta = (int) $entry['quantity'];
                $stockAfter = $stockBefore + $delta;

                if ($delta < 0 && ($available + $delta) < 0) {
                    throw new InventoryInsufficientStockException(
                        $available,
                        abs($delta),
                        (string) $location->code,
                    );
                }

                if ($stockAfter < $reserved || $stockAfter < 0) {
                    throw new InventoryInsufficientStockException(
                        $available,
                        abs($delta),
                        (string) $location->code,
                    );
                }

                try {
                    $movement = AutomotiveInventoryMovement::create([
                        'branch_id' => (int) $location->branch_id,
                        'automotive_part_id' => $partId,
                        'automotive_location_id' => $locationId,
                        'type' => $entry['type'],
                        'quantity' => $delta,
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockAfter,
                        'reference_type' => $entry['reference_type'],
                        'reference_id' => $entry['reference_id'],
                        'reference' => $entry['reference'],
                        'notes' => $entry['notes'],
                        'metadata' => array_merge($entry['metadata'], [
                            'automotive_branch_id' => (int) $location->branch_id,
                        ]),
                        'external_key' => $extKey,
                        'created_by' => $actor?->getKey(),
                        'occurred_at' => $entry['occurred_at'] !== null
                            ? Carbon::parse($entry['occurred_at'])
                            : now(),
                    ]);
                } catch (QueryException $e) {
                    if ($extKey !== null && $this->isDuplicateKeyException($e)) {
                        $existing = AutomotiveInventoryMovement::query()
                            ->where('external_key', $extKey)
                            ->first();
                        if ($existing) {
                            $existingByKey[$extKey] = $existing;
                            $resultsByIdx[$idx] = $existing;

                            continue;
                        }
                    }

                    throw $e;
                }

                if ($balance) {
                    $balance->physical_quantity = $stockAfter;
                    $balance->save();
                }

                if ($extKey !== null) {
                    $existingByKey[$extKey] = $movement;
                }
                $resultsByIdx[$idx] = $movement;
            }

            ksort($resultsByIdx, SORT_NUMERIC);

            return collect(array_values($resultsByIdx));
        });
    }

    private function validateTypeAndQuantity(string $type, mixed $quantity): void
    {
        if (! in_array($type, AutomotiveInventoryMovement::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de movimiento inválido para Autopartes.');
        }

        if (! is_int($quantity) && ! (is_string($quantity) && preg_match('/^-?\d+$/', $quantity))) {
            throw new InvalidArgumentException('La cantidad del movimiento debe ser un entero exacto.');
        }

        $intQty = (int) $quantity;
        if ($intQty === 0) {
            throw new InvalidArgumentException('La cantidad del movimiento de inventario no puede ser cero.');
        }

        $mustBePositive = in_array($type, [
            AutomotiveInventoryMovement::RECEIPT,
            AutomotiveInventoryMovement::ADJUSTMENT_IN,
            AutomotiveInventoryMovement::RETURN,
            AutomotiveInventoryMovement::CANCEL,
        ], true);

        if ($mustBePositive && $intQty < 0) {
            throw new InvalidArgumentException("El tipo {$type} exige cantidad positiva.");
        }

        $mustBeNegative = in_array($type, [
            AutomotiveInventoryMovement::ADJUSTMENT_OUT,
            AutomotiveInventoryMovement::SALE,
        ], true);

        if ($mustBeNegative && $intQty > 0) {
            throw new InvalidArgumentException("El tipo {$type} exige cantidad negativa.");
        }
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19;
    }
}
