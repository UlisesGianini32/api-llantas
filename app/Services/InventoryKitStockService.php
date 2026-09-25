<?php

namespace App\Services;

use App\Models\InventoryKitComponent;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use Illuminate\Support\Collection;

class InventoryKitStockService
{
    public function __construct(private readonly InventoryStockService $stock) {}

    public function physicalStock(InventoryProduct|int $kit): int
    {
        $kit = $this->kit($kit);
        $components = $kit->relationLoaded('kitComponents')
            ? $kit->kitComponents
            : $kit->kitComponents()->with('component')->get();

        return $this->capacity($components, false);
    }

    public function availableStock(InventoryProduct|int $kit): int
    {
        $kit = $this->kit($kit);
        $components = $kit->relationLoaded('kitComponents')
            ? $kit->kitComponents
            : $kit->kitComponents()->with('component')->get();

        return $this->capacity($components, true);
    }

    /** @return array<int, array<string, mixed>> */
    public function componentSummary(InventoryProduct|int $kit): array
    {
        $kit = $this->kit($kit);
        $components = $kit->relationLoaded('kitComponents')
            ? $kit->kitComponents
            : $kit->kitComponents()->with('component')->get();

        return $components->map(function (InventoryKitComponent $component): array {
            $product = $component->component;
            $physical = $this->stock->physicalStock($product);
            $available = $this->stock->availableStock($product);

            return [
                'component' => $product,
                'quantity' => (int) $component->quantity,
                'physical_stock' => $physical,
                'available_stock' => $available,
                'physical_capacity' => intdiv(max(0, $physical), (int) $component->quantity),
                'available_capacity' => intdiv(max(0, $available), (int) $component->quantity),
            ];
        })->values()->all();
    }

    /** @param Collection<int, InventoryProduct> $kits @return Collection<int, array{physical_stock:int,available_stock:int}> */
    public function stocksForKits(Collection $kits): Collection
    {
        $kits = $kits->filter(fn (InventoryProduct $kit): bool => $kit->isKit());
        $components = $kits->flatMap(fn (InventoryProduct $kit) => $kit->kitComponents)->values();
        $componentIds = $components->pluck('component_product_id')->unique()->values();
        if ($componentIds->isEmpty()) {
            return $kits->mapWithKeys(fn (InventoryProduct $kit): array => [$kit->getKey() => ['physical_stock' => 0, 'available_stock' => 0]]);
        }

        $physical = InventoryMovement::query()
            ->whereIn('inventory_product_id', $componentIds)
            ->select('inventory_product_id')
            ->selectRaw('SUM(quantity) AS quantity')
            ->groupBy('inventory_product_id')
            ->pluck('quantity', 'inventory_product_id');
        $reserved = InventoryReservation::query()
            ->active()
            ->whereIn('inventory_product_id', $componentIds)
            ->select('inventory_product_id')
            ->selectRaw('SUM(quantity) AS quantity')
            ->groupBy('inventory_product_id')
            ->pluck('quantity', 'inventory_product_id');

        return $kits->mapWithKeys(function (InventoryProduct $kit) use ($components, $physical, $reserved): array {
            $kitComponents = $components->where('kit_product_id', $kit->getKey());
            $physicalCapacity = $kitComponents->map(fn (InventoryKitComponent $component): int => intdiv(max(0, (int) $physical->get($component->component_product_id, 0)), (int) $component->quantity));
            $availableCapacity = $kitComponents->map(fn (InventoryKitComponent $component): int => intdiv(max(0, (int) $physical->get($component->component_product_id, 0) - (int) $reserved->get($component->component_product_id, 0)), (int) $component->quantity));

            return [$kit->getKey() => [
                'physical_stock' => $physicalCapacity->isEmpty() ? 0 : (int) $physicalCapacity->min(),
                'available_stock' => $availableCapacity->isEmpty() ? 0 : (int) $availableCapacity->min(),
            ]];
        });
    }

    private function capacity(Collection $components, bool $available): int
    {
        if ($components->isEmpty()) {
            return 0;
        }

        return (int) $components->map(function (InventoryKitComponent $component) use ($available): int {
            $stock = $available
                ? $this->stock->availableStock($component->component)
                : $this->stock->physicalStock($component->component);

            return intdiv(max(0, $stock), (int) $component->quantity);
        })->min();
    }

    private function kit(InventoryProduct|int $kit): InventoryProduct
    {
        $product = $kit instanceof InventoryProduct
            ? $kit
            : InventoryProduct::query()->findOrFail($kit);

        if (! $product->isKit()) {
            throw new \InvalidArgumentException('El producto no es un kit.');
        }

        return $product;
    }
}
