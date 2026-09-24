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

class InventoryMovementService
{
    /**
     * Record a movement with its already-signed quantity.
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
     *     external_key?: ?string
     * } $data
     */
    public function record(array $data, ?User $user = null): InventoryMovement
    {
        return DB::transaction(function () use ($data, $user): InventoryMovement {
            $type = (string) ($data['type'] ?? '');
            $quantity = $data['quantity'] ?? null;

            $this->validateTypeAndQuantity($type, $quantity);

            $product = InventoryProduct::query()
                ->lockForUpdate()
                ->find($data['inventory_product_id'] ?? null);
            if (! $product) {
                throw (new ModelNotFoundException)->setModel(InventoryProduct::class);
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

    /**
     * Record a manual movement from a positive magnitude.
     */
    public function recordManual(array $data, ?User $user = null): InventoryMovement
    {
        $type = (string) ($data['type'] ?? '');
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

        return $this->record($data, $user);
    }

    private function validateTypeAndQuantity(string $type, mixed $quantity): void
    {
        if (! in_array($type, InventoryMovement::types(), true)) {
            throw new InvalidArgumentException('Tipo de movimiento inválido.');
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
