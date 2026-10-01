<?php

namespace App\Services;

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
use InvalidArgumentException;

class MeliFullShipmentService
{
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
     * Create shipment with standardized 30-unit boxes
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
                        'capacity' => 30,
                        'dimensions' => '40x30x30',
                        'weight_kg' => 0,
                        'items' => [],
                    ]
                ];
            }

            foreach ($boxesData as $boxIndex => $boxData) {
                $boxNumber = (int) ($boxData['box_number'] ?? ($boxIndex + 1));
                $capacity = (int) ($boxData['capacity'] ?? 30);
                $boxCode = "CAJA-{$boxNumber}-" . substr($shipment->shipment_code, -4);

                $box = MeliFullShipmentBox::create([
                    'meli_full_shipment_id' => $shipment->id,
                    'box_number' => $boxNumber,
                    'box_code' => $boxCode,
                    'capacity' => $capacity,
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

                    MeliFullShipmentItem::create([
                        'meli_full_shipment_id' => $shipment->id,
                        'meli_full_shipment_box_id' => $box->id,
                        'inventory_product_id' => $productId,
                        'sku' => $sku,
                        'product_name' => $productName,
                        'mlm' => $itemData['mlm'] ?? null,
                        'variation_id' => $itemData['variation_id'] ?? null,
                        'quantity_sent' => $qty,
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
                    $capacity = (int) ($boxData['capacity'] ?? 30);
                    $boxCode = "CAJA-{$boxNumber}-" . substr($shipment->shipment_code, -4);

                    $box = MeliFullShipmentBox::create([
                        'meli_full_shipment_id' => $shipment->id,
                        'box_number' => $boxNumber,
                        'box_code' => $boxCode,
                        'capacity' => $capacity,
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

                        MeliFullShipmentItem::create([
                            'meli_full_shipment_id' => $shipment->id,
                            'meli_full_shipment_box_id' => $box->id,
                            'inventory_product_id' => $productId,
                            'sku' => $itemData['sku'] ?? $product?->sku ?? 'SIN-SKU',
                            'product_name' => $itemData['product_name'] ?? $product?->name ?? 'Producto',
                            'mlm' => $itemData['mlm'] ?? null,
                            'variation_id' => $itemData['variation_id'] ?? null,
                            'quantity_sent' => $qty,
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
     * Dispatch shipment: Register TRANSFER_OUT in local warehouse ledger
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

            // Register TRANSFER_OUT movements for each item
            $items = $shipment->items()->whereNotNull('inventory_product_id')->get();
            foreach ($items as $item) {
                if ($item->quantity_sent <= 0) {
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

            // Check if there are existing TRANSFER_OUT movements to revert
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

        // 2. En Bodega MeLi (Disponible para venta)
        $inWarehouseAvailableUnits = (int) MeliFullStock::query()->sum('full_available_quantity');

        // 3. Dañado (Transporte o Bodega MeLi)
        $damagedShipmentUnits = (int) MeliFullShipmentItem::query()->sum('quantity_damaged');
        
        // Sumar piezas dañadas registradas en not_available_detail de MeliFullStock
        $damagedStockUnits = 0;
        $underReviewStockUnits = 0;

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

            if (in_array(strtolower((string) $st->publication_status), ['under_review', 'paused', 'inactive'], true)) {
                // Publicación con posibles observaciones
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
}
