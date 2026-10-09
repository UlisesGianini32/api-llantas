<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryProductRequest;
use App\Http\Requests\UpdateInventoryProductRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\Supplier;
use App\Services\InventoryKitStockService;
use App\Services\InventoryStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryProductController extends Controller
{
    public function index(Request $request, InventoryKitStockService $kitStock): Response
    {
        $search = trim((string) $request->input('search', ''));
        $brand = trim((string) $request->input('brand', ''));

        $query = InventoryProduct::query()
            ->with(['primaryLocation:id,code,name,amazon_aisle,is_active', 'secondaryLocation:id,code,name,amazon_aisle,is_active', 'kitComponents.component:id,name,sku'])
            ->withSum('movements as physical_stock', 'quantity')
            ->withSum([
                'reservations as reserved_stock' => fn ($q) => $q
                    ->where('status', InventoryReservation::ACTIVE),
            ], 'quantity')
            ->when($brand !== '' && mb_strtoupper($brand) !== 'TODAS', function ($q) use ($brand): void {
                $q->whereRaw('UPPER(TRIM(brand)) = ?', [mb_strtoupper($brand)]);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('barcode_secondary', 'like', "%{$search}%")
                        ->orWhere('reserve_notes', 'like', "%{$search}%")
                        ->orWhereHas('primaryLocation', function ($location) use ($search): void {
                            $location->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('secondaryLocation', function ($location) use ($search): void {
                            $location->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('name');

        $products = $query->paginate(25)->withQueryString();

        $kitStocks = $kitStock->stocksForKits($products->getCollection());
        $products->getCollection()->each(function (InventoryProduct $product) use ($kitStocks): void {
            if ($product->isKit()) {
                $stock = $kitStocks->get($product->getKey(), ['physical_stock' => 0, 'available_stock' => 0]);
                $physical = $stock['physical_stock'];
                $available = $stock['available_stock'];
                $product->setAttribute('physical_stock', $physical);
                $product->setAttribute('reserved_stock', max(0, $physical - $available));
                $product->setAttribute('available_stock', $available);
            } else {
                $product->setAttribute('available_stock', (int) $product->physical_stock - (int) ($product->reserved_stock ?? 0));
            }
        });

        $brands = InventoryProduct::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->selectRaw('UPPER(TRIM(brand)) as clean_brand, COUNT(*) as count')
            ->groupBy(DB::raw('UPPER(TRIM(brand))'))
            ->orderBy('clean_brand')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->clean_brand,
                'count' => (int) $r->count,
            ])
            ->values()
            ->all();

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'brand' => $brand,
            ],
            'brands' => $brands,
        ]);
    }

    /**
     * Exportar reporte de inventario en Excel (CSV UTF-8 compatible con acentos y fórmulas)
     */
    public function export(Request $request, InventoryKitStockService $kitStockService): StreamedResponse
    {
        $brand = trim((string) $request->input('brand', ''));
        $search = trim((string) $request->input('search', ''));
        $status = trim((string) $request->input('status', 'all'));

        $query = InventoryProduct::query()
            ->with(['primaryLocation:id,code,name', 'secondaryLocation:id,code,name', 'kitComponents.component:id,name,sku'])
            ->withSum('movements as physical_stock', 'quantity')
            ->withSum([
                'reservations as reserved_stock' => fn ($q) => $q->where('status', InventoryReservation::ACTIVE),
            ], 'quantity');

        if ($brand !== '' && mb_strtoupper($brand) !== 'TODAS') {
            $query->whereRaw('UPPER(TRIM(brand)) = ?', [mb_strtoupper($brand)]);
        }

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('barcode_secondary', 'like', "%{$search}%")
                    ->orWhere('reserve_notes', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $kits = trim((string) $request->input('kits', 'all'));
        if ($kits === 'exclude') {
            $query->where(function ($q): void {
                $q->whereNull('product_type')
                    ->orWhere('product_type', '!=', InventoryProduct::KIT);
            });
        } elseif ($kits === 'only') {
            $query->where('product_type', InventoryProduct::KIT);
        }

        $products = $query->orderBy('brand')->orderBy('name')->get();

        $kitStocks = $kitStockService->stocksForKits($products);
        $products->each(function (InventoryProduct $product) use ($kitStocks): void {
            if ($product->isKit()) {
                $stock = $kitStocks->get($product->getKey(), ['physical_stock' => 0, 'available_stock' => 0]);
                $physical = (int) $stock['physical_stock'];
                $available = (int) $stock['available_stock'];
                $product->setAttribute('physical_stock', $physical);
                $product->setAttribute('reserved_stock', max(0, $physical - $available));
                $product->setAttribute('available_stock', $available);
            } else {
                $physical = (int) ($product->physical_stock ?? 0);
                $reserved = (int) ($product->reserved_stock ?? 0);
                $product->setAttribute('physical_stock', $physical);
                $product->setAttribute('reserved_stock', $reserved);
                $product->setAttribute('available_stock', $physical - $reserved);
            }
        });

        if ($status === 'with_stock') {
            $products = $products->filter(fn ($p) => (int) $p->physical_stock > 0);
        } elseif ($status === 'zero_stock') {
            $products = $products->filter(fn ($p) => (int) $p->physical_stock <= 0);
        }

        $cleanBrandName = $brand !== '' && mb_strtoupper($brand) !== 'TODAS'
            ? Str::slug($brand)
            : 'general';
        $filename = 'reporte-inventario-' . $cleanBrandName . '-' . date('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($products): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 para Excel

            fputcsv($handle, [
                'SKU',
                'Producto / Nombre',
                'Marca',
                'Proveedor',
                'Tipo Producto',
                'Código de Barras Principal',
                'Código de Barras Secundario',
                'Ubicación Primaria',
                'Ubicación Secundaria',
                'Stock Físico',
                'Stock Reservado',
                'Stock Disponible',
                'Costo Unitario ($)',
                'Valor Total Almacén ($ Costo x Físico)',
                'Precio Mercado Libre ($)',
                'Precio Amazon ($)',
                'Precio Shopify / Estilista ($)',
                'Precio Público ($)',
                'Estado',
                'Notas de Reserva',
            ]);

            foreach ($products as $p) {
                $physical = (int) ($p->physical_stock ?? 0);
                $reserved = (int) ($p->reserved_stock ?? 0);
                $available = (int) ($p->available_stock ?? ($physical - $reserved));
                $cost = (float) ($p->cost ?? 0);
                $totalValuation = round($cost * $physical, 2);

                fputcsv($handle, [
                    $p->sku,
                    $p->name,
                    $p->brand ?: 'SIN MARCA',
                    $p->supplier ?: '—',
                    $p->isKit() ? 'KIT' : 'SIMPLE',
                    $p->barcode ?: '—',
                    $p->barcode_secondary ?: '—',
                    $p->primaryLocation ? $p->primaryLocation->code : 'SIN UBICACIÓN',
                    $p->secondaryLocation ? $p->secondaryLocation->code : '—',
                    $physical,
                    $reserved,
                    $available,
                    number_format($cost, 2, '.', ''),
                    number_format($totalValuation, 2, '.', ''),
                    $p->price_mercado_libre ? number_format((float) $p->price_mercado_libre, 2, '.', '') : '—',
                    $p->price_amazon ? number_format((float) $p->price_amazon, 2, '.', '') : '—',
                    $p->price_stylist ? number_format((float) $p->price_stylist, 2, '.', '') : '—',
                    $p->price_public ? number_format((float) $p->price_public, 2, '.', '') : '—',
                    $p->is_active ? 'ACTIVO' : 'INACTIVO',
                    $p->reserve_notes ?: '—',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Reporte imprimible / PDF con membrete oficial SBS
     */
    public function reportPdf(Request $request, InventoryKitStockService $kitStockService): View
    {
        $brand = trim((string) $request->input('brand', ''));
        $search = trim((string) $request->input('search', ''));
        $status = trim((string) $request->input('status', 'all'));

        $query = InventoryProduct::query()
            ->with(['primaryLocation:id,code,name', 'secondaryLocation:id,code,name', 'kitComponents.component:id,name,sku'])
            ->withSum('movements as physical_stock', 'quantity')
            ->withSum([
                'reservations as reserved_stock' => fn ($q) => $q->where('status', InventoryReservation::ACTIVE),
            ], 'quantity');

        if ($brand !== '' && mb_strtoupper($brand) !== 'TODAS') {
            $query->whereRaw('UPPER(TRIM(brand)) = ?', [mb_strtoupper($brand)]);
        }

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('barcode_secondary', 'like', "%{$search}%")
                    ->orWhere('reserve_notes', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $kits = trim((string) $request->input('kits', 'all'));
        if ($kits === 'exclude') {
            $query->where(function ($q): void {
                $q->whereNull('product_type')
                    ->orWhere('product_type', '!=', InventoryProduct::KIT);
            });
        } elseif ($kits === 'only') {
            $query->where('product_type', InventoryProduct::KIT);
        }

        $products = $query->orderBy('brand')->orderBy('name')->get();

        $kitStocks = $kitStockService->stocksForKits($products);
        $products->each(function (InventoryProduct $product) use ($kitStocks): void {
            if ($product->isKit()) {
                $stock = $kitStocks->get($product->getKey(), ['physical_stock' => 0, 'available_stock' => 0]);
                $physical = (int) $stock['physical_stock'];
                $available = (int) $stock['available_stock'];
                $product->setAttribute('physical_stock', $physical);
                $product->setAttribute('reserved_stock', max(0, $physical - $available));
                $product->setAttribute('available_stock', $available);
            } else {
                $physical = (int) ($product->physical_stock ?? 0);
                $reserved = (int) ($product->reserved_stock ?? 0);
                $product->setAttribute('physical_stock', $physical);
                $product->setAttribute('reserved_stock', $reserved);
                $product->setAttribute('available_stock', $physical - $reserved);
            }
        });

        if ($status === 'with_stock') {
            $products = $products->filter(fn ($p) => (int) $p->available_stock > 0);
        } elseif ($status === 'zero_stock') {
            $products = $products->filter(fn ($p) => (int) $p->available_stock <= 0);
        }

        $totalSkus = $products->count();
        $totalAvailableUnits = $products->sum(fn ($p) => (int) ($p->available_stock ?? 0));
        $withStockCount = $products->filter(fn ($p) => (int) ($p->available_stock ?? 0) > 0)->count();
        $zeroStockCount = $totalSkus - $withStockCount;

        return view('inventory.report-pdf', [
            'products' => $products,
            'selectedBrand' => $brand !== '' && mb_strtoupper($brand) !== 'TODAS' ? mb_strtoupper($brand) : 'GENERAL (TODO EL CATÁLOGO)',
            'statusFilter' => $status,
            'kitsFilter' => $kits,
            'totalSkus' => $totalSkus,
            'totalAvailableUnits' => $totalAvailableUnits,
            'withStockCount' => $withStockCount,
            'zeroStockCount' => $zeroStockCount,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'mode' => 'create',
            'product' => null,
            'locations' => $this->locationOptions(),
            'suppliers' => \Illuminate\Support\Facades\Schema::hasTable('suppliers')
                ? Supplier::query()->where('is_active', true)->pluck('name')
                : [],
        ]);
    }

    public function store(StoreInventoryProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $type = $data['product_type'] ?? InventoryProduct::SIMPLE;
        if ($type === InventoryProduct::KIT) {
            $data['primary_location_id'] = null;
            $data['secondary_location_id'] = null;
            $data['reserve_notes'] = null;
        }
        InventoryProduct::create([...$data, 'product_type' => $type]);

        return redirect()->route('inventory.products.index')->with('success', 'Producto creado correctamente.');
    }

    public function show(
        InventoryProduct $inventoryProduct,
        InventoryStockService $stock,
        InventoryKitStockService $kitStock,
    ): Response {
        $inventoryProduct->load([
            'primaryLocation:id,code,name,amazon_aisle,is_active',
            'secondaryLocation:id,code,name,amazon_aisle,is_active',
            'kitComponents.component.primaryLocation:id,code,name,amazon_aisle',
            'kitComponents.component.secondaryLocation:id,code,name,amazon_aisle',
            'channelLinks',
        ]);
        $movements = $inventoryProduct->movements()
            ->with([
                'location:id,code,name',
                'createdBy:id,name',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $physicalByLocation = $stock->productStockByLocation($inventoryProduct)
            ->keyBy('inventory_location_id');
        $reservedByLocation = InventoryReservation::query()
            ->active()
            ->where('inventory_product_id', $inventoryProduct->getKey())
            ->whereNotNull('inventory_location_id')
            ->select('inventory_location_id')
            ->selectRaw('SUM(quantity) as quantity')
            ->groupBy('inventory_location_id')
            ->get()
            ->keyBy('inventory_location_id');
        $locationIds = $physicalByLocation->keys()
            ->merge($reservedByLocation->keys())
            ->unique()
            ->values();
        $locations = InventoryLocation::query()
            ->whereIn('id', $locationIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');
        $stockByLocation = $locationIds->map(function ($locationId) use (
            $physicalByLocation,
            $reservedByLocation,
            $locations,
        ): array {
            $physical = (int) ($physicalByLocation[$locationId]->quantity ?? 0);
            $reserved = (int) ($reservedByLocation[$locationId]->quantity ?? 0);

            return [
                'inventory_location_id' => (int) $locationId,
                'location' => $locations[$locationId] ?? null,
                'physical_stock' => $physical,
                'reserved_stock' => $reserved,
                'available_stock' => $physical - $reserved,
            ];
        })->values();
        $reservations = $inventoryProduct->reservations()
            ->active()
            ->with('location:id,code,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $isKit = $inventoryProduct->isKit();
        $physicalStock = $isKit ? $kitStock->physicalStock($inventoryProduct) : $stock->physicalStock($inventoryProduct);
        $availableStock = $isKit ? $kitStock->availableStock($inventoryProduct) : $stock->availableStock($inventoryProduct);
        $kitComponents = $isKit ? $kitStock->componentSummary($inventoryProduct) : [];

        return Inertia::render('Inventory/Products/Show', [
            'product' => $inventoryProduct,
            'physicalStock' => $physicalStock,
            'reservedStock' => $isKit ? max(0, $physicalStock - $availableStock) : $stock->reservedStock($inventoryProduct),
            'availableStock' => $availableStock,
            'kitComponents' => $kitComponents,
            'channelLinks' => $inventoryProduct->channelLinks,
            'stockByLocation' => $stockByLocation,
            'movements' => $movements,
            'reservations' => $reservations,
        ]);
    }

    public function edit(InventoryProduct $inventoryProduct): Response
    {
        $inventoryProduct->load([
            'primaryLocation:id,code,name,amazon_aisle,is_active',
            'secondaryLocation:id,code,name,amazon_aisle,is_active',
            'kitComponents.component.primaryLocation:id,code,name,amazon_aisle',
            'kitComponents.component.secondaryLocation:id,code,name,amazon_aisle',
        ]);

        return Inertia::render('Inventory/Products/Form', [
            'mode' => 'edit',
            'product' => $inventoryProduct,
            'locations' => $this->locationOptions($inventoryProduct),
            'suppliers' => \Illuminate\Support\Facades\Schema::hasTable('suppliers')
                ? Supplier::query()->where('is_active', true)->pluck('name')
                : [],
        ]);
    }

    public function update(UpdateInventoryProductRequest $request, InventoryProduct $inventoryProduct): RedirectResponse
    {
        $data = $request->validated();
        $targetType = $data['product_type'] ?? $inventoryProduct->product_type;
        if ($targetType !== $inventoryProduct->product_type) {
            $physical = app(InventoryStockService::class)->physicalStock($inventoryProduct);
            $reserved = app(InventoryStockService::class)->reservedStock($inventoryProduct);
            if ($inventoryProduct->isSimple() && $targetType === InventoryProduct::KIT && ($physical !== 0 || $reserved !== 0)) {
                return back()->withInput()->withErrors(['product_type' => 'No se puede convertir a kit mientras tenga stock físico o reservas activas.']);
            }
            if ($inventoryProduct->isKit() && $targetType === InventoryProduct::SIMPLE) {
                if ($inventoryProduct->kitComponents()->exists() || $inventoryProduct->kitReservations()->active()->exists()) {
                    return back()->withInput()->withErrors(['product_type' => 'No se puede convertir a producto simple mientras tenga componentes o reservas activas.']);
                }
            }
        }
        if ($targetType === InventoryProduct::KIT) {
            $data['primary_location_id'] = null;
            $data['secondary_location_id'] = null;
            $data['reserve_notes'] = null;
        }
        $inventoryProduct->update($data);

        return redirect()->route('inventory.products.index')->with('success', 'Producto actualizado correctamente.');
    }

    public function toggle(InventoryProduct $inventoryProduct): RedirectResponse
    {
        $inventoryProduct->update(['is_active' => ! $inventoryProduct->is_active]);

        return back()->with('success', $inventoryProduct->is_active ? 'Producto activado.' : 'Producto desactivado.');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, InventoryLocation> */
    private function locationOptions(?InventoryProduct $product = null)
    {
        $currentPrimaryId = $product?->primary_location_id;
        $currentSecondaryId = $product?->secondary_location_id;

        return InventoryLocation::query()
            ->where(function ($query) use ($currentPrimaryId, $currentSecondaryId): void {
                $query->where('is_active', true);
                if ($currentPrimaryId) {
                    $query->orWhere('id', $currentPrimaryId);
                }
                if ($currentSecondaryId) {
                    $query->orWhere('id', $currentSecondaryId);
                }
            })
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'amazon_aisle', 'is_active']);
    }
}
