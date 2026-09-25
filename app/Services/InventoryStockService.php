<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use Illuminate\Support\Collection;

class InventoryStockService
{
    public function productStock(InventoryProduct|int $product): int
    {
        $productId = $product instanceof InventoryProduct ? $product->getKey() : $product;

        return (int) InventoryMovement::query()
            ->where('inventory_product_id', $productId)
            ->sum('quantity');
    }

    public function physicalStock(InventoryProduct|int $product): int
    {
        return $this->productStock($product);
    }

    public function physicalStockByLocation(
        InventoryProduct|int $product,
        InventoryLocation|int $location,
    ): int {
        $productId = $product instanceof InventoryProduct ? $product->getKey() : $product;
        $locationId = $location instanceof InventoryLocation ? $location->getKey() : $location;

        return (int) InventoryMovement::query()
            ->where('inventory_product_id', $productId)
            ->where('inventory_location_id', $locationId)
            ->sum('quantity');
    }

    public function reservedStock(InventoryProduct|int $product): int
    {
        $productId = $product instanceof InventoryProduct ? $product->getKey() : $product;

        return (int) InventoryReservation::query()
            ->active()
            ->where('inventory_product_id', $productId)
            ->sum('quantity');
    }

    public function reservedStockByLocation(
        InventoryProduct|int $product,
        InventoryLocation|int $location,
    ): int {
        $productId = $product instanceof InventoryProduct ? $product->getKey() : $product;
        $locationId = $location instanceof InventoryLocation ? $location->getKey() : $location;

        return (int) InventoryReservation::query()
            ->active()
            ->where('inventory_product_id', $productId)
            ->where(function ($query) use ($locationId): void {
                $query->where('inventory_location_id', $locationId)
                    ->orWhereNull('inventory_location_id');
            })
            ->sum('quantity');
    }

    public function availableStock(InventoryProduct|int $product): int
    {
        return $this->physicalStock($product) - $this->reservedStock($product);
    }

    public function availableStockByLocation(
        InventoryProduct|int $product,
        InventoryLocation|int $location,
    ): int {
        return $this->physicalStockByLocation($product, $location)
            - $this->reservedStockByLocation($product, $location);
    }

    /** @return Collection<int, object{inventory_location_id:int, quantity:int, location:object}> */
    public function productStockByLocation(InventoryProduct|int $product): Collection
    {
        $productId = $product instanceof InventoryProduct ? $product->getKey() : $product;

        return InventoryMovement::query()
            ->select('inventory_location_id')
            ->selectRaw('SUM(quantity) as quantity')
            ->with('location:id,code,name')
            ->where('inventory_product_id', $productId)
            ->groupBy('inventory_location_id')
            ->orderBy('inventory_location_id')
            ->get();
    }
}
