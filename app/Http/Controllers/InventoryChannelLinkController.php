<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryChannelLinkRequest;
use App\Http\Requests\UpdateInventoryChannelLinkRequest;
use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Services\Amazon\InventoryAmazonLinkImportService;
use App\Services\InventoryChannelLinkService;
use App\Services\InventoryMeliLinkImportService;
use App\Services\InventoryMeliSharedStockGroupService;
use App\Services\InventoryMeliStockPilotService;
use App\Services\InventoryMeliStockSyncService;
use App\Services\Shopify\InventoryShopifyLinkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class InventoryChannelLinkController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $channel = trim((string) $request->input('channel', ''));
        $active = (string) $request->input('active', '');
        $links = InventoryChannelLink::query()
            ->with('product:id,sku,name,barcode')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('account_key', 'like', "%{$search}%")
                        ->orWhere('external_product_id', 'like', "%{$search}%")
                        ->orWhere('external_variant_id', 'like', "%{$search}%")
                        ->orWhere('external_listing_id', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($product) use ($search): void {
                            $product->where('sku', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%");
                        });
                });
            })
            ->when(in_array($channel, InventoryChannelLink::CHANNELS, true), fn ($query) => $query->where('channel', $channel))
            ->when($active === '1' || $active === '0', fn ($query) => $query->where('is_active', $active === '1'))
            ->orderBy('channel')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Channels/Index', [
            'links' => $links,
            'filters' => compact('search', 'channel', 'active'),
            'channels' => InventoryChannelLink::CHANNELS,
            'canManage' => $request->user()?->isAdmin() ?? false,
        ]);
    }

    public function create(): Response
    {
        abort_unless(request()->user()?->isAdmin(), 403);

        return Inertia::render('Inventory/Channels/Form', [
            'mode' => 'create',
            'link' => null,
            'products' => $this->products(),
            'channels' => InventoryChannelLink::CHANNELS,
        ]);
    }

    public function store(StoreInventoryChannelLinkRequest $request, InventoryChannelLinkService $service): RedirectResponse
    {
        try {
            $service->create($request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['external_listing_id' => $exception->getMessage()]);
        }

        return redirect()->route('inventory.channels.index')->with('success', 'Enlace de canal creado correctamente.');
    }

    public function show(Request $request, InventoryChannelLink $inventoryChannelLink, InventoryMeliSharedStockGroupService $groups): Response
    {
        $inventoryChannelLink->load('product:id,sku,name,barcode');
        $lastSuccess = $inventoryChannelLink->stockSyncs()->where('status', 'SUCCESS')->latest('id')->first();
        $siblings = $groups->isGrouped($inventoryChannelLink)
            ? $groups->links($inventoryChannelLink, true)->map(fn (InventoryChannelLink $link) => (int) $link->id)->values()
            : collect();

        return Inertia::render('Inventory/Channels/Show', [
            'link' => $inventoryChannelLink,
            'canManage' => $request->user()?->isAdmin() ?? false,
            'lastSuccessfulStockSync' => $lastSuccess,
            'sharedStockGroup' => $groups->isGrouped($inventoryChannelLink) ? [
                'remote_user_product_id' => $inventoryChannelLink->remote_user_product_id,
                'link_ids' => $siblings,
            ] : null,
        ]);
    }

    public function stock(Request $request, InventoryMeliStockSyncService $sync, InventoryMeliStockPilotService $pilot): Response
    {
        $filters = $request->only(['search', 'result', 'account_key', 'enabled', 'sku', 'link']);
        $pilotPreview = null;
        if (filled($filters['link'] ?? null) && is_numeric($filters['link'])) {
            $pilotPreview = $pilot->preview((int) $filters['link']);
        }

        return Inertia::render('Inventory/Channels/MercadoLibreStock', [
            'preview' => $request->boolean('analyze') ? $sync->preview($filters) : [
                'rows' => [],
                'counts' => array_fill_keys(InventoryMeliStockSyncService::previewStatuses(), 0),
                'filters' => $filters,
            ],
            'accounts' => MeliAccount::query()->orderBy('nickname')->get(['id', 'nickname', 'meli_user_id']),
            'statuses' => InventoryMeliStockSyncService::previewStatuses(),
            'canManage' => $request->user()?->isAdmin() ?? false,
            'pilotPreview' => $pilotPreview,
        ]);
    }

    public function toggleStockSync(Request $request, InventoryChannelLink $inventoryChannelLink): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($inventoryChannelLink->channel === InventoryChannelLink::MERCADO_LIBRE, 404);
        $inventoryChannelLink->update(['stock_sync_enabled' => $request->boolean('enabled')]);

        return back()->with('success', $inventoryChannelLink->stock_sync_enabled
            ? 'Sincronización de stock activada para este vínculo.'
            : 'Sincronización de stock desactivada para este vínculo.');
    }

    public function syncMeliStock(Request $request, InventoryMeliStockSyncService $sync): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $validated = $request->validate([
            'link_id' => ['nullable', 'integer', 'exists:inventory_channel_links,id', 'required_without:link_ids'],
            'link_ids' => ['nullable', 'array', 'min:1', 'required_without:link_id'],
            'link_ids.*' => ['integer', 'exists:inventory_channel_links,id'],
        ]);
        if (! empty($validated['link_ids'])) {
            $batch = $sync->apply(['links' => $validated['link_ids']], $request->user()->id);

            return back()->with('success', "Sincronización terminada: {$batch['imported']} vínculo(s) actualizado(s).");
        }
        $result = $sync->syncLink((int) $validated['link_id'], $request->user()->id, 'manual');

        return back()->with($result['status'] === 'SUCCESS' ? 'success' : 'error', $result['status'] === 'SUCCESS'
            ? 'Stock sincronizado correctamente.'
            : ($result['reason'] ?? 'No fue posible sincronizar el stock.'));
    }

    public function edit(InventoryChannelLink $inventoryChannelLink): Response
    {
        abort_unless(request()->user()?->isAdmin(), 403);
        $inventoryChannelLink->load('product:id,sku,name,barcode');

        return Inertia::render('Inventory/Channels/Form', [
            'mode' => 'edit',
            'link' => $inventoryChannelLink,
            'products' => $this->products($inventoryChannelLink->product),
            'channels' => InventoryChannelLink::CHANNELS,
        ]);
    }

    public function update(UpdateInventoryChannelLinkRequest $request, InventoryChannelLink $inventoryChannelLink, InventoryChannelLinkService $service): RedirectResponse
    {
        try {
            $service->update($inventoryChannelLink, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['external_listing_id' => $exception->getMessage()]);
        }

        return redirect()->route('inventory.channels.show', $inventoryChannelLink)->with('success', 'Enlace de canal actualizado correctamente.');
    }

    public function toggle(Request $request, InventoryChannelLink $inventoryChannelLink): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $inventoryChannelLink->update(['is_active' => ! $inventoryChannelLink->is_active]);

        return back()->with('success', $inventoryChannelLink->is_active ? 'Enlace activado.' : 'Enlace desactivado.');
    }

    public function import(
        Request $request,
        InventoryMeliLinkImportService $meliImporter,
        InventoryShopifyLinkImportService $shopifyImporter,
        InventoryAmazonLinkImportService $amazonImporter,
    ): Response {
        $channel = strtolower(trim((string) $request->input('channel', InventoryChannelLink::MERCADO_LIBRE)));
        if (! in_array($channel, [InventoryChannelLink::MERCADO_LIBRE, InventoryChannelLink::SHOPIFY, InventoryChannelLink::AMAZON], true)) {
            $channel = InventoryChannelLink::MERCADO_LIBRE;
        }

        $filters = $request->only(['search', 'result', 'account_key']);
        $preview = [
            'rows' => [],
            'counts' => array_fill_keys(InventoryMeliLinkImportService::classStatuses(), 0),
            'filters' => $filters,
            'total_rows' => 0,
            'rows_truncated' => false,
            'row_limit' => 250,
        ];

        if ($channel === InventoryChannelLink::SHOPIFY) {
            $preview = $shopifyImporter->preview($filters);
        } elseif ($channel === InventoryChannelLink::AMAZON) {
            $amazonItems = session('amazon_import_items', []);
            $preview = $amazonImporter->preview($filters, $amazonItems);
        } else {
            // Mercado Libre
            $preview = $request->boolean('analyze') ? $meliImporter->preview($filters) : $preview;
        }

        return Inertia::render('Inventory/Channels/ChannelLinksImport', [
            'activeChannel' => $channel,
            'preview' => $preview,
            'accounts' => MeliAccount::query()->orderBy('nickname')->get(['id', 'nickname', 'meli_user_id']),
            'canApply' => $request->user()?->isAdmin() ?? false,
            'amazonLoadedCount' => count(session('amazon_import_items', [])),
            'shopifyDomain' => (string) config('services.shopify.store_domain', 'shopify'),
        ]);
    }

    public function meliImport(Request $request, InventoryMeliLinkImportService $meliImporter, InventoryShopifyLinkImportService $shopifyImporter, InventoryAmazonLinkImportService $amazonImporter): Response
    {
        return $this->import($request, $meliImporter, $shopifyImporter, $amazonImporter);
    }

    public function applyImport(
        Request $request,
        InventoryMeliLinkImportService $meliImporter,
        InventoryShopifyLinkImportService $shopifyImporter,
        InventoryAmazonLinkImportService $amazonImporter,
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);
        $channel = strtolower(trim((string) $request->input('channel', InventoryChannelLink::MERCADO_LIBRE)));
        $filters = $request->only(['search', 'result', 'account_key']);

        if ($channel === InventoryChannelLink::SHOPIFY) {
            $result = $shopifyImporter->apply($filters);

            return redirect()->route('inventory.channels.import', [
                'channel' => 'shopify',
                'analyze' => 1,
                ...array_filter($filters),
            ])->with('success', "Importación de Shopify completada: {$result['imported']} vínculo(s) creado(s).")
                ->with('importErrors', $result['errors']);
        }

        if ($channel === InventoryChannelLink::AMAZON) {
            $amazonItems = session('amazon_import_items', []);
            $result = $amazonImporter->apply($filters, $amazonItems);

            return redirect()->route('inventory.channels.import', [
                'channel' => 'amazon',
                'analyze' => 1,
                ...array_filter($filters),
            ])->with('success', "Importación de Amazon completada: {$result['imported']} vínculo(s) creado(s).")
                ->with('importErrors', $result['errors']);
        }

        $result = $meliImporter->apply($filters);

        return redirect()->route('inventory.channels.import', [
            'channel' => 'mercado_libre',
            'analyze' => 1,
            ...array_filter($filters),
        ])->with('success', "Importación de Mercado Libre completada: {$result['imported']} vínculo(s) creado(s).")
            ->with('importErrors', $result['errors']);
    }

    public function applyMeliImport(Request $request, InventoryMeliLinkImportService $meliImporter, InventoryShopifyLinkImportService $shopifyImporter, InventoryAmazonLinkImportService $amazonImporter): RedirectResponse
    {
        return $this->applyImport($request, $meliImporter, $shopifyImporter, $amazonImporter);
    }

    public function uploadAmazonReport(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $items = [];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            $content = file_get_contents($file->getRealPath());

            if (in_array($extension, ['xlsx', 'xls'], true)) {
                $sheets = \Maatwebsite\Excel\Facades\Excel::toArray([], $file);
                $rows = $sheets[0] ?? [];
                if (! empty($rows)) {
                    $header = array_map(fn ($col) => strtolower(trim((string) $col)), $rows[0]);
                    $skuIdx = array_search('seller-sku', $header);
                    if ($skuIdx === false) $skuIdx = array_search('sku', $header);
                    if ($skuIdx === false) $skuIdx = 0;

                    $asinIdx = array_search('asin1', $header);
                    if ($asinIdx === false) $asinIdx = array_search('asin', $header);
                    if ($asinIdx === false) $asinIdx = 1;

                    $titleIdx = array_search('item-name', $header);
                    if ($titleIdx === false) $titleIdx = array_search('title', $header);
                    if ($titleIdx === false) $titleIdx = null;

                    $priceIdx = array_search('price', $header);
                    if ($priceIdx === false) $priceIdx = null;

                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        $sku = trim((string) ($row[$skuIdx] ?? ''));
                        $asin = trim((string) ($row[$asinIdx] ?? ''));
                        if ($sku !== '' || $asin !== '') {
                            $items[] = [
                                'sku' => $sku,
                                'seller_sku' => $sku,
                                'asin' => $asin,
                                'title' => $titleIdx !== null ? (string) ($row[$titleIdx] ?? '') : '',
                                'price' => $priceIdx !== null && is_numeric($row[$priceIdx] ?? null) ? (float) $row[$priceIdx] : null,
                            ];
                        }
                    }
                }
            } else {
                $lines = preg_split('/\r\n|\r|\n/', $content);
                $isTsv = str_contains($lines[0] ?? '', "\t");
                $delimiter = $isTsv ? "\t" : (str_contains($lines[0] ?? '', ',') ? ',' : ';');

                $skuIdx = 0;
                $asinIdx = 1;
                $titleIdx = null;
                $priceIdx = null;

                foreach ($lines as $lineNum => $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $cols = str_getcsv($line, $delimiter);

                    if ($lineNum === 0 && (str_contains(strtolower($line), 'sku') || str_contains(strtolower($line), 'asin'))) {
                        $header = array_map(fn ($c) => strtolower(trim((string) $c)), $cols);
                        $foundSku = array_search('seller-sku', $header);
                        if ($foundSku === false) $foundSku = array_search('sku', $header);
                        if ($foundSku !== false) $skuIdx = $foundSku;

                        $foundAsin = array_search('asin1', $header);
                        if ($foundAsin === false) $foundAsin = array_search('asin', $header);
                        if ($foundAsin !== false) $asinIdx = $foundAsin;

                        $foundTitle = array_search('item-name', $header);
                        if ($foundTitle === false) $foundTitle = array_search('title', $header);
                        if ($foundTitle !== false) $titleIdx = $foundTitle;

                        $foundPrice = array_search('price', $header);
                        if ($foundPrice !== false) $priceIdx = $foundPrice;
                        continue;
                    }

                    $sku = trim((string) ($cols[$skuIdx] ?? ''));
                    $asin = trim((string) ($cols[$asinIdx] ?? ''));
                    if ($sku !== '' || $asin !== '') {
                        $items[] = [
                            'sku' => $sku,
                            'seller_sku' => $sku,
                            'asin' => $asin,
                            'title' => $titleIdx !== null ? (string) ($cols[$titleIdx] ?? '') : '',
                            'price' => $priceIdx !== null && is_numeric($cols[$priceIdx] ?? null) ? (float) $cols[$priceIdx] : null,
                        ];
                    }
                }
            }
        } elseif (filled($request->input('pasted_text'))) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $request->input('pasted_text'));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts = preg_split('/[\t,;]+/', $line);
                $sku = trim($parts[0] ?? '');
                $asin = trim($parts[1] ?? '');
                if ($sku !== '' || $asin !== '') {
                    $items[] = [
                        'sku' => $sku,
                        'seller_sku' => $sku,
                        'asin' => $asin,
                        'title' => trim($parts[2] ?? ''),
                        'price' => isset($parts[3]) && is_numeric(trim($parts[3])) ? (float) trim($parts[3]) : null,
                    ];
                }
            }
        }

        session(['amazon_import_items' => $items]);

        return redirect()->route('inventory.channels.import', [
            'channel' => 'amazon',
            'analyze' => 1,
        ])->with('success', 'Se cargaron '.count($items).' productos de Amazon para analizar.');
    }

    public function clearAmazonReport(): RedirectResponse
    {
        session()->forget('amazon_import_items');

        return redirect()->route('inventory.channels.import', [
            'channel' => 'amazon',
        ])->with('success', 'Reporte de Amazon limpiado.');
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $products = InventoryProduct::query()
            ->select(['id', 'sku', 'barcode', 'name', 'price_public', 'is_active'])
            ->where('is_active', true)
            ->where(function ($query) use ($q): void {
                $query->where('sku', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json($products);
    }

    public function linkManual(Request $request, InventoryChannelLinkService $linkService): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'in:mercado_libre,amazon,shopify'],
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'account_key' => ['nullable', 'string'],
            'external_listing_id' => ['required', 'string'],
            'external_variant_id' => ['nullable', 'string'],
            'external_product_id' => ['nullable', 'string'],
            'external_url' => ['nullable', 'string'],
            'remote_status' => ['nullable', 'string'],
            'remote_price' => ['nullable', 'numeric'],
            'remote_currency' => ['nullable', 'string'],
        ]);

        $channel = $validated['channel'] ?? InventoryChannelLink::MERCADO_LIBRE;
        $accountKey = (! empty($validated['account_key'])) ? $validated['account_key'] : match ($channel) {
            InventoryChannelLink::SHOPIFY => (string) config('services.shopify.store_domain', 'shopify'),
            InventoryChannelLink::AMAZON => (string) config('services.amazon.seller_id', 'default_seller'),
            default => 'default',
        };

        try {
            $linkService->create([
                ...$validated,
                'channel' => $channel,
                'account_key' => $accountKey,
                'metadata' => [
                    'source' => 'manual_assisted_mapping',
                    'mapped_by_user_id' => $request->user()->id,
                    'mapped_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $channelLabel = match ($channel) {
            InventoryChannelLink::SHOPIFY => 'Shopify',
            InventoryChannelLink::AMAZON => 'Amazon',
            default => 'Mercado Libre',
        };

        return back()->with('success', "Publicación de {$channelLabel} ({$validated['external_listing_id']}) vinculada exitosamente.");
    }

    public function linkSelected(
        Request $request,
        InventoryChannelLinkService $linkService,
        InventoryMeliLinkImportService $meliImporter
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);

        $channel = $request->input('channel', InventoryChannelLink::MERCADO_LIBRE);

        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'in:mercado_libre,amazon,shopify'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.account_key' => ['nullable', 'string'],
            'items.*.listing_id' => ['nullable', 'string'],
            'items.*.variant_id' => ['nullable', 'string'],
            'items.*.mlm' => ['nullable', 'string'],
            'items.*.variation_id' => ['nullable', 'string'],
            'items.*.external_product_id' => ['nullable', 'string'],
            'items.*.external_url' => ['nullable', 'string'],
            'items.*.remote_status' => ['nullable', 'string'],
            'items.*.remote_price' => ['nullable', 'numeric'],
            'items.*.remote_currency' => ['nullable', 'string'],
            'items.*.title' => ['nullable', 'string'],
        ]);

        if ($channel === InventoryChannelLink::MERCADO_LIBRE) {
            $result = $meliImporter->applySelected($validated['items']);

            return back()->with('success', "Se vincularon {$result['imported']} publicaciones de Mercado Libre.")
                ->with('importErrors', $result['errors']);
        }

        $imported = 0;
        $errors = [];

        foreach ($validated['items'] as $item) {
            try {
                $listingId = $item['listing_id'] ?? $item['mlm'] ?? $item['external_listing_id'] ?? null;
                $variantId = $item['variant_id'] ?? $item['variation_id'] ?? $item['external_variant_id'] ?? null;
                $accountKey = (! empty($item['account_key'])) ? $item['account_key'] : match ($channel) {
                    InventoryChannelLink::SHOPIFY => (string) config('services.shopify.store_domain', 'shopify'),
                    InventoryChannelLink::AMAZON => (string) config('services.amazon.seller_id', 'default_seller'),
                    default => 'default',
                };

                $linkService->create([
                    'channel' => $channel,
                    'inventory_product_id' => (int) $item['inventory_product_id'],
                    'account_key' => $accountKey,
                    'external_listing_id' => $listingId,
                    'external_variant_id' => $variantId,
                    'external_product_id' => $item['external_product_id'] ?? null,
                    'remote_price' => isset($item['remote_price']) && is_numeric($item['remote_price']) ? round((float) $item['remote_price'], 2) : null,
                    'remote_currency' => $item['remote_currency'] ?? 'MXN',
                    'is_active' => true,
                    'stock_sync_enabled' => false,
                    'order_reservation_enabled' => true,
                    'metadata' => [
                        'source' => 'assisted_ui_import',
                        'title' => $item['title'] ?? null,
                    ],
                ]);
                $imported++;
            } catch (InvalidArgumentException $e) {
                $identifier = $item['listing_id'] ?? $item['mlm'] ?? 'Ítem';
                $errors[] = "{$identifier}: {$e->getMessage()}";
            }
        }

        $channelLabel = match ($channel) {
            InventoryChannelLink::SHOPIFY => 'Shopify',
            InventoryChannelLink::AMAZON => 'Amazon',
            default => 'Mercado Libre',
        };

        return back()->with('success', "Se vincularon {$imported} publicaciones de {$channelLabel} seleccionadas.")
            ->with('importErrors', $errors);
    }

    private function products(?InventoryProduct $current = null)
    {
        return InventoryProduct::query()
            ->where(function ($query) use ($current): void {
                $query->where('is_active', true);
                if ($current !== null) {
                    $query->orWhere('id', $current->getKey());
                }
            })
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'barcode']);
    }
}
