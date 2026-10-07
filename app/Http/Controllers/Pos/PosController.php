<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\PosInsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryLocation;
use App\Models\PosSale;
use App\Services\Pos\CustomerCreditService;
use App\Services\Pos\PosReceiptService;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PosController extends Controller
{
    public function __construct(
        private readonly PosSaleService $posSaleService,
        private readonly PosShiftService $posShiftService,
        private readonly PosReceiptService $posReceiptService,
        private readonly CustomerCreditService $creditService
    ) {}

    public function index(Request $request): Response
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'description']);

        $defaultLocation = $this->posSaleService->getDefaultLocation();

        // Cargar stock global por defecto para que refleje el conteo completo del inventario
        $initialProducts = $this->posSaleService->searchProducts('', null);

        $recentSales = PosSale::query()
            ->with([
                'cashier:id,name',
                'customer:id,name,phone,business_name',
                'items:id,pos_sale_id,product_name,sku,quantity,unit_price,subtotal',
            ])
            ->whereDate('created_at', Carbon::today())
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $customers = Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'business_name', 'phone', 'credit_limit', 'credit_days_default']);

        $creditAlerts = $this->creditService->getCreditPortfolioSummary();

        // Buscar turno activo del usuario (en mostrador o en cualquier ubicación)
        $currentShift = $this->posShiftService->getActiveShift($request->user(), null);
        $shiftSummary = $currentShift ? $this->posShiftService->calculateShiftSummary($currentShift) : null;

        return Inertia::render('Pos/Index', [
            'locations' => $locations,
            'defaultLocationId' => null,
            'fallbackLocationId' => $defaultLocation->id,
            'initialProducts' => $initialProducts,
            'recentSales' => $recentSales,
            'customers' => $customers,
            'creditAlerts' => $creditAlerts,
            'currentShift' => $currentShift ? $currentShift->load(['location', 'cashier']) : null,
            'shiftSummary' => $shiftSummary,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = (string) $request->input('q', '');
        $locationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;

        $products = $this->posSaleService->searchProducts($query, $locationId);

        return response()->json([
            'products' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'create_customer' => ['nullable', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_business_name' => ['nullable', 'string', 'max:255'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'customer_credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'in:7,15,30'],
            'customer_type' => ['nullable', 'string', 'in:public,stylist'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer,mixed,credit'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $sale = $this->posSaleService->createSale($validated, $request->user());
            $paperType = $request->input('paper_type', '80mm');
            $receipt = $this->posReceiptService->formatSaleReceipt($sale, $paperType, true);

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Venta {$sale->sale_number} completada correctamente.",
                    'sale' => $sale,
                    'receipt' => $receipt,
                ]);
            }

            return back()
                ->with('success', "Venta {$sale->sale_number} registrada correctamente.")
                ->with('last_sale', $sale);
        } catch (PosInsufficientStockException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['stock' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Error al procesar la venta: '.$e->getMessage(),
                ], 500);
            }

            return back()->with('error', 'Error al procesar la venta: '.$e->getMessage());
        }
    }

    public function receipt(Request $request, PosSale $posSale): JsonResponse
    {
        $paperType = $request->input('paper_type', '80mm');
        $kickDrawer = $request->boolean('kick_drawer', false);
        $receipt = $this->posReceiptService->formatSaleReceipt($posSale, $paperType, $kickDrawer);

        return response()->json([
            'ok' => true,
            'receipt' => $receipt,
        ]);
    }

    public function show(PosSale $posSale): JsonResponse
    {
        $posSale->load([
            'items.product:id,sku,barcode,name,cost',
            'cashier:id,name,email',
            'location:id,code,name',
            'cancelledBy:id,name',
        ]);

        return response()->json([
            'sale' => $posSale,
        ]);
    }

    public function cancel(Request $request, PosSale $posSale): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $cancelledSale = $this->posSaleService->cancelSale(
                $posSale,
                $request->user(),
                $validated['reason']
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Venta {$posSale->sale_number} cancelada e inventario reingresado.",
                    'sale' => $cancelledSale,
                ]);
            }

            return back()->with('success', "Venta {$posSale->sale_number} cancelada exitosamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Error al cancelar la venta: '.$e->getMessage(),
                ], 422);
            }

            return back()->with('error', 'Error al cancelar la venta: '.$e->getMessage());
        }
    }
}
