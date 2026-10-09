<?php

namespace App\Http\Controllers;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliFullShipment;
use App\Models\MeliFullShipmentBox;
use App\Models\MeliFullShipmentItem;
use App\Models\MeliFullStock;
use App\Services\InventoryStockService;
use App\Services\MeliFullShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MeliFullShipmentController extends Controller
{
    public function __construct(
        private readonly MeliFullShipmentService $shipmentService,
        private readonly InventoryStockService $stockService
    ) {}

    /**
     * Dashboard & listado de envíos MeLi FULL
     */
    public function index(Request $request): Response
    {
        $query = MeliFullShipment::query()
            ->with(['user:id,name', 'boxes'])
            ->withCount(['items', 'boxes']);

        if ($request->filled('status') && $request->input('status') !== 'ALL') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('carrier')) {
            $query->where('envia_carrier', $request->input('carrier'));
        }

        if ($request->filled('warehouse')) {
            $query->where('meli_warehouse_code', $request->input('warehouse'));
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('shipment_code', 'like', "%{$s}%")
                    ->orWhere('envia_tracking_number', 'like', "%{$s}%")
                    ->orWhere('meli_shipment_id', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%");
            });
        }

        $shipments = $query->orderByDesc('id')->paginate(15)->withQueryString();

        // Obtener KPIs de monitoreo integral FULL
        $kpis = $this->shipmentService->getKpiSummary();

        return Inertia::render('MeliFullShipments/Index', [
            'shipments' => $shipments,
            'kpis' => $kpis,
            'warehouses' => MeliFullShipment::WAREHOUSES,
            'carriers' => MeliFullShipment::CARRIERS,
            'filters' => $request->only(['status', 'carrier', 'warehouse', 'search']),
        ]);
    }

    /**
     * Formulario para armado de cajas de 30 kg, bultos y nuevo envío FULL
     */
    public function create(): Response
    {
        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->select(['id', 'sku', 'barcode', 'name', 'brand', 'weight_kg', 'requires_meli_labeling'])
            ->orderBy('brand')
            ->orderBy('name')
            ->get()
            ->map(function ($p) {
                $p->available_stock = $this->stockService->availableStock($p);
                $p->weight_kg = (float) ($p->weight_kg ?: 1.000);
                $p->requires_meli_labeling = (bool) $p->requires_meli_labeling;
                return $p;
            });

        $recommendations = $this->shipmentService->getRestockRecommendations(50);

        return Inertia::render('MeliFullShipments/Create', [
            'nextShipmentCode' => $this->shipmentService->generateShipmentCode(),
            'warehouses' => MeliFullShipment::WAREHOUSES,
            'carriers' => MeliFullShipment::CARRIERS,
            'products' => $products,
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * Guardar nuevo envío con sus cajas de 30 kg y bultos
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shipment_code' => ['nullable', 'string', 'max:50'],
            'meli_warehouse_code' => ['required', 'string', 'max:20'],
            'meli_warehouse_name' => ['nullable', 'string', 'max:150'],
            'meli_shipment_id' => ['nullable', 'string', 'max:50'],
            'envia_carrier' => ['nullable', 'string', 'max:50'],
            'envia_tracking_number' => ['nullable', 'string', 'max:100'],
            'envia_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'boxes' => ['required', 'array', 'min:1'],
            'boxes.*.box_number' => ['required', 'integer', 'min:1'],
            'boxes.*.bulto_number' => ['nullable', 'integer', 'min:1'],
            'boxes.*.boxes_in_bulto' => ['nullable', 'integer', 'min:1'],
            'boxes.*.capacity' => ['nullable', 'integer', 'min:1'],
            'boxes.*.capacity_kg' => ['nullable', 'numeric', 'min:1'],
            'boxes.*.dimensions' => ['nullable', 'string', 'max:50'],
            'boxes.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'boxes.*.items' => ['nullable', 'array'],
            'boxes.*.items.*.inventory_product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'boxes.*.items.*.sku' => ['nullable', 'string', 'max:100'],
            'boxes.*.items.*.product_name' => ['nullable', 'string', 'max:255'],
            'boxes.*.items.*.quantity_sent' => ['required', 'integer', 'min:1'],
            'boxes.*.items.*.requires_labeling' => ['nullable', 'boolean'],
            'boxes.*.items.*.unit_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $shipment = $this->shipmentService->createShipment($validated, $request->user());

            return redirect()->route('meli-full-shipments.show', $shipment->id)
                ->with('success', "Envío {$shipment->shipment_code} creado exitosamente con {$shipment->total_boxes} cajas y {$shipment->total_units} unidades.");
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Error al crear el envío: ' . $e->getMessage());
        }
    }

    /**
     * Vista de detalle del envío, cajas de 30 y tracking
     */
    public function show(MeliFullShipment $shipment): Response
    {
        $shipment->load([
            'user:id,name,email',
            'boxes' => function ($q) {
                $q->orderBy('box_number');
            },
            'boxes.items' => function ($q) {
                $q->with('inventoryProduct:id,sku,name,brand,weight_kg');
            },
            'items' => function ($q) {
                $q->with('inventoryProduct:id,sku,name,brand,weight_kg');
            },
        ]);

        return Inertia::render('MeliFullShipments/Show', [
            'shipment' => $shipment,
            'warehouses' => MeliFullShipment::WAREHOUSES,
            'carriers' => MeliFullShipment::CARRIERS,
        ]);
    }

    /**
     * Formulario de edición
     */
    public function edit(MeliFullShipment $shipment): Response|RedirectResponse
    {
        if (! in_array($shipment->status, [MeliFullShipment::STATUS_DRAFT, MeliFullShipment::STATUS_PACKED], true)) {
            return redirect()->route('meli-full-shipments.show', $shipment->id)
                ->with('error', 'No se puede editar un envío que ya fue despachado o recibido.');
        }

        $shipment->load(['boxes.items.inventoryProduct']);

        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->select(['id', 'sku', 'barcode', 'name', 'brand', 'weight_kg', 'requires_meli_labeling'])
            ->orderBy('brand')
            ->orderBy('name')
            ->get()
            ->map(function ($p) {
                $p->available_stock = $this->stockService->availableStock($p);
                $p->weight_kg = (float) ($p->weight_kg ?: 1.000);
                $p->requires_meli_labeling = (bool) $p->requires_meli_labeling;
                return $p;
            });

        $recommendations = $this->shipmentService->getRestockRecommendations(50);

        return Inertia::render('MeliFullShipments/Edit', [
            'shipment' => $shipment,
            'warehouses' => MeliFullShipment::WAREHOUSES,
            'carriers' => MeliFullShipment::CARRIERS,
            'products' => $products,
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * Actualizar envío existente
     */
    public function update(Request $request, MeliFullShipment $shipment): RedirectResponse
    {
        $validated = $request->validate([
            'meli_warehouse_code' => ['required', 'string', 'max:20'],
            'meli_warehouse_name' => ['nullable', 'string', 'max:150'],
            'meli_shipment_id' => ['nullable', 'string', 'max:50'],
            'envia_carrier' => ['nullable', 'string', 'max:50'],
            'envia_tracking_number' => ['nullable', 'string', 'max:100'],
            'envia_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'boxes' => ['nullable', 'array'],
            'boxes.*.box_number' => ['required', 'integer', 'min:1'],
            'boxes.*.bulto_number' => ['nullable', 'integer', 'min:1'],
            'boxes.*.boxes_in_bulto' => ['nullable', 'integer', 'min:1'],
            'boxes.*.capacity' => ['nullable', 'integer', 'min:1'],
            'boxes.*.capacity_kg' => ['nullable', 'numeric', 'min:1'],
            'boxes.*.dimensions' => ['nullable', 'string', 'max:50'],
            'boxes.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'boxes.*.items' => ['nullable', 'array'],
            'boxes.*.items.*.inventory_product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'boxes.*.items.*.sku' => ['nullable', 'string', 'max:100'],
            'boxes.*.items.*.product_name' => ['nullable', 'string', 'max:255'],
            'boxes.*.items.*.quantity_sent' => ['required', 'integer', 'min:1'],
            'boxes.*.items.*.requires_labeling' => ['nullable', 'boolean'],
            'boxes.*.items.*.unit_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $updated = $this->shipmentService->updateShipment($shipment, $validated, $request->user());

            return redirect()->route('meli-full-shipments.show', $updated->id)
                ->with('success', 'Envío actualizado correctamente.');
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Error al actualizar el envío: ' . $e->getMessage());
        }
    }

    /**
     * Despachar envío: Registra salida física del almacén local (TRANSFER_OUT)
     */
    public function dispatch(Request $request, MeliFullShipment $shipment): RedirectResponse
    {
        try {
            $this->shipmentService->dispatchShipment($shipment, $request->user());

            return back()->with('success', "¡Envío {$shipment->shipment_code} puesto en tránsito! Se ha registrado la salida de {$shipment->total_units} unidades del almacén local.");
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo despachar el envío: ' . $e->getMessage());
        }
    }

    /**
     * Revertir salida del almacén local (TRANSFER_IN)
     */
    public function revert(Request $request, MeliFullShipment $shipment): RedirectResponse
    {
        try {
            $this->shipmentService->revertDispatch($shipment, $request->user());

            return back()->with('success', "Salida del envío {$shipment->shipment_code} revertida. Las unidades fueron reintegradas al almacén local.");
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo revertir la salida: ' . $e->getMessage());
        }
    }

    /**
     * Registrar recepción / inspección MeLi (unidades conformes, dañadas y faltantes)
     */
    public function receive(Request $request, MeliFullShipment $shipment): RedirectResponse
    {
        $validated = $request->validate([
            'receptions' => ['required', 'array'],
            'receptions.*.item_id' => ['required', 'integer'],
            'receptions.*.quantity_received' => ['required', 'integer', 'min:0'],
            'receptions.*.quantity_damaged' => ['nullable', 'integer', 'min:0'],
            'receptions.*.quantity_missing' => ['nullable', 'integer', 'min:0'],
            'receptions.*.notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->shipmentService->receiveShipment($shipment, $validated['receptions'], $validated['notes'] ?? null);

            $msg = "Recepción guardada con éxito. ";
            if ($updated->total_units_damaged > 0 || $updated->total_units_missing > 0) {
                $msg .= "Se registraron discrepancias: {$updated->total_units_damaged} piezas dañadas y {$updated->total_units_missing} faltantes.";
            } else {
                $msg .= "Todas las unidades ({$updated->total_units_received}) ingresaron completas al CEDIS MeLi.";
            }

            return back()->with('success', $msg);
        } catch (Throwable $e) {
            return back()->with('error', 'Error al procesar la recepción: ' . $e->getMessage());
        }
    }

    /**
     * Vista de impresión de rótulos de caja de 30 kg / bultos máster MeLi
     */
    public function printLabels(MeliFullShipment $shipment, ?MeliFullShipmentBox $box = null): \Illuminate\View\View
    {
        $shipment->load(['boxes.items.inventoryProduct']);
        $boxes = $box ? collect([$box->load('items.inventoryProduct')]) : $shipment->boxes;

        return view('labels.meli_full_box', [
            'shipment' => $shipment,
            'boxes' => $boxes,
        ]);
    }

    /**
     * Vista de impresión de etiquetas térmicas individuales de producto para MeLi FULL
     */
    public function printProductLabels(MeliFullShipment $shipment): \Illuminate\View\View
    {
        $shipment->load(['items.inventoryProduct']);
        $items = $shipment->items->where('requires_labeling', true);

        return view('labels.meli_full_product', [
            'shipment' => $shipment,
            'items' => $items,
        ]);
    }

    /**
     * Mesa de Empaque Caja por Caja (30 kg máx) con báscula visual y descuento inmediato
     */
    public function packStation(Request $request): Response
    {
        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->select(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'weight_kg', 'requires_meli_labeling'])
            ->orderBy('name')
            ->get()
            ->map(function ($p) {
                $p->available_stock = $this->stockService->availableStock($p);
                $p->weight_kg = (float) ($p->weight_kg ?: 1.000);
                $p->requires_meli_labeling = (bool) $p->requires_meli_labeling;
                return $p;
            });

        $meliFullStocks = [];
        if (Schema::hasTable('meli_full_stocks')) {
            $meliFullStocks = MeliFullStock::query()
                ->select(['id', 'mlm', 'variation_id', 'sku', 'title', 'full_available_quantity', 'thumbnail', 'permalink'])
                ->orderBy('title')
                ->get();
        }

        $channelLinks = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->select(['id', 'inventory_product_id', 'external_listing_id', 'external_variant_id'])
            ->get();

        $channelLinkMap = [];
        foreach ($channelLinks as $link) {
            $key = strtoupper((string) $link->external_listing_id) . ($link->external_variant_id ? '_' . $link->external_variant_id : '');
            $channelLinkMap[$key] = $link->inventory_product_id;
        }

        $pastLinks = MeliFullShipmentItem::query()
            ->whereNotNull('inventory_product_id')
            ->whereNotNull('mlm')
            ->pluck('inventory_product_id', 'mlm')
            ->all();

        $productsBySku = $products->keyBy(fn ($p) => strtoupper((string) $p->sku));
        $productsByBarcode = $products->filter(fn ($p) => ! empty($p->barcode))->keyBy(fn ($p) => (string) $p->barcode);

        $mappedFullStocks = collect($meliFullStocks)->map(function ($stock) use ($channelLinkMap, $pastLinks, $productsBySku, $productsByBarcode) {
            $mlm = strtoupper(trim((string) $stock->mlm));
            $var = $stock->variation_id ? trim((string) $stock->variation_id) : '';
            $key = $mlm . ($var ? '_' . $var : '');

            $linkedId = $channelLinkMap[$key]
                ?? $channelLinkMap[$mlm]
                ?? $pastLinks[$mlm]
                ?? null;

            if (! $linkedId && ! empty($stock->sku)) {
                $stockSku = strtoupper(trim((string) $stock->sku));
                $linkedId = $productsBySku[$stockSku]?->id ?? $productsByBarcode[$stockSku]?->id ?? null;
            }

            $stock->linked_product_id = $linkedId;
            return $stock;
        });

        // Envíos activos en preparación (borradores o empacados)
        $draftShipments = MeliFullShipment::query()
            ->whereIn('status', [MeliFullShipment::STATUS_DRAFT, MeliFullShipment::STATUS_PACKED])
            ->with(['boxes' => function ($q) {
                $q->orderBy('box_number')->with('items.inventoryProduct');
            }, 'user:id,name'])
            ->orderByDesc('id')
            ->get();

        $selectedShipmentId = (int) $request->input('shipment_id', 0);
        $selectedShipment = null;

        if ($selectedShipmentId > 0) {
            $selectedShipment = $draftShipments->firstWhere('id', $selectedShipmentId)
                ?? MeliFullShipment::with(['boxes' => function ($q) {
                    $q->orderBy('box_number')->with('items.inventoryProduct');
                }, 'user:id,name'])->find($selectedShipmentId);
        }

        if (! $selectedShipment && $draftShipments->isNotEmpty()) {
            $selectedShipment = $draftShipments->first();
        }

        // Si no existe ningún envío en borrador, generar uno nuevo para empacar de inmediato
        if (! $selectedShipment) {
            $selectedShipment = $this->shipmentService->createShipment([
                'shipment_code' => $this->shipmentService->generateShipmentCode(),
                'meli_warehouse_code' => 'MXCD01',
                'notes' => 'Envío inicial generado desde la mesa de empaque caja por caja',
                'boxes' => [],
            ], $request->user());
            $selectedShipment->load(['boxes.items.inventoryProduct', 'user:id,name']);
            $draftShipments = collect([$selectedShipment]);
        }

        return Inertia::render('MeliFullShipments/PackStation', [
            'shipment' => $selectedShipment,
            'draftShipments' => $draftShipments,
            'products' => $products,
            'meliFullStocks' => $mappedFullStocks,
            'warehouses' => MeliFullShipment::WAREHOUSES,
            'carriers' => MeliFullShipment::CARRIERS,
            'nextShipmentCode' => $this->shipmentService->generateShipmentCode(),
        ]);
    }

    /**
     * Guardar y sellar una caja individual en el envío actual con descuento inmediato de stock
     */
    public function storeBox(Request $request, MeliFullShipment $shipment): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'box_number' => ['nullable', 'integer', 'min:1'],
            'bulto_number' => ['nullable', 'integer', 'min:1'],
            'boxes_in_bulto' => ['nullable', 'integer', 'min:1'],
            'capacity_kg' => ['nullable', 'numeric', 'min:1'],
            'dimensions' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.mlm' => ['nullable', 'string', 'max:50'],
            'items.*.variation_id' => ['nullable', 'string', 'max:50'],
            'items.*.quantity_sent' => ['required', 'integer', 'min:1'],
            'items.*.requires_labeling' => ['nullable', 'boolean'],
            'items.*.unit_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        try {
            $box = $this->shipmentService->packBox($shipment, $validated, $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "¡Caja #{$box->box_number} sellada con éxito! Se descontaron {$box->units_count} unidades del almacén local.",
                    'box' => $box,
                    'shipment' => $shipment->fresh(['boxes.items.inventoryProduct']),
                ]);
            }

            return redirect()->route('meli-full-shipments.pack-station', ['shipment_id' => $shipment->id])
                ->with('success', "¡Caja #{$box->box_number} sellada! {$box->units_count} piezas descontadas del almacén local.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }
            return back()->with('error', 'Error al guardar la caja: ' . $e->getMessage());
        }
    }

    /**
     * Desempacar y eliminar una caja, reintegrando su inventario físico
     */
    public function destroyBox(Request $request, MeliFullShipment $shipment, MeliFullShipmentBox $box): JsonResponse|RedirectResponse
    {
        if ($box->meli_full_shipment_id !== $shipment->id) {
            abort(404, 'La caja no pertenece a este envío.');
        }

        try {
            $boxNumber = $box->box_number;
            $units = $box->units_count;
            $this->shipmentService->unpackBox($box, $request->user());

            $msg = "Caja #{$boxNumber} eliminada. {$units} unidades reintegradas al almacén local.";

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'shipment' => $shipment->fresh(['boxes.items.inventoryProduct']),
                ]);
            }

            return back()->with('success', $msg);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }
            return back()->with('error', 'Error al eliminar caja: ' . $e->getMessage());
        }
    }

    /**
     * Vincular una publicación FULL con un producto de inventario existente
     */
    public function linkProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'mlm' => ['required', 'string', 'max:50'],
            'variation_id' => ['nullable', 'string', 'max:50'],
            'meli_sku' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $link = $this->shipmentService->linkProductToMeli(
                $validated['inventory_product_id'],
                $validated['mlm'],
                $validated['variation_id'] ?? null,
                $validated['meli_sku'] ?? null,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'message' => 'Producto vinculado exitosamente a la publicación FULL.',
                'link' => $link,
                'product' => $link->product,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Alta rápida de un producto en inventario local desde la mesa de empaque
     */
    public function quickCreateProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.01'],
            'initial_stock' => ['nullable', 'integer', 'min:0'],
            'mlm' => ['nullable', 'string', 'max:50'],
            'variation_id' => ['nullable', 'string', 'max:50'],
            'meli_sku' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $product = $this->shipmentService->quickCreateProduct($validated, $request->user());

            return response()->json([
                'success' => true,
                'message' => "Producto {$product->sku} creado con éxito y vinculado a FULL.",
                'product' => $product,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
