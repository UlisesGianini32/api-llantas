<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderMeliImageCacheService;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $poService,
        private readonly PurchaseOrderMeliImageResolver $meliImageResolver,
        private readonly PurchaseOrderMeliImageCacheService $meliImageCache,
    ) {}

    public function index(Request $request): Response
    {
        $query = PurchaseOrder::query()
            ->with(['location:id,code,name', 'buyer:id,name'])
            ->withCount('items');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier')) {
            $query->where('supplier_name', 'like', '%'.$request->input('supplier').'%');
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('supplier_name', 'like', "%{$s}%")
                    ->orWhere('supplier_quote_reference', 'like', "%{$s}%");
            });
        }

        $orders = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $summary = [
            'total_orders' => PurchaseOrder::count(),
            'draft_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_DRAFT)->count(),
            'ordered_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_ORDERED)->count(),
            'partial_count' => PurchaseOrder::whereIn('status', [PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_PARTIAL_CLOSED])->count(),
            'received_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_RECEIVED)->count(),
            'total_invested' => (float) PurchaseOrder::whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED])->sum('total_cost'),
        ];

        $suppliers = PurchaseOrder::distinct()->whereNotNull('supplier_name')->pluck('supplier_name');
        $brands = PurchaseOrder::distinct()->whereNotNull('brand')->where('brand', '!=', '')->pluck('brand');

        return Inertia::render('Purchasing/Index', [
            'orders' => $orders,
            'summary' => $summary,
            'suppliers' => $suppliers,
            'brands' => $brands,
            'filters' => $request->only(['status', 'supplier', 'brand', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        $products = InventoryProduct::query()
            ->with([
                'primaryLocation:id,code,name',
                'supplierProducts:id,inventory_product_id,supplier_id,supplier_name,supplier_sku,supplier_cost',
            ])
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->orderBy('brand')
            ->orderBy('name')
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'supplier', 'cost', 'primary_location_id']);

        $registeredSuppliers = Supplier::query()
            ->where('is_active', true)
            ->with('brands')
            ->orderBy('name')
            ->get(['id', 'name', 'contact_name', 'phone', 'email', 'lead_time_days', 'credit_days']);

        return Inertia::render('Purchasing/Create', [
            'locations' => $locations,
            'products' => $products,
            'registeredSuppliers' => $registeredSuppliers,
            'prefillSupplier' => $request->input('supplier', ''),
            'prefillBrand' => $request->input('brand', ''),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:100'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $order = $this->poService->createOrder($validated, $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$order->order_number} creada en borrador.",
                    'order' => $order,
                ]);
            }

            return redirect()->route('purchasing.orders.show', $order)
                ->with('success', "Orden de compra {$order->order_number} creada en borrador.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:100'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $updated = $this->poService->updateOrder($purchaseOrder, $validated, $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$updated->order_number} actualizada exitosamente.",
                    'order' => $updated,
                ]);
            }

            return redirect()->route('purchasing.orders.show', $updated)
                ->with('success', "Orden de compra {$updated->order_number} actualizada exitosamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroyItem(Request $request, PurchaseOrder $purchaseOrder, \App\Models\PurchaseOrderItem $item): JsonResponse|RedirectResponse
    {
        try {
            if ($purchaseOrder->receipts()->exists()) {
                throw new \InvalidArgumentException('No se puede eliminar partidas de una orden que ya tiene recibos.');
            }

            if ($purchaseOrder->items()->count() <= 1) {
                throw new \InvalidArgumentException('No se puede eliminar la única partida de la orden.');
            }

            $deletedQty = $item->quantity_ordered;
            $deletedSubtotal = $item->subtotal;
            $item->delete();

            $purchaseOrder->update([
                'total_items_count' => $purchaseOrder->items()->count(),
                'total_units_ordered' => max(0, $purchaseOrder->total_units_ordered - $deletedQty),
                'subtotal' => max(0, round($purchaseOrder->subtotal - $deletedSubtotal, 2)),
                'total_cost' => max(0, round($purchaseOrder->total_cost - $deletedSubtotal, 2)),
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Partida eliminada de la orden.',
                ]);
            }

            return back()->with('success', 'Partida eliminada de la orden.');
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'location:id,code,name',
            'buyer:id,name,email',
            'cancelledBy:id,name',
            'items.product:id,sku,barcode,barcode_secondary,name,brand,cost,primary_location_id',
            'items.product.primaryLocation:id,code,name',
            'receipts.receivedBy:id,name',
            'receipts.location:id,code,name',
            'receipts.items.product:id,sku,name,primary_location_id',
            'receipts.items.product.primaryLocation:id,code,name',
        ]);

        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->get(['id', 'code', 'name']);

        $supplier = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers')) {
            $supplier = Supplier::query()
                ->where('name', $purchaseOrder->supplier_name)
                ->first(['id', 'name', 'phone', 'contact_name', 'email']);
        }

        $user = $request->user();
        $canViewPdf = $user !== null && $user->can('viewPdf', $purchaseOrder);
        $canManagePhotos = $user !== null && $user->can('managePhotos', $purchaseOrder);
        $sharedPdfUrl = ($user !== null && $user->can('sharePdf', $purchaseOrder))
            ? $this->buildExternalSignedPdfUrl($request, $purchaseOrder)
            : null;

        $resolvedPhotos = $this->meliImageResolver->resolveForOrder($purchaseOrder);

        return Inertia::render('Purchasing/Show', [
            'order' => $purchaseOrder,
            'imageUrlByItemId' => $this->buildItemPhotoUrlsFromResolution($purchaseOrder, $resolvedPhotos, false),
            'photoStatusByItemId' => $this->buildItemPhotoStatusesFromResolution($resolvedPhotos),
            'locations' => $locations,
            'supplier' => $supplier,
            'canViewPdf' => $canViewPdf,
            'canManagePhotos' => $canManagePhotos,
            'sharedPdfUrl' => $sharedPdfUrl,
        ]);
    }

    public function pdf(Request $request, PurchaseOrder $purchaseOrder): HttpResponse
    {
        $isSignedPublicAccess = $request->routeIs('purchasing.orders.public-pdf')
            && $request->hasValidRelativeSignature();

        if (! $isSignedPublicAccess) {
            Gate::authorize('viewPdf', $purchaseOrder);
        }

        $purchaseOrder->load([
            'location:id,code,name',
            'buyer:id,name,email',
            'items.product:id,sku,barcode,barcode_secondary,name,brand,primary_location_id',
            'items.product.primaryLocation:id,code,name',
        ]);

        $supplier = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers')) {
            $supplier = Supplier::query()
                ->where('name', $purchaseOrder->supplier_name)
                ->first(['id', 'name', 'phone', 'contact_name', 'email']);
        }

        $resolvedPhotos = $this->meliImageResolver->resolveForOrder($purchaseOrder);

        return response()
            ->view('purchasing.order-pdf', [
                'order' => $purchaseOrder,
                'imageUrlByItemId' => $this->buildItemPhotoUrlsFromResolution($purchaseOrder, $resolvedPhotos, $isSignedPublicAccess),
                'supplier' => $supplier,
            ])
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
    }

    public function itemPhoto(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): HttpResponse
    {
        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        $isSignedPublicAccess = $request->routeIs('purchasing.orders.public-item-photo')
            && $request->hasValidRelativeSignature();

        if (! $isSignedPublicAccess && $request->user() === null) {
            abort(403);
        }

        $resolution = $this->meliImageResolver->resolveForItem($purchaseOrder, $item);
        if (! is_string($resolution['url']) || $resolution['url'] === '') {
            abort(404);
        }

        if (
            $resolution['source'] === PurchaseOrderMeliImageResolver::SOURCE_MANUAL_UPLOAD
            && is_string($resolution['manual_path'] ?? null)
            && $resolution['manual_path'] !== ''
        ) {
            $asset = $this->meliImageCache->readManualPhoto($resolution['manual_path']);
        } else {
            $asset = $this->meliImageCache->fetch($resolution['url']);
        }

        if ($asset === null) {
            abort(404);
        }

        return response($asset['bytes'], 200)
            ->withHeaders([
                'Content-Type' => $asset['mime'],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=86400',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
    }

    public function previewPhoto(Request $request, PurchaseOrder $purchaseOrder): HttpResponse
    {
        Gate::authorize('managePhotos', $purchaseOrder);

        $trustedUrl = $this->meliImageResolver->trustedUrl($request->query('url'));
        if ($trustedUrl === null) {
            abort(404);
        }

        $asset = $this->meliImageCache->fetch($trustedUrl);
        if ($asset === null) {
            abort(404);
        }

        return response($asset['bytes'], 200)
            ->withHeaders([
                'Content-Type' => $asset['mime'],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=86400',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
    }

    public function itemPhotoCandidates(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        Gate::authorize('managePhotos', $purchaseOrder);

        $query = $request->query('q');
        $queryStr = is_string($query) ? trim($query) : null;

        $resolution = $this->meliImageResolver->resolveForItem($purchaseOrder, $item);
        $candidates = $this->meliImageResolver->discoverCandidatesForItem($item, $queryStr);

        $candidatesWithPreview = array_map(function (array $candidate) use ($purchaseOrder): array {
            $candidate['preview_url'] = route('purchasing.orders.preview-photo', [
                'purchaseOrder' => $purchaseOrder->id,
                'url' => $candidate['url'],
            ], false);

            return $candidate;
        }, $candidates);

        return response()->json([
            'ok' => true,
            'item' => [
                'id' => (int) $item->id,
                'sku' => trim((string) ($item->product?->sku ?: $item->sku)),
                'supplier_sku' => $item->supplier_sku,
                'product_name' => $item->product?->name ?? $item->product_name,
                'photo_source_mode' => $item->getAttribute('photo_source_mode'),
                'approved_mlm' => $item->getAttribute('approved_mlm'),
                'approved_variation_id' => $item->getAttribute('approved_variation_id'),
                'photo_notes' => $item->getAttribute('photo_notes'),
                'rejected_mlms' => $item->getAttribute('rejected_mlms') ?? [],
            ],
            'current_resolution' => $resolution,
            'candidates' => $candidatesWithPreview,
        ]);
    }

    public function approveItemPhoto(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        Gate::authorize('managePhotos', $purchaseOrder);

        $validated = $request->validate([
            'mlm' => ['required', 'string', 'max:40'],
            'variation_id' => ['nullable', 'string', 'max:64'],
            'meli_account_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $variationId = isset($validated['variation_id']) && trim((string) $validated['variation_id']) !== ''
            ? trim((string) $validated['variation_id'])
            : null;
        $meliAccountId = isset($validated['meli_account_id']) ? (int) $validated['meli_account_id'] : null;

        $candidate = $this->meliImageResolver->resolveExplicitCandidate(
            $validated['mlm'],
            $variationId,
            $meliAccountId,
        );

        if ($candidate === null) {
            return response()->json([
                'ok' => false,
                'error' => 'No se encontró una fotografía válida en Mercado Libre para la publicación o variación seleccionada.',
            ], 422);
        }

        $oldManualPath = $item->getAttribute('manual_photo_path');

        $rejected = is_array($item->getAttribute('rejected_mlms')) ? $item->getAttribute('rejected_mlms') : [];
        $rejected = array_values(array_filter(
            $rejected,
            static fn (mixed $entry): bool => strtoupper(trim((string) $entry)) !== $candidate['mlm']
        ));

        $item->update([
            'photo_source_mode' => PurchaseOrderItem::PHOTO_MODE_APPROVED_ML,
            'approved_mlm' => $candidate['mlm'],
            'approved_variation_id' => $candidate['variation_id'],
            'approved_meli_account_id' => $candidate['meli_account_id'],
            'approved_photo_url' => $candidate['url'],
            'manual_photo_path' => null,
            'manual_photo_mime' => null,
            'photo_notes' => isset($validated['notes']) && trim((string) $validated['notes']) !== '' ? trim((string) $validated['notes']) : null,
            'rejected_mlms' => $rejected !== [] ? $rejected : null,
            'photo_updated_by' => $request->user()?->id,
            'photo_updated_at' => now(),
        ]);

        if (is_string($oldManualPath) && $oldManualPath !== '') {
            $this->meliImageCache->deleteManualPhoto($oldManualPath);
        }

        $item->refresh();
        $resolution = $this->meliImageResolver->resolveForItem($purchaseOrder, $item);

        return response()->json([
            'ok' => true,
            'message' => 'Fotografía aprobada para la partida.',
            'resolution' => $resolution,
            'photo_url' => $this->buildSingleItemPhotoUrl($purchaseOrder, $item, false),
        ]);
    }

    public function uploadItemManualPhoto(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        Gate::authorize('managePhotos', $purchaseOrder);

        $validated = $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('image');
        $bytes = $file !== null ? (string) file_get_contents($file->getRealPath()) : '';
        $stored = $this->meliImageCache->storeManualPhoto((int) $purchaseOrder->id, (int) $item->id, $bytes);

        if ($stored === null) {
            return response()->json([
                'ok' => false,
                'error' => 'El archivo no es una imagen válida (JPG, PNG, WEBP o GIF de máximo 5 MB).',
            ], 422);
        }

        $oldManualPath = $item->getAttribute('manual_photo_path');

        $item->update([
            'photo_source_mode' => PurchaseOrderItem::PHOTO_MODE_MANUAL,
            'manual_photo_path' => $stored['path'],
            'manual_photo_mime' => $stored['mime'],
            'approved_mlm' => null,
            'approved_variation_id' => null,
            'approved_meli_account_id' => null,
            'approved_photo_url' => null,
            'photo_notes' => isset($validated['notes']) && trim((string) $validated['notes']) !== '' ? trim((string) $validated['notes']) : null,
            'photo_updated_by' => $request->user()?->id,
            'photo_updated_at' => now(),
        ]);

        if (is_string($oldManualPath) && $oldManualPath !== '' && $oldManualPath !== $stored['path']) {
            $this->meliImageCache->deleteManualPhoto($oldManualPath);
        }

        $item->refresh();
        $resolution = $this->meliImageResolver->resolveForItem($purchaseOrder, $item);

        return response()->json([
            'ok' => true,
            'message' => 'Imagen manual guardada correctamente.',
            'resolution' => $resolution,
            'photo_url' => $this->buildSingleItemPhotoUrl($purchaseOrder, $item, false),
        ]);
    }

    public function rejectItemPhoto(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        Gate::authorize('managePhotos', $purchaseOrder);

        $validated = $request->validate([
            'mlm' => ['nullable', 'string', 'max:40'],
            'mark_no_photo' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $mlm = isset($validated['mlm']) ? strtoupper(trim((string) $validated['mlm'])) : '';
        $markNoPhoto = (bool) ($validated['mark_no_photo'] ?? ($mlm === ''));

        $rejected = is_array($item->getAttribute('rejected_mlms')) ? $item->getAttribute('rejected_mlms') : [];
        if ($mlm !== '') {
            $rejected[] = $mlm;
            $rejected = array_values(array_unique(array_map(
                static fn (mixed $entry): string => strtoupper(trim((string) $entry)),
                $rejected
            )));
        }

        $updates = [
            'rejected_mlms' => $rejected !== [] ? $rejected : null,
            'photo_updated_by' => $request->user()?->id,
            'photo_updated_at' => now(),
        ];

        if (isset($validated['notes']) && trim((string) $validated['notes']) !== '') {
            $updates['photo_notes'] = trim((string) $validated['notes']);
        }

        $oldManualPath = $item->getAttribute('manual_photo_path');
        $clearApproval = $markNoPhoto || ($mlm !== '' && strtoupper(trim((string) $item->getAttribute('approved_mlm'))) === $mlm);

        if ($clearApproval) {
            $updates['approved_mlm'] = null;
            $updates['approved_variation_id'] = null;
            $updates['approved_meli_account_id'] = null;
            $updates['approved_photo_url'] = null;
            if ($markNoPhoto) {
                $updates['photo_source_mode'] = PurchaseOrderItem::PHOTO_MODE_NONE;
                $updates['manual_photo_path'] = null;
                $updates['manual_photo_mime'] = null;
            } elseif ($item->getAttribute('photo_source_mode') === PurchaseOrderItem::PHOTO_MODE_APPROVED_ML) {
                $updates['photo_source_mode'] = null;
            }
        }

        $item->update($updates);

        if ($markNoPhoto && is_string($oldManualPath) && $oldManualPath !== '') {
            $this->meliImageCache->deleteManualPhoto($oldManualPath);
        }

        $item->refresh();

        return response()->json([
            'ok' => true,
            'message' => $markNoPhoto ? 'Partida marcada sin fotografía.' : 'Candidato rechazado para esta partida.',
            'resolution' => $this->meliImageResolver->resolveForItem($purchaseOrder, $item),
        ]);
    }

    public function resetItemPhoto(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): JsonResponse
    {
        if ((int) $item->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        Gate::authorize('managePhotos', $purchaseOrder);

        $oldManualPath = $item->getAttribute('manual_photo_path');
        $clearRejected = $request->boolean('clear_rejected', false);

        $updates = [
            'photo_source_mode' => null,
            'approved_mlm' => null,
            'approved_variation_id' => null,
            'approved_meli_account_id' => null,
            'approved_photo_url' => null,
            'manual_photo_path' => null,
            'manual_photo_mime' => null,
            'photo_notes' => null,
            'photo_updated_by' => $request->user()?->id,
            'photo_updated_at' => null,
        ];

        if ($clearRejected) {
            $updates['rejected_mlms'] = null;
        }

        $item->update($updates);

        if (is_string($oldManualPath) && $oldManualPath !== '') {
            $this->meliImageCache->deleteManualPhoto($oldManualPath);
        }

        $item->refresh();

        return response()->json([
            'ok' => true,
            'message' => 'Selección manual restablecida a resolución automática.',
            'resolution' => $this->meliImageResolver->resolveForItem($purchaseOrder, $item),
            'photo_url' => $this->buildSingleItemPhotoUrl($purchaseOrder, $item, false),
        ]);
    }

    /**
     * Build same-origin cached photo URLs for items that resolved to a verified picture.
     *
     * @param  array<int, array<string, mixed>>  $resolved
     * @return array<int, string>
     */
    private function buildItemPhotoUrlsFromResolution(
        PurchaseOrder $purchaseOrder,
        array $resolved,
        bool $useSignedPublicUrls,
    ): array {
        $itemsById = $purchaseOrder->items->keyBy('id');
        $urls = [];

        foreach ($resolved as $itemId => $entry) {
            if (! is_string($entry['url']) || $entry['url'] === '') {
                continue;
            }

            /** @var ?PurchaseOrderItem $item */
            $item = $itemsById->get((int) $itemId);
            $version = $item?->getAttribute('photo_updated_at')?->timestamp;
            $params = [
                'purchaseOrder' => $purchaseOrder->id,
                'item' => (int) $itemId,
            ];
            if (is_int($version) && $version > 0) {
                $params['v'] = $version;
            }

            if ($useSignedPublicUrls) {
                $urls[(int) $itemId] = URL::temporarySignedRoute(
                    'purchasing.orders.public-item-photo',
                    now()->addDays(7),
                    $params,
                    absolute: false
                );
            } else {
                $urls[(int) $itemId] = route(
                    'purchasing.orders.item-photo',
                    $params,
                    false
                );
            }
        }

        return $urls;
    }

    private function buildSingleItemPhotoUrl(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderItem $item,
        bool $useSignedPublicUrls,
    ): ?string {
        $resolved = $this->meliImageResolver->resolveForItem($purchaseOrder, $item);
        if (! is_string($resolved['url']) || $resolved['url'] === '') {
            return null;
        }

        $version = $item->getAttribute('photo_updated_at')?->timestamp;
        $params = [
            'purchaseOrder' => $purchaseOrder->id,
            'item' => (int) $item->id,
        ];
        if (is_int($version) && $version > 0) {
            $params['v'] = $version;
        }

        return $useSignedPublicUrls
            ? URL::temporarySignedRoute('purchasing.orders.public-item-photo', now()->addDays(7), $params, absolute: false)
            : route('purchasing.orders.item-photo', $params, false);
    }

    /**
     * Build UI photo diagnostic metadata (resolved source/variation, manual override, or needs_review).
     *
     * @param  array<int, array<string, mixed>>  $resolved
     * @return array<int, array<string, mixed>>
     */
    private function buildItemPhotoStatusesFromResolution(array $resolved): array
    {
        $statuses = [];

        foreach ($resolved as $itemId => $entry) {
            $hasUrl = is_string($entry['url'] ?? null) && $entry['url'] !== '';
            $isAmbiguous = ! empty($entry['ambiguous']);

            if ($isAmbiguous && ! $hasUrl) {
                $statuses[(int) $itemId] = [
                    'status' => 'needs_review',
                    'audit_category' => $entry['audit_category'] ?? PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL,
                    'reason' => (string) ($entry['reason'] ?? 'ambiguous'),
                    'candidates_count' => count((array) ($entry['ambiguous_matches'] ?? [])),
                    'source' => $entry['source'] ?? null,
                    'mlm' => $entry['mlm'] ?? null,
                    'variation_id' => $entry['variation_id'] ?? null,
                    'variation_label' => $entry['variation_label'] ?? null,
                    'listing_title' => $entry['listing_title'] ?? null,
                    'photo_source_mode' => $entry['photo_source_mode'] ?? null,
                    'photo_notes' => $entry['photo_notes'] ?? null,
                ];

                continue;
            }

            $statuses[(int) $itemId] = [
                'status' => $hasUrl
                    ? 'resolved'
                    : (($entry['photo_source_mode'] ?? null) === PurchaseOrderItem::PHOTO_MODE_NONE ? 'manual_rejected' : 'missing'),
                'audit_category' => $entry['audit_category'] ?? ($hasUrl
                    ? PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_SKU_LINK
                    : PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE),
                'reason' => (string) ($entry['reason'] ?? 'unresolved'),
                'candidates_count' => count((array) ($entry['ambiguous_matches'] ?? [])),
                'source' => $entry['source'] ?? null,
                'mlm' => $entry['mlm'] ?? null,
                'variation_id' => $entry['variation_id'] ?? null,
                'variation_label' => $entry['variation_label'] ?? null,
                'listing_title' => $entry['listing_title'] ?? null,
                'photo_source_mode' => $entry['photo_source_mode'] ?? null,
                'photo_notes' => $entry['photo_notes'] ?? null,
            ];
        }

        return $statuses;
    }

    /**
     * Build a temporary signed external PDF URL that stays valid behind Cloudflare/Nginx TLS termination.
     */
    private function buildExternalSignedPdfUrl(Request $request, PurchaseOrder $purchaseOrder): string
    {
        $relativeSignedPath = URL::temporarySignedRoute(
            'purchasing.orders.public-pdf',
            now()->addDays(7),
            ['purchaseOrder' => $purchaseOrder->id],
            absolute: false
        );

        $forwardedProto = strtolower((string) $request->header('X-Forwarded-Proto', ''));
        $isHttps = $request->isSecure()
            || str_contains($forwardedProto, 'https')
            || (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://'));

        $scheme = $isHttps ? 'https' : $request->getScheme();

        return "{$scheme}://{$request->getHttpHost()}{$relativeSignedPath}";
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $strippedQ = ltrim($q, '0');
        $cleanQ = preg_replace('/[^0-9A-Za-z]/', '', $q);

        $products = InventoryProduct::query()
            ->with([
                'primaryLocation:id,code,name',
                'supplierProducts:id,inventory_product_id,supplier_id,supplier_name,supplier_sku,supplier_cost',
            ])
            ->where('is_active', true)
            ->purchasableFromSupplier()
            ->where(function ($query) use ($q, $strippedQ, $cleanQ) {
                $query->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhere('barcode_secondary', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('brand', 'like', "%{$q}%")
                    ->orWhereHas('supplierProducts', function ($spq) use ($q) {
                        $spq->where('supplier_sku', 'like', "%{$q}%");
                    });

                if ($strippedQ !== '' && $strippedQ !== $q) {
                    $query->orWhere('sku', 'like', "%{$strippedQ}%")
                          ->orWhere('barcode', 'like', "%{$strippedQ}%");
                }

                if ($cleanQ !== '' && $cleanQ !== $q && $cleanQ !== $strippedQ) {
                    $query->orWhere('sku', 'like', "%{$cleanQ}%")
                          ->orWhere('barcode', 'like', "%{$cleanQ}%");
                }
            })
            ->limit(30)
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'cost', 'primary_location_id']);

        return response()->json(['data' => $products]);
    }

    public function quickCreateProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:150'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'primary_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
        ]);

        $sku = trim($validated['sku']);
        $barcode = ! empty($validated['barcode']) ? trim($validated['barcode']) : (is_numeric($sku) ? $sku : null);

        // Buscar si ya existe por SKU o código de barras
        $product = InventoryProduct::query()
            ->where('sku', $sku)
            ->when($barcode, fn ($q) => $q->orWhere('barcode', $barcode))
            ->first();

        if (! $product) {
            $locationId = $validated['primary_location_id'] ?? null;
            if (! $locationId) {
                $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
                $locationId = $location?->id;
            }

            $product = InventoryProduct::create([
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => trim($validated['name']),
                'brand' => ! empty($validated['brand']) ? trim($validated['brand']) : 'General',
                'supplier' => ! empty($validated['supplier_name']) ? trim($validated['supplier_name']) : 'Compras USA',
                'cost' => isset($validated['cost']) ? (float) $validated['cost'] : 0.00,
                'product_type' => InventoryProduct::SIMPLE,
                'is_active' => true,
                'primary_location_id' => $locationId,
            ]);
        }

        // Asociar supplier_sku si se especificó
        if (! empty($validated['supplier_sku']) && ! empty($validated['supplier_name'])) {
            $supplier = Supplier::where('name', $validated['supplier_name'])->first();
            \App\Models\SupplierProduct::updateOrCreate(
                [
                    'supplier_name' => trim($validated['supplier_name']),
                    'inventory_product_id' => $product->id,
                    'supplier_sku' => trim($validated['supplier_sku']),
                ],
                [
                    'supplier_id' => $supplier?->id,
                    'supplier_product_name' => $product->name,
                    'supplier_cost' => isset($validated['cost']) ? (float) $validated['cost'] : null,
                    'is_primary' => true,
                ]
            );
        }

        $product->load(['primaryLocation:id,code,name', 'supplierProducts']);

        return response()->json([
            'ok' => true,
            'message' => 'Producto registrado y listo para la orden.',
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'barcode_secondary' => $product->barcode_secondary,
                'name' => $product->name,
                'brand' => $product->brand,
                'cost' => (float) ($product->cost ?? 0),
                'primary_location_id' => $product->primary_location_id,
                'primary_location' => $product->primaryLocation ? [
                    'id' => $product->primaryLocation->id,
                    'code' => $product->primaryLocation->code,
                    'name' => $product->primaryLocation->name,
                ] : null,
                'supplier_products' => $product->supplierProducts ?? [],
            ],
        ]);
    }

    public function saveSupplierSku(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_sku' => ['required', 'string', 'max:100'],
            'supplier_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = InventoryProduct::findOrFail($validated['inventory_product_id']);
        $supplier = Supplier::where('name', $validated['supplier_name'])->first();

        $supProduct = \App\Models\SupplierProduct::updateOrCreate(
            [
                'supplier_name' => trim($validated['supplier_name']),
                'inventory_product_id' => $product->id,
                'supplier_sku' => trim($validated['supplier_sku']),
            ],
            [
                'supplier_id' => $supplier?->id,
                'supplier_product_name' => $product->name,
                'supplier_cost' => $validated['supplier_cost'] ?? null,
                'is_primary' => true,
            ]
        );

        return response()->json([
            'ok' => true,
            'message' => 'Código de proveedor guardado correctamente.',
            'supplier_product' => $supProduct,
        ]);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'ordered_at' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
        ]);

        try {
            $updated = $this->poService->markAsOrdered(
                $purchaseOrder,
                $validated['supplier_quote_reference'] ?? null,
                $validated['ordered_at'] ?? null,
                $validated['expected_delivery_date'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$updated->order_number} enviada a proveedor.",
                    'order' => $updated,
                ]);
            }

            return back()->with('success', "Orden de compra {$updated->order_number} marcada como enviada.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'close_order' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
            'items.*.allow_over_receipt' => ['nullable', 'boolean'],
        ]);

        try {
            $receipt = $this->poService->receiveItems(
                $purchaseOrder,
                $validated['items'],
                $request->user(),
                $validated['inventory_location_id'] ?? null,
                $validated['carrier'] ?? null,
                $validated['tracking_number'] ?? null,
                $validated['notes'] ?? null,
                (bool) ($validated['close_order'] ?? false)
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Recepción {$receipt->receipt_number} procesada e inventario ingresado al almacén.",
                    'receipt' => $receipt,
                    'order' => $purchaseOrder->fresh(),
                ]);
            }

            return back()->with('success', "Recepción {$receipt->receipt_number} procesada correctamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function closePartial(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->poService->closePartialOrder(
                $purchaseOrder,
                $request->user(),
                $validated['reason'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden {$updated->order_number} cerrada definitivamente con recepción parcial.",
                    'order' => $updated,
                ]);
            }

            return back()->with('success', "Orden {$updated->order_number} cerrada definitivamente con recepción parcial.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function voidReceipt(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderReceipt $receipt): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            if ($receipt->purchase_order_id !== $purchaseOrder->id) {
                abort(404, 'El comprobante de recepción no pertenece a esta orden.');
            }

            $updated = $this->poService->voidReceipt(
                $receipt,
                $request->user(),
                $validated['reason'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Comprobante {$receipt->receipt_number} anulado e inventario revertido correctamente.",
                    'order' => $updated,
                ]);
            }

            return back()->with('success', "Comprobante {$receipt->receipt_number} anulado e inventario revertido correctamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $cancelled = $this->poService->cancelOrder($purchaseOrder, $request->user(), $validated['reason']);

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$cancelled->order_number} cancelada exitosamente.",
                    'order' => $cancelled,
                ]);
            }

            return back()->with('success', "Orden de compra {$cancelled->order_number} cancelada.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }
}
