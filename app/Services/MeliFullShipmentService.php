<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\MeliFullShipment;
use App\Models\MeliFullShipmentBox;
use App\Models\MeliFullShipmentItem;
use App\Models\MeliFullStock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MeliFullShipmentService
{
    public function __construct(
        private readonly ?InventoryStockService $stockService = null
    ) {}

    /**
     * Generate unique sequential shipment code, e.g., FULL-ENV-2026-0001
     */
    public function generateShipmentCode(): string
    {
        $year = date('Y');
        $prefix = "FULL-ENV-{$year}-";

        $last = MeliFullShipment::query()
            ->where('shipment_code', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->value('shipment_code');

        if ($last) {
            $num = (int) substr($last, strlen($prefix));
            $next = str_pad((string) ($num + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $next = '0001';
        }

        return $prefix . $next;
    }

    /**
     * Create shipment with standardized 30 kg boxes and multi-box per bulto support
     */
    public function createShipment(array $data, ?User $user = null): MeliFullShipment
    {
        return DB::transaction(function () use ($data, $user) {
            $shipmentCode = ! empty($data['shipment_code']) ? trim($data['shipment_code']) : $this->generateShipmentCode();
            $warehouseCode = $data['meli_warehouse_code'] ?? 'MXCD01';
            $warehouseName = MeliFullShipment::WAREHOUSES[$warehouseCode] ?? ($data['meli_warehouse_name'] ?? 'CEDIS MeLi');

            $shipment = MeliFullShipment::create([
                'user_id' => $user?->id,
                'shipment_code' => $shipmentCode,
                'status' => MeliFullShipment::STATUS_DRAFT,
                'meli_warehouse_code' => $warehouseCode,
                'meli_warehouse_name' => $warehouseName,
                'meli_shipment_id' => $data['meli_shipment_id'] ?? null,
                'envia_carrier' => $data['envia_carrier'] ?? 'Paquetexpress',
                'envia_tracking_number' => $data['envia_tracking_number'] ?? null,
                'envia_tracking_url' => $this->buildEnviaTrackingUrl($data['envia_carrier'] ?? null, $data['envia_tracking_number'] ?? null),
                'envia_cost' => (float) ($data['envia_cost'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ]);

            $boxesData = $data['boxes'] ?? [];

            // If no boxes specified, create at least 1 default box
            if (empty($boxesData)) {
                $boxesData = [
                    [
                        'box_number' => 1,
                        'bulto_number' => 1,
                        'boxes_in_bulto' => 1,
                        'capacity_kg' => 30.00,
                        'capacity' => 30,
                        'dimensions' => '40x30x30',
                        'weight_kg' => 0,
                        'items' => [],
                    ]
                ];
            }

            foreach ($boxesData as $boxIndex => $boxData) {
                $boxNumber = (int) ($boxData['box_number'] ?? ($boxIndex + 1));
                $bultoNumber = (int) ($boxData['bulto_number'] ?? ($boxData['box_number'] ?? ($boxIndex + 1)));
                $boxesInBulto = max(1, (int) ($boxData['boxes_in_bulto'] ?? 1));
                $capacityKg = (float) ($boxData['capacity_kg'] ?? ($boxesInBulto * 30.00));
                $capacityUnits = (int) ($boxData['capacity'] ?? ($boxesInBulto * 30));
                
                $boxCode = ($boxesInBulto > 1 ? "BULTO-{$bultoNumber}-(" . $boxesInBulto . "CAJAS)-" : "CAJA-{$boxNumber}-") . substr($shipment->shipment_code, -4);

                $box = MeliFullShipmentBox::create([
                    'meli_full_shipment_id' => $shipment->id,
                    'box_number' => $boxNumber,
                    'bulto_number' => $bultoNumber,
                    'boxes_in_bulto' => $boxesInBulto,
                    'box_code' => $boxCode,
                    'capacity' => $capacityUnits,
                    'capacity_kg' => $capacityKg,
                    'dimensions' => $boxData['dimensions'] ?? '40x30x30',
                    'weight_kg' => (float) ($boxData['weight_kg'] ?? 0),
                    'status' => MeliFullShipmentBox::STATUS_PACKING,
                ]);

                $items = $boxData['items'] ?? [];
                foreach ($items as $itemData) {
                    $qty = (int) ($itemData['quantity_sent'] ?? 0);
                    if ($qty <= 0) {
                        continue;
                    }

                    $productId = ! empty($itemData['inventory_product_id']) ? (int) $itemData['inventory_product_id'] : null;
                    $product = $productId ? InventoryProduct::find($productId) : null;

                    $sku = $itemData['sku'] ?? $product?->sku ?? 'SIN-SKU';
                    $productName = $itemData['product_name'] ?? $product?->name ?? 'Producto';

                    $unitWeight = isset($itemData['unit_weight_kg']) && (float) $itemData['unit_weight_kg'] > 0
                        ? (float) $itemData['unit_weight_kg']
                        : (float) ($product?->weight_kg ?: 1.000);

                    $totalWeight = round($qty * $unitWeight, 3);

                    MeliFullShipmentItem::create([
                        'meli_full_shipment_id' => $shipment->id,
                        'meli_full_shipment_box_id' => $box->id,
                        'inventory_product_id' => $productId,
                        'sku' => $sku,
                        'product_name' => $productName,
                        'mlm' => $itemData['mlm'] ?? null,
                        'variation_id' => $itemData['variation_id'] ?? null,
                        'quantity_sent' => $qty,
                        'requires_labeling' => ! empty($itemData['requires_labeling']),
                        'unit_weight_kg' => $unitWeight,
                        'total_weight_kg' => $totalWeight,
                        'notes' => $itemData['notes'] ?? null,
                    ]);
                }

                $box->recalculateUnits();
            }

            $shipment->recalculateTotals();

            return $shipment->fresh(['boxes.items', 'items', 'user']);
        });
    }

    /**
     * Update an existing shipment details, boxes, and items
     */
    public function updateShipment(MeliFullShipment $shipment, array $data, ?User $user = null): MeliFullShipment
    {
        return DB::transaction(function () use ($shipment, $data) {
            $warehouseCode = $data['meli_warehouse_code'] ?? $shipment->meli_warehouse_code;
            $warehouseName = MeliFullShipment::WAREHOUSES[$warehouseCode] ?? ($data['meli_warehouse_name'] ?? $shipment->meli_warehouse_name);

            $carrier = $data['envia_carrier'] ?? $shipment->envia_carrier;
            $tracking = $data['envia_tracking_number'] ?? $shipment->envia_tracking_number;

            $shipment->update([
                'meli_warehouse_code' => $warehouseCode,
                'meli_warehouse_name' => $warehouseName,
                'meli_shipment_id' => $data['meli_shipment_id'] ?? $shipment->meli_shipment_id,
                'envia_carrier' => $carrier,
                'envia_tracking_number' => $tracking,
                'envia_tracking_url' => $this->buildEnviaTrackingUrl($carrier, $tracking),
                'envia_cost' => isset($data['envia_cost']) ? (float) $data['envia_cost'] : $shipment->envia_cost,
                'notes' => $data['notes'] ?? $shipment->notes,
            ]);

            // Only allow re-arranging boxes/items if DRAFT or PACKED
            if (in_array($shipment->status, [MeliFullShipment::STATUS_DRAFT, MeliFullShipment::STATUS_PACKED], true) && isset($data['boxes'])) {
                // Delete existing boxes and items
                $shipment->items()->delete();
                $shipment->boxes()->delete();

                foreach ($data['boxes'] as $boxIndex => $boxData) {
                    $boxNumber = (int) ($boxData['box_number'] ?? ($boxIndex + 1));
                    $bultoNumber = (int) ($boxData['bulto_number'] ?? ($boxData['box_number'] ?? ($boxIndex + 1)));
                    $boxesInBulto = max(1, (int) ($boxData['boxes_in_bulto'] ?? 1));
                    $capacityKg = (float) ($boxData['capacity_kg'] ?? ($boxesInBulto * 30.00));
                    $capacityUnits = (int) ($boxData['capacity'] ?? ($boxesInBulto * 30));
                    
                    $boxCode = ($boxesInBulto > 1 ? "BULTO-{$bultoNumber}-(" . $boxesInBulto . "CAJAS)-" : "CAJA-{$boxNumber}-") . substr($shipment->shipment_code, -4);

                    $box = MeliFullShipmentBox::create([
                        'meli_full_shipment_id' => $shipment->id,
                        'box_number' => $boxNumber,
                        'bulto_number' => $bultoNumber,
                        'boxes_in_bulto' => $boxesInBulto,
                        'box_code' => $boxCode,
                        'capacity' => $capacityUnits,
                        'capacity_kg' => $capacityKg,
                        'dimensions' => $boxData['dimensions'] ?? '40x30x30',
                        'weight_kg' => (float) ($boxData['weight_kg'] ?? 0),
                        'status' => MeliFullShipmentBox::STATUS_PACKING,
                    ]);

                    $items = $boxData['items'] ?? [];
                    foreach ($items as $itemData) {
                        $qty = (int) ($itemData['quantity_sent'] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }

                        $productId = ! empty($itemData['inventory_product_id']) ? (int) $itemData['inventory_product_id'] : null;
                        $product = $productId ? InventoryProduct::find($productId) : null;

                        $sku = $itemData['sku'] ?? $product?->sku ?? 'SIN-SKU';
                        $productName = $itemData['product_name'] ?? $product?->name ?? 'Producto';

                        $unitWeight = isset($itemData['unit_weight_kg']) && (float) $itemData['unit_weight_kg'] > 0
                            ? (float) $itemData['unit_weight_kg']
                            : (float) ($product?->weight_kg ?: 1.000);

                        $totalWeight = round($qty * $unitWeight, 3);

                        MeliFullShipmentItem::create([
                            'meli_full_shipment_id' => $shipment->id,
                            'meli_full_shipment_box_id' => $box->id,
                            'inventory_product_id' => $productId,
                            'sku' => $sku,
                            'product_name' => $productName,
                            'mlm' => $itemData['mlm'] ?? null,
                            'variation_id' => $itemData['variation_id'] ?? null,
                            'quantity_sent' => $qty,
                            'requires_labeling' => ! empty($itemData['requires_labeling']),
                            'unit_weight_kg' => $unitWeight,
                            'total_weight_kg' => $totalWeight,
                            'notes' => $itemData['notes'] ?? null,
                        ]);
                    }

                    $box->recalculateUnits();
                }

                $shipment->recalculateTotals();
            }

            return $shipment->fresh(['boxes.items', 'items', 'user']);
        });
    }

    /**
     * Empacar y sellar una caja individual (de una por una, máx 30 kg)
     * Descuenta el inventario físico del almacén local inmediatamente.
     */
    public function packBox(MeliFullShipment $shipment, array $data, ?User $user = null): MeliFullShipmentBox
    {
        return DB::transaction(function () use ($shipment, $data, $user) {
            $existingCount = $shipment->boxes()->count();
            $boxNumber = (int) ($data['box_number'] ?? ($existingCount + 1));
            $bultoNumber = (int) ($data['bulto_number'] ?? $boxNumber);
            $boxesInBulto = max(1, (int) ($data['boxes_in_bulto'] ?? 1));
            $capacityKg = (float) ($data['capacity_kg'] ?? ($boxesInBulto * 30.00));
            $capacityUnits = (int) ($data['capacity'] ?? ($boxesInBulto * 30));

            $boxCode = ($boxesInBulto > 1 ? "BULTO-{$bultoNumber}-(" . $boxesInBulto . "CAJAS)-" : "CAJA-{$boxNumber}-") . substr($shipment->shipment_code, -4);

            $box = MeliFullShipmentBox::create([
                'meli_full_shipment_id' => $shipment->id,
                'box_number' => $boxNumber,
                'bulto_number' => $bultoNumber,
                'boxes_in_bulto' => $boxesInBulto,
                'box_code' => $boxCode,
                'capacity' => $capacityUnits,
                'capacity_kg' => $capacityKg,
                'dimensions' => $data['dimensions'] ?? '40x30x30',
                'weight_kg' => 0,
                'status' => MeliFullShipmentBox::STATUS_PACKED,
            ]);

            $location = InventoryLocation::query()->first() ?? InventoryLocation::create([
                'name' => 'Almacén General',
                'code' => 'ALM-GEN',
                'is_active' => true,
            ]);

            $items = $data['items'] ?? [];
            foreach ($items as $itemData) {
                $qty = (int) ($itemData['quantity_sent'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $productId = ! empty($itemData['inventory_product_id']) ? (int) $itemData['inventory_product_id'] : null;
                $product = $productId ? InventoryProduct::find($productId) : null;

                $sku = $itemData['sku'] ?? $product?->sku ?? 'SIN-SKU';
                $productName = $itemData['product_name'] ?? $product?->name ?? 'Producto';

                $unitWeight = isset($itemData['unit_weight_kg']) && (float) $itemData['unit_weight_kg'] > 0
                    ? (float) $itemData['unit_weight_kg']
                    : (float) ($product?->weight_kg ?: 1.000);

                $totalWeight = round($qty * $unitWeight, 3);

                MeliFullShipmentItem::create([
                    'meli_full_shipment_id' => $shipment->id,
                    'meli_full_shipment_box_id' => $box->id,
                    'inventory_product_id' => $productId,
                    'sku' => $sku,
                    'product_name' => $productName,
                    'mlm' => $itemData['mlm'] ?? null,
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity_sent' => $qty,
                    'requires_labeling' => ! empty($itemData['requires_labeling']),
                    'unit_weight_kg' => $unitWeight,
                    'total_weight_kg' => $totalWeight,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // Descuento inmediato de existencias físicas del almacén local
                if ($productId) {
                    InventoryMovement::create([
                        'inventory_product_id' => $productId,
                        'inventory_location_id' => $location->id,
                        'type' => InventoryMovement::TRANSFER_OUT,
                        'quantity' => -abs($qty),
                        'reference_type' => 'meli_full_box',
                        'reference_id' => $box->id,
                        'reference' => $box->box_code,
                        'notes' => "Salida por empaque de {$box->box_code} ({$shipment->shipment_code}) - Mercado Libre FULL",
                        'created_by' => $user?->id,
                        'occurred_at' => Carbon::now(),
                    ]);
                }
            }

            $box->recalculateUnits();
            $shipment->recalculateTotals();

            if ($shipment->status === MeliFullShipment::STATUS_DRAFT) {
                $shipment->status = MeliFullShipment::STATUS_PACKED;
                $shipment->save();
            }

            return $box->fresh(['items.inventoryProduct']);
        });
    }

    /**
     * Desempacar / eliminar una caja y reintegrar su inventario al almacén local
     */
    public function unpackBox(MeliFullShipmentBox $box, ?User $user = null): void
    {
        DB::transaction(function () use ($box, $user) {
            $shipment = $box->shipment;
            $location = InventoryLocation::query()->first() ?? InventoryLocation::create([
                'name' => 'Almacén General',
                'code' => 'ALM-GEN',
                'is_active' => true,
            ]);

            // Reintegrar al inventario los productos descontados al empacar esta caja
            $items = $box->items()->whereNotNull('inventory_product_id')->get();
            foreach ($items as $item) {
                if ($item->quantity_sent <= 0) {
                    continue;
                }

                InventoryMovement::create([
                    'inventory_product_id' => $item->inventory_product_id,
                    'inventory_location_id' => $location->id,
                    'type' => InventoryMovement::TRANSFER_IN,
                    'quantity' => abs($item->quantity_sent),
                    'reference_type' => 'meli_full_box_revert',
                    'reference_id' => $box->id,
                    'reference' => $box->box_code,
                    'notes' => "Reincorporación al almacén por desempacado de {$box->box_code} ({$shipment->shipment_code})",
                    'created_by' => $user?->id,
                    'occurred_at' => Carbon::now(),
                ]);
            }

            $box->items()->delete();
            $box->delete();

            $shipment->recalculateTotals();
            if ($shipment->boxes()->count() === 0 && $shipment->status === MeliFullShipment::STATUS_PACKED) {
                $shipment->status = MeliFullShipment::STATUS_DRAFT;
                $shipment->save();
            }
        });
    }

    /**
     * Vincular una publicación de Mercado Libre FULL con un producto de inventario local
     */
    public function linkProductToMeli(
        int $inventoryProductId,
        string $mlm,
        ?string $variationId = null,
        ?string $meliSku = null,
        ?User $user = null,
    ): InventoryChannelLink {
        $product = InventoryProduct::findOrFail($inventoryProductId);
        $mlmClean = strtoupper(trim($mlm));
        $varClean = $variationId ? trim($variationId) : null;
        $identityKey = 'meli_' . $mlmClean . ($varClean ? '_' . $varClean : '');

        $link = InventoryChannelLink::firstOrNew([
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'identity_key' => $identityKey,
        ]);

        $link->inventory_product_id = $product->id;
        $link->external_listing_id = $mlmClean;
        $link->external_variant_id = $varClean;
        $link->remote_status = 'active';
        $link->is_active = true;
        $link->stock_sync_enabled = false;
        $link->metadata = array_merge($link->metadata ?? [], [
            'linked_from' => 'meli_full_packing',
            'linked_by' => $user?->id,
            'meli_sku' => $meliSku,
            'linked_at' => Carbon::now()->toIso8601String(),
        ]);
        $link->save();

        return $link->fresh(['product']);
    }

    /**
     * Alta rápida de un producto en el inventario local y vinculación directa
     */
    public function quickCreateProduct(array $data, ?User $user = null): InventoryProduct
    {
        return DB::transaction(function () use ($data, $user) {
            $sku = trim((string) ($data['sku'] ?? ''));
            if ($sku === '') {
                $sku = 'PRD-' . strtoupper(substr(uniqid(), -6));
            }

            // Asegurar SKU único
            $baseSku = $sku;
            $counter = 1;
            while (InventoryProduct::where('sku', $sku)->exists()) {
                $sku = $baseSku . '-' . $counter;
                $counter++;
            }

            $barcode = ! empty($data['barcode']) ? trim((string) $data['barcode']) : null;
            if ($barcode && InventoryProduct::where('barcode', $barcode)->exists()) {
                $barcode = null;
            }

            $weightKg = (float) ($data['weight_kg'] ?? 1.000);
            if ($weightKg <= 0) {
                $weightKg = 1.000;
            }

            $location = InventoryLocation::query()->first() ?? InventoryLocation::create([
                'name' => 'Almacén General',
                'code' => 'ALM-GEN',
                'is_active' => true,
            ]);

            $product = InventoryProduct::create([
                'name' => trim((string) ($data['name'] ?? 'Producto Nuevo')),
                'sku' => $sku,
                'barcode' => $barcode,
                'brand' => trim((string) ($data['brand'] ?? 'General')),
                'product_type' => InventoryProduct::SIMPLE,
                'primary_location_id' => $location->id,
                'weight_kg' => $weightKg,
                'is_active' => true,
                'requires_meli_labeling' => empty($barcode),
            ]);

            $initialStock = max(0, (int) ($data['initial_stock'] ?? 0));
            if ($initialStock > 0) {
                InventoryMovement::create([
                    'inventory_product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'type' => InventoryMovement::INITIAL,
                    'quantity' => $initialStock,
                    'reference' => 'Alta Rápida Mesa FULL',
                    'notes' => 'Stock inicial registrado durante armado de cajas FULL',
                    'created_by' => $user?->id,
                    'occurred_at' => Carbon::now(),
                ]);
            }

            // Vincular automáticamente a la publicación MeLi si se proporcionó mlm
            if (! empty($data['mlm'])) {
                $this->linkProductToMeli(
                    $product->id,
                    (string) $data['mlm'],
                    $data['variation_id'] ?? null,
                    $data['meli_sku'] ?? null,
                    $user
                );
            }

            $stockSvc = $this->stockService ?? app(InventoryStockService::class);
            $product->available_stock = $stockSvc->availableStock($product);

            return $product;
        });
    }

    /**
     * Dispatch shipment: Register TRANSFER_OUT in local warehouse ledger
     * Evita duplicar descuentos si las cajas ya fueron descontadas al empacar.
     */
    public function dispatchShipment(MeliFullShipment $shipment, ?User $user = null): MeliFullShipment
    {
        if ($shipment->status === MeliFullShipment::STATUS_IN_TRANSIT) {
            throw new InvalidArgumentException("El envío {$shipment->shipment_code} ya se encuentra en tránsito.");
        }

        if (in_array($shipment->status, [MeliFullShipment::STATUS_RECEIVED, MeliFullShipment::STATUS_DELIVERED], true)) {
            throw new InvalidArgumentException("El envío {$shipment->shipment_code} ya fue entregado/recibido.");
        }

        return DB::transaction(function () use ($shipment, $user) {
            $location = InventoryLocation::query()->first() ?? InventoryLocation::create([
                'name' => 'Almacén General',
                'code' => 'ALM-GEN',
                'is_active' => true,
            ]);

            // Cajas que ya descontaron inventario individualmente
            $alreadyDeductedBoxIds = InventoryMovement::query()
                ->where('reference_type', 'meli_full_box')
                ->whereIn('reference_id', $shipment->boxes()->pluck('id'))
                ->pluck('reference_id')
                ->all();

            // Registrar movimientos de salida solo para ítems cuyas cajas no hayan sido descontadas previamente
            $items = $shipment->items()->whereNotNull('inventory_product_id')->get();
            foreach ($items as $item) {
                if ($item->quantity_sent <= 0) {
                    continue;
                }

                if (in_array($item->meli_full_shipment_box_id, $alreadyDeductedBoxIds, true)) {
                    continue;
                }

                InventoryMovement::create([
                    'inventory_product_id' => $item->inventory_product_id,
                    'inventory_location_id' => $location->id,
                    'type' => InventoryMovement::TRANSFER_OUT,
                    'quantity' => -abs($item->quantity_sent),
                    'reference_type' => 'meli_full_shipment',
                    'reference_id' => $shipment->id,
                    'reference' => $shipment->shipment_code,
                    'notes' => "Salida para Mercado Libre FULL ({$shipment->meli_warehouse_code}) - Guía ENVIA {$shipment->envia_carrier} {$shipment->envia_tracking_number}",
                    'created_by' => $user?->id,
                    'occurred_at' => Carbon::now(),
                ]);
            }

            $shipment->status = MeliFullShipment::STATUS_IN_TRANSIT;
            $shipment->shipped_at = Carbon::now();
            $shipment->save();

            // Mark boxes as SEALED
            $shipment->boxes()->update(['status' => MeliFullShipmentBox::STATUS_SEALED]);

            return $shipment->fresh(['boxes.items', 'items', 'user']);
        });
    }

    /**
     * Revert dispatch: Re-inject inventory into local warehouse (TRANSFER_IN)
     */
    public function revertDispatch(MeliFullShipment $shipment, ?User $user = null): MeliFullShipment
    {
        if (! in_array($shipment->status, [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_CANCELLED], true)) {
            throw new InvalidArgumentException("Solo se pueden revertir envíos en tránsito o cancelados.");
        }

        return DB::transaction(function () use ($shipment, $user) {
            $location = InventoryLocation::query()->first() ?? InventoryLocation::create([
                'name' => 'Almacén General',
                'code' => 'ALM-GEN',
                'is_active' => true,
            ]);

            // Revertir salidas a nivel de envío
            $items = $shipment->items()->whereNotNull('inventory_product_id')->get();
            foreach ($items as $item) {
                if ($item->quantity_sent <= 0) {
                    continue;
                }

                InventoryMovement::create([
                    'inventory_product_id' => $item->inventory_product_id,
                    'inventory_location_id' => $location->id,
                    'type' => InventoryMovement::TRANSFER_IN,
                    'quantity' => abs($item->quantity_sent),
                    'reference_type' => 'meli_full_shipment',
                    'reference_id' => $shipment->id,
                    'reference' => $shipment->shipment_code,
                    'notes' => "Reversión de envío FULL ({$shipment->shipment_code}) - Retorno a almacén local",
                    'created_by' => $user?->id,
                    'occurred_at' => Carbon::now(),
                ]);
            }

            $shipment->status = MeliFullShipment::STATUS_DRAFT;
            $shipment->shipped_at = null;
            $shipment->save();

            $shipment->boxes()->update(['status' => MeliFullShipmentBox::STATUS_PACKED]);

            return $shipment->fresh(['boxes.items', 'items', 'user']);
        });
    }

    /**
     * Register MeLi reception inspection (received units, damaged units, missing units)
     */
    public function receiveShipment(MeliFullShipment $shipment, array $receptions, ?string $notes = null): MeliFullShipment
    {
        return DB::transaction(function () use ($shipment, $receptions, $notes) {
            foreach ($receptions as $itemRec) {
                $itemId = (int) ($itemRec['item_id'] ?? 0);
                $item = MeliFullShipmentItem::where('meli_full_shipment_id', $shipment->id)->find($itemId);

                if (! $item) {
                    continue;
                }

                $rec = (int) ($itemRec['quantity_received'] ?? 0);
                $dam = (int) ($itemRec['quantity_damaged'] ?? 0);
                $mis = (int) ($itemRec['quantity_missing'] ?? 0);

                // Auto calculate missing if only received and damaged are specified
                if (! isset($itemRec['quantity_missing'])) {
                    $mis = max(0, $item->quantity_sent - ($rec + $dam));
                }

                $item->update([
                    'quantity_received' => $rec,
                    'quantity_damaged' => $dam,
                    'quantity_missing' => $mis,
                    'notes' => $itemRec['notes'] ?? $item->notes,
                ]);
            }

            $shipment->status = MeliFullShipment::STATUS_RECEIVED;
            $shipment->received_at = Carbon::now();

            if (! empty($notes)) {
                $shipment->notes = trim(($shipment->notes ? $shipment->notes . "\n" : '') . "[Recepción " . Carbon::now()->format('d/m/Y H:i') . "]: " . $notes);
            }

            $shipment->recalculateTotals();

            return $shipment->fresh(['boxes.items', 'items', 'user']);
        });
    }

    /**
     * Build tracking URL for ENVIA or carriers
     */
    private function buildEnviaTrackingUrl(?string $carrier, ?string $tracking): ?string
    {
        if (blank($tracking)) {
            return null;
        }

        $c = strtolower(trim((string) $carrier));
        $t = urlencode(trim($tracking));

        if (str_contains($c, 'paquetexpress')) {
            return "https://www.paquetexpress.com.mx/rastreo-de-envios?tracking={$t}";
        }

        if (str_contains($c, 'fedex')) {
            return "https://www.fedex.com/fedextrack/?trknbr={$t}";
        }

        if (str_contains($c, 'dhl')) {
            return "https://www.dhl.com/mx-es/home/tracking/tracking-express.html?submit=1&tracking-id={$t}";
        }

        if (str_contains($c, 'estafeta')) {
            return "https://www.estafeta.com/Herramientas/Rastreo?rastreo={$t}";
        }

        if (str_contains($c, 'redpack')) {
            return "https://www.redpack.com.mx/es/rastreo/?tracking={$t}";
        }

        // Generic ENVIA tracking link
        return "https://envia.com/es-MX/tracking?tracking_number={$t}";
    }

    /**
     * Full KPI and Stock Monitoring Dashboard
     */
    public function getKpiSummary(): array
    {
        // 1. En Tránsito (hacia bodegas MeLi)
        $inTransitShipments = MeliFullShipment::query()
            ->whereIn('status', [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_PACKED])
            ->count();

        $inTransitUnits = (int) MeliFullShipment::query()
            ->whereIn('status', [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_PACKED])
            ->sum('total_units');

        $inTransitWeight = (float) MeliFullShipment::query()
            ->whereIn('status', [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_PACKED])
            ->sum('total_weight_kg');

        // 2. En Bodega MeLi (Disponible para venta)
        $inWarehouseAvailableUnits = 0;
        if (Schema::hasTable('meli_full_stocks')) {
            $inWarehouseAvailableUnits = (int) MeliFullStock::query()->sum('full_available_quantity');
        }

        // 3. Dañado (Transporte o Bodega MeLi)
        $damagedShipmentUnits = (int) MeliFullShipmentItem::query()->sum('quantity_damaged');
        
        $damagedStockUnits = 0;
        $underReviewStockUnits = 0;

        if (Schema::hasTable('meli_full_stocks')) {
            $stocksWithDetail = MeliFullStock::query()
                ->whereNotNull('not_available_detail')
                ->select(['not_available_detail', 'publication_status'])
                ->get();

            foreach ($stocksWithDetail as $st) {
                $details = is_array($st->not_available_detail) ? $st->not_available_detail : [];
                foreach ($details as $d) {
                    $statusName = strtolower(trim((string) ($d['status'] ?? '')));
                    $qty = (int) ($d['quantity'] ?? 0);

                    if (str_contains($statusName, 'damage') || str_contains($statusName, 'daña')) {
                        $damagedStockUnits += $qty;
                    } elseif (str_contains($statusName, 'review') || str_contains($statusName, 'revis') || str_contains($statusName, 'fiscal') || str_contains($statusName, 'audit')) {
                        $underReviewStockUnits += $qty;
                    }
                }
            }
        }

        $totalDamagedUnits = $damagedShipmentUnits + $damagedStockUnits;

        // 4. En Revisión / Falla / Discrepancia
        $missingShipmentUnits = (int) MeliFullShipmentItem::query()->sum('quantity_missing');
        $discrepancyShipmentsCount = MeliFullShipment::query()
            ->where('status', MeliFullShipment::STATUS_DISCREPANCY)
            ->count();

        $totalUnderReviewUnits = $underReviewStockUnits + $missingShipmentUnits;

        return [
            'in_transit' => [
                'shipments_count' => $inTransitShipments,
                'units' => $inTransitUnits,
                'weight_kg' => $inTransitWeight,
            ],
            'in_warehouse' => [
                'units' => $inWarehouseAvailableUnits,
            ],
            'damaged' => [
                'shipment_damaged' => $damagedShipmentUnits,
                'meli_damaged' => $damagedStockUnits,
                'total_units' => $totalDamagedUnits,
            ],
            'under_review' => [
                'stock_under_review' => $underReviewStockUnits,
                'missing_in_shipments' => $missingShipmentUnits,
                'discrepancy_shipments' => $discrepancyShipmentsCount,
                'total_units' => $totalUnderReviewUnits,
            ],
        ];
    }

    /**
     * Recomendar productos para enviar a MeLi FULL basándose en demanda y existencias locales
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRestockRecommendations(?int $limit = 40): array
    {
        $stockSvc = $this->stockService ?? app(InventoryStockService::class);

        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->get();

        $recommendations = [];

        foreach ($products as $prod) {
            $localAvailable = $stockSvc->availableStock($prod);
            if ($localAvailable <= 0) {
                continue; // Si no hay inventario físico local, no podemos enviarlo
            }

            $sku = strtoupper(trim((string) $prod->sku));
            $barcode = strtoupper(trim((string) $prod->barcode));

            // 1. Stock disponible en bodegas FULL
            $fullStock = 0;
            if (Schema::hasTable('meli_full_stocks')) {
                $fullStock = (int) MeliFullStock::query()
                    ->where(function ($q) use ($sku, $barcode) {
                        if ($sku !== '') $q->where('sku', $sku);
                        if ($barcode !== '') $q->orWhere('sku', $barcode);
                    })
                    ->sum('full_available_quantity');
            }

            // 2. Stock en camino hacia FULL
            $inTransit = 0;
            if (Schema::hasTable('meli_full_shipments') && Schema::hasTable('meli_full_shipment_items')) {
                $inTransit = (int) MeliFullShipmentItem::query()
                    ->where('inventory_product_id', $prod->id)
                    ->whereHas('shipment', function ($q) {
                        $q->whereIn('status', [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_PACKED]);
                    })
                    ->sum('quantity_sent');
            }

            // 3. Ventas por FULL últimos 30 días
            $salesFull30d = 0;
            if (Schema::hasTable('meli_orders') && Schema::hasTable('meli_order_items')) {
                $salesFull30d = (int) DB::table('meli_orders as o')
                    ->join('meli_order_items as i', 'i.meli_order_id', '=', 'o.id')
                    ->where(function ($q) use ($sku, $barcode) {
                        if ($sku !== '') $q->whereRaw('UPPER(TRIM(i.sku)) = ?', [$sku]);
                        if ($barcode !== '') $q->orWhereRaw('UPPER(TRIM(i.sku)) = ?', [$barcode]);
                    })
                    ->whereRaw("LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'invalid')")
                    ->where(function ($q) {
                        $q->whereRaw("LOWER(COALESCE(o.shipping_logistic_type, '')) = 'fulfillment'")
                            ->orWhereRaw("LOWER(COALESCE(o.shipping_mode, '')) = 'fulfillment'")
                            ->orWhereRaw("LOWER(COALESCE(o.shipping_type, '')) = 'fulfillment'");
                    })
                    ->where('o.created_at', '>=', Carbon::now()->subDays(30))
                    ->sum('i.quantity');
            }

            $unitWeight = (float) ($prod->weight_kg ?: 1.000);
            $totalMeliStock = $fullStock + $inTransit;
            
            // Meta de stock en FULL: 30 días de cobertura o al menos 10 piezas si vende
            $targetFullStock = $salesFull30d > 0 ? (int) max(10, ceil($salesFull30d * 1.25)) : 0;
            $need = max(0, $targetFullStock - $totalMeliStock);

            $priority = null;
            $reason = null;
            $suggested = 0;

            if ($salesFull30d > 0 && $fullStock === 0) {
                $priority = 'CRITICAL';
                $reason = "🔴 Agotado en FULL (Vendió {$salesFull30d} uds en los últimos 30 días)";
                $suggested = min($localAvailable, max(5, $need ?: $salesFull30d));
            } elseif ($salesFull30d > 0 && $fullStock <= 5) {
                $priority = 'HIGH';
                $reason = "🟡 Por agotarse en FULL ({$fullStock} uds restantes) · Venta: {$salesFull30d} uds/mes";
                $suggested = min($localAvailable, max(5, $need));
            } elseif ($salesFull30d > 0 && $need > 0) {
                $priority = 'MEDIUM';
                $reason = "Cobertura recomendada ({$targetFullStock} uds) · Venta: {$salesFull30d} uds/mes";
                $suggested = min($localAvailable, $need);
            } elseif ($salesFull30d === 0 && $totalMeliStock === 0 && $localAvailable >= 10) {
                $priority = 'EXPLORE';
                $reason = "Producto con stock disponible en almacén ({$localAvailable} uds) · Probar presencia en FULL";
                $suggested = min($localAvailable, 5);
            }

            if ($priority !== null && $suggested > 0) {
                $recommendations[] = [
                    'product_id' => $prod->id,
                    'sku' => $prod->sku,
                    'barcode' => $prod->barcode,
                    'name' => $prod->name,
                    'brand' => $prod->brand ?: 'Sin marca',
                    'weight_kg' => $unitWeight,
                    'available_stock' => $localAvailable,
                    'local_stock_available' => $localAvailable,
                    'full_stock_available' => $fullStock,
                    'full_stock_in_transit' => $inTransit,
                    'sales_full_30d' => $salesFull30d,
                    'suggested_quantity' => $suggested,
                    'suggested_total_weight' => round($suggested * $unitWeight, 2),
                    'priority' => $priority,
                    'reason' => $reason,
                ];
            }
        }

        // Ordenar por prioridad
        $priorityOrder = ['CRITICAL' => 1, 'HIGH' => 2, 'MEDIUM' => 3, 'EXPLORE' => 4];
        usort($recommendations, function ($a, $b) use ($priorityOrder) {
            $pA = $priorityOrder[$a['priority']] ?? 99;
            $pB = $priorityOrder[$b['priority']] ?? 99;
            if ($pA !== $pB) {
                return $pA <=> $pB;
            }
            return $b['sales_full_30d'] <=> $a['sales_full_30d'];
        });

        return array_slice($recommendations, 0, $limit);
    }
}
