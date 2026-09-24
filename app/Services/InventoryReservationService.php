<?php

namespace App\Services;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryReservationService
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryMovementService $movements,
    ) {}

    /**
     * @param array{
     *     inventory_product_id: int,
     *     inventory_location_id: int,
     *     quantity: int,
     *     source_type?: ?string,
     *     source_id?: ?int,
     *     reference?: ?string,
     *     external_key?: ?string,
     *     expires_at?: \DateTimeInterface|string|null,
     *     metadata?: ?array
     * } $data
     */
    public function create(array $data, ?User $user = null): InventoryReservation
    {
        return DB::transaction(function () use ($data, $user): InventoryReservation {
            $quantity = $data['quantity'] ?? null;
            if (is_string($quantity) && filter_var($quantity, FILTER_VALIDATE_INT) !== false) {
                $quantity = (int) $quantity;
            }
            if (! is_int($quantity) || $quantity <= 0) {
                throw new InvalidArgumentException('La cantidad reservada debe ser un entero mayor que cero.');
            }

            $product = InventoryProduct::query()
                ->lockForUpdate()
                ->find($data['inventory_product_id'] ?? null);
            if (! $product) {
                throw (new ModelNotFoundException)->setModel(InventoryProduct::class);
            }

            $locationId = $data['inventory_location_id'] ?? null;
            if ($locationId !== null) {
                $location = InventoryLocation::query()->find($locationId);
                if (! $location) {
                    throw (new ModelNotFoundException)->setModel(InventoryLocation::class);
                }
            }

            $externalKey = $data['external_key'] ?? null;
            if ($externalKey !== null) {
                $existing = InventoryReservation::query()
                    ->where('external_key', $externalKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $available = $locationId === null
                ? $this->stock->availableStock($product)
                : $this->stock->availableStockByLocation($product, (int) $locationId);
            if ($quantity > $available) {
                $locationLabel = $locationId === null ? 'producto' : (string) $location->code;
                throw new InventoryInsufficientStockException($available, $quantity, $locationLabel);
            }

            return InventoryReservation::create([
                'inventory_product_id' => $product->getKey(),
                'inventory_location_id' => $locationId,
                'quantity' => $quantity,
                'status' => InventoryReservation::ACTIVE,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'external_key' => $externalKey,
                'expires_at' => isset($data['expires_at']) && $data['expires_at'] !== ''
                    ? Carbon::parse($data['expires_at'])
                    : null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $data['created_by'] ?? $user?->getKey(),
            ]);
        });
    }

    public function release(InventoryReservation|int $reservation): InventoryReservation
    {
        return $this->transition($reservation, InventoryReservation::RELEASED, 'released_at');
    }

    public function cancel(InventoryReservation|int $reservation): InventoryReservation
    {
        return $this->transition($reservation, InventoryReservation::CANCELLED, 'released_at');
    }

    public function expire(InventoryReservation|int $reservation): InventoryReservation
    {
        return DB::transaction(function () use ($reservation): InventoryReservation {
            $locked = $this->lockReservation($reservation);
            $this->assertActive($locked);
            if (! $locked->expires_at || $locked->expires_at->isFuture()) {
                throw new InvalidArgumentException('La reserva todavía no ha vencido.');
            }

            $locked->forceFill([
                'status' => InventoryReservation::EXPIRED,
                'released_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * Fulfill a reservation and create its physical outbound movement atomically.
     *
     * @param  array{type?: string, reference?: ?string, reference_type?: ?string, reference_id?: ?int, notes?: ?string, external_key?: ?string}  $movementData
     */
    public function fulfill(
        InventoryReservation|int $reservation,
        array $movementData = [],
        ?User $user = null,
    ): InventoryReservation {
        return DB::transaction(function () use ($reservation, $movementData, $user): InventoryReservation {
            $locked = $this->lockReservation($reservation);
            $this->assertActive($locked);

            $locationId = $locked->inventory_location_id ?? ($movementData['inventory_location_id'] ?? null);
            if ($locationId === null) {
                throw new InvalidArgumentException('La reserva necesita una ubicación para cumplirse.');
            }
            if (! InventoryLocation::query()->find($locationId)) {
                throw (new ModelNotFoundException)->setModel(InventoryLocation::class);
            }

            $type = $movementData['type'] ?? InventoryMovement::SALE;
            if (! in_array($type, InventoryMovement::NEGATIVE_TYPES, true)) {
                throw new InvalidArgumentException('El movimiento de cumplimiento debe ser una salida.');
            }

            $locked->forceFill([
                'status' => InventoryReservation::FULFILLED,
                'fulfilled_at' => now(),
            ])->save();

            $movement = $this->movements->record([
                'inventory_product_id' => $locked->inventory_product_id,
                'inventory_location_id' => $locationId,
                'type' => $type,
                'quantity' => -$locked->quantity,
                'reference_type' => $movementData['reference_type'] ?? 'inventory_reservation',
                'reference_id' => $movementData['reference_id'] ?? $locked->getKey(),
                'reference' => $movementData['reference'] ?? $locked->reference,
                'notes' => $movementData['notes'] ?? null,
                'external_key' => $movementData['external_key'] ?? "reservation:{$locked->getKey()}:fulfill",
            ], $user);

            return $locked->fresh()->load('product', 'location', 'createdBy');
        });
    }

    private function transition(
        InventoryReservation|int $reservation,
        string $status,
        string $timestampColumn,
    ): InventoryReservation {
        return DB::transaction(function () use ($reservation, $status, $timestampColumn): InventoryReservation {
            $locked = $this->lockReservation($reservation);
            $this->assertActive($locked);
            $locked->forceFill([
                'status' => $status,
                $timestampColumn => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    private function lockReservation(InventoryReservation|int $reservation): InventoryReservation
    {
        $id = $reservation instanceof InventoryReservation ? $reservation->getKey() : $reservation;
        $locked = InventoryReservation::query()->lockForUpdate()->find($id);
        if (! $locked) {
            throw (new ModelNotFoundException)->setModel(InventoryReservation::class, [$id]);
        }

        return $locked;
    }

    private function assertActive(InventoryReservation $reservation): void
    {
        if ($reservation->status !== InventoryReservation::ACTIVE) {
            throw new InvalidArgumentException('La reserva ya está finalizada y no admite esta transición.');
        }
    }
}
