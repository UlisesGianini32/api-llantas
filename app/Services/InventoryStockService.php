<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
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
