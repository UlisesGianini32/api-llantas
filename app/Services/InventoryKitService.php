<?php

namespace App\Services;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryKitComponent;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryKitService
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly InventoryReservationService $reservations,
    ) {}

    /**
     * @param  array{kit_product_id:int,quantity:int,source_type?:?string,source_id?:?int,reference?:?string,external_key?:?string,expires_at?:\DateTimeInterface|string|null,metadata?:?array}  $data
     */
    public function reserve(array $data, ?User $user = null): InventoryKitReservation
    {
        return DB::transaction(function () use ($data, $user): InventoryKitReservation {
            $quantity = $this->positiveInteger($data['quantity'] ?? null, 'La cantidad del kit debe ser un entero mayor que cero.');
            $kit = InventoryProduct::query()->lockForUpdate()->find($data['kit_product_id'] ?? null);
            if (! $kit) {
                throw (new ModelNotFoundException)->setModel(InventoryProduct::class);
            }
            if (! $kit->isKit()) {
                throw new InvalidArgumentException('El producto indicado no es un kit.');
            }
            if (! $kit->is_active) {
                throw new InvalidArgumentException('El kit está inactivo y no puede reservarse.');
            }

            $externalKey = $data['external_key'] ?? null;
            if ($externalKey !== null) {
                $existing = InventoryKitReservation::query()
                    ->where('external_key', $externalKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing->load('kit', 'componentReservations.product', 'componentReservations.location');
                }
            }

            $components = InventoryKitComponent::query()
                ->where('kit_product_id', $kit->getKey())
                ->orderBy('component_product_id')
                ->get();
            if ($components->isEmpty()) {
                throw new InvalidArgumentException('Kit sin componentes configurados.');
            }

            $componentIds = $components->pluck('component_product_id')->all();
            $products = InventoryProduct::query()
                ->whereIn('id', $componentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($components as $component) {
                $product = $products->get($component->component_product_id);
                if (! $product || ! $product->isSimple()) {
                    throw new InvalidArgumentException('Un kit no puede contener otro kit.');
                }
            }

            $parent = InventoryKitReservation::create([
                'kit_product_id' => $kit->getKey(),
                'quantity' => $quantity,
                'status' => InventoryKitReservation::ACTIVE,
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

            foreach ($components as $component) {
                $product = $products->get($component->component_product_id);
                $required = $quantity * (int) $component->quantity;
                foreach ($this->allocate($product, $required) as $allocation) {
                    $locationId = $allocation['inventory_location_id'];
                    $childKey = "kit-reservation:{$parent->getKey()}:component:{$product->getKey()}:location:{$locationId}";
                    $this->reservations->create([
                        'inventory_product_id' => $product->getKey(),
                        'inventory_location_id' => $locationId,
                        'quantity' => $allocation['quantity'],
                        'status' => InventoryReservation::ACTIVE,
                        'source_type' => InventoryKitReservation::SOURCE_TYPE,
                        'source_id' => $parent->getKey(),
                        'reference' => $parent->reference,
                        'external_key' => $childKey,
                        'expires_at' => $parent->expires_at,
                        'metadata' => [
                            'kit_product_id' => $kit->getKey(),
                            'kit_quantity' => $quantity,
                        ],
                    ], $user);
                }
            }

            return $parent->fresh()->load('kit', 'componentReservations.product', 'componentReservations.location');
        });
    }

    public function release(InventoryKitReservation|int $reservation): InventoryKitReservation
    {
        return $this->transition($reservation, InventoryKitReservation::RELEASED, 'released_at');
    }

    public function cancel(InventoryKitReservation|int $reservation): InventoryKitReservation
    {
        return $this->transition($reservation, InventoryKitReservation::CANCELLED, 'released_at');
    }

    public function expire(InventoryKitReservation|int $reservation): InventoryKitReservation
    {
        return DB::transaction(function () use ($reservation): InventoryKitReservation {
            $locked = $this->lockParent($reservation);
            $this->assertActive($locked);
            if (! $locked->expires_at || $locked->expires_at->isFuture()) {
                throw new InvalidArgumentException('La reserva todavía no ha vencido.');
            }

            $this->releaseChildren($locked, InventoryReservation::EXPIRED);
            $locked->forceFill(['status' => InventoryKitReservation::EXPIRED, 'released_at' => now()])->save();

            return $locked->fresh()->load('kit', 'componentReservations.product', 'componentReservations.location');
        });
    }

    /** @param array{reference?:?string,notes?:?string} $movementData */
    public function fulfill(InventoryKitReservation|int $reservation, array $movementData = [], ?User $user = null): InventoryKitReservation
    {
        return DB::transaction(function () use ($reservation, $movementData, $user): InventoryKitReservation {
            $locked = $this->lockParent($reservation);
            $this->assertActive($locked);
            $children = $this->children($locked)->get();
            if ($children->isEmpty()) {
                throw new InvalidArgumentException('La reserva del kit no tiene componentes reservados.');
            }

            foreach ($children as $child) {
                $this->reservations->fulfill($child, [
                    'reference_type' => InventoryKitReservation::SOURCE_TYPE,
                    'reference_id' => $locked->getKey(),
                    'reference' => $movementData['reference'] ?? $locked->reference,
                    'notes' => $movementData['notes'] ?? null,
                    'metadata' => [
                        'kit_product_id' => $locked->kit_product_id,
                        'kit_quantity' => $locked->quantity,
                    ],
                    'allow_kit_child' => true,
                    'external_key' => "kit-reservation:{$locked->getKey()}:child:{$child->getKey()}:fulfill",
                ], $user);
            }

            $locked->forceFill(['status' => InventoryKitReservation::FULFILLED, 'fulfilled_at' => now()])->save();

            return $locked->fresh()->load('kit', 'componentReservations.product', 'componentReservations.location');
        });
    }

    /** @param array<int, array{component_product_id:int,quantity:int}> $components */
    public function replaceComponents(InventoryProduct|int $kit, array $components): InventoryProduct
    {
        return DB::transaction(function () use ($kit, $components): InventoryProduct {
            $lockedKit = InventoryProduct::query()->lockForUpdate()->findOrFail($kit instanceof InventoryProduct ? $kit->getKey() : $kit);
            if (! $lockedKit->isKit()) {
                throw new InvalidArgumentException('El producto no es un kit.');
            }
            if (InventoryKitReservation::query()->active()->where('kit_product_id', $lockedKit->getKey())->exists()) {
                throw new InvalidArgumentException('No se puede modificar el kit mientras tenga reservas activas.');
            }

            $normalized = [];
            foreach ($components as $component) {
                $componentId = (int) ($component['component_product_id'] ?? 0);
                $quantity = $this->positiveInteger($component['quantity'] ?? null, 'La cantidad de cada componente debe ser mayor que cero.');
                if ($componentId === $lockedKit->getKey()) {
                    throw new InvalidArgumentException('Un kit no puede contenerse a sí mismo.');
                }
                if (isset($normalized[$componentId])) {
                    throw new InvalidArgumentException('No se puede repetir un componente dentro del kit.');
                }
                $product = InventoryProduct::query()->find($componentId);
                if (! $product) {
                    throw (new ModelNotFoundException)->setModel(InventoryProduct::class, [$componentId]);
                }
                if (! $product->isSimple()) {
                    throw new InvalidArgumentException('Un kit no puede contener otro kit.');
                }
                $normalized[$componentId] = $quantity;
            }

            $lockedKit->kitComponents()->delete();
            foreach ($normalized as $componentId => $quantity) {
                $lockedKit->kitComponents()->create([
                    'component_product_id' => $componentId,
                    'quantity' => $quantity,
                ]);
            }

            return $lockedKit->fresh()->load('kitComponents.component');
        });
    }

    /** @return array<int, array{inventory_location_id:int,quantity:int}> */
    private function allocate(InventoryProduct $product, int $required): array
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
        $locations = $locations->sortBy(function (InventoryLocation $location) use ($product): array {
            return [$location->getKey() === $product->primary_location_id ? 0 : 1, $location->sort_order === null ? 1 : 0, $location->sort_order ?? 0, $location->code];
        });
        $remaining = $required;
        $allocations = [];
        foreach ($locations as $location) {
            $available = max(0, $this->stock->availableStockByLocation($product, $location));
            if ($available === 0) {
                continue;
            }
            $take = min($remaining, $available);
            $allocations[] = ['inventory_location_id' => $location->getKey(), 'quantity' => $take];
            $remaining -= $take;
            if ($remaining === 0) {
                return $allocations;
            }
        }

        throw new InventoryInsufficientStockException(
            $required - $remaining,
            $required,
            $product->name,
        );
    }

    private function transition(InventoryKitReservation|int $reservation, string $status, string $timestampColumn): InventoryKitReservation
    {
        return DB::transaction(function () use ($reservation, $status, $timestampColumn): InventoryKitReservation {
            $locked = $this->lockParent($reservation);
            $this->assertActive($locked);
            $this->releaseChildren($locked, $status === InventoryKitReservation::CANCELLED ? InventoryReservation::CANCELLED : InventoryReservation::RELEASED);
            $locked->forceFill(['status' => $status, $timestampColumn => now()])->save();

            return $locked->fresh()->load('kit', 'componentReservations.product', 'componentReservations.location');
        });
    }

    private function releaseChildren(InventoryKitReservation $parent, string $status): void
    {
        $this->children($parent)->lockForUpdate()->get()->each(function (InventoryReservation $child) use ($status): void {
            if ($child->status === InventoryReservation::ACTIVE) {
                $child->forceFill([
                    'status' => $status,
                    'released_at' => now(),
                ])->save();
            }
        });
    }

    private function children(InventoryKitReservation $parent)
    {
        return InventoryReservation::query()
            ->where('source_type', InventoryKitReservation::SOURCE_TYPE)
            ->where('source_id', $parent->getKey())
            ->orderBy('inventory_product_id')
            ->orderBy('inventory_location_id');
    }

    private function lockParent(InventoryKitReservation|int $reservation): InventoryKitReservation
    {
        $id = $reservation instanceof InventoryKitReservation ? $reservation->getKey() : $reservation;
        $locked = InventoryKitReservation::query()->lockForUpdate()->find($id);
        if (! $locked) {
            throw (new ModelNotFoundException)->setModel(InventoryKitReservation::class, [$id]);
        }

        return $locked;
    }

    private function assertActive(InventoryKitReservation $reservation): void
    {
        if ($reservation->status !== InventoryKitReservation::ACTIVE) {
            throw new InvalidArgumentException('La reserva del kit ya está finalizada y no admite esta transición.');
        }
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
