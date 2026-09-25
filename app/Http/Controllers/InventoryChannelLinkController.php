<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryChannelLinkRequest;
use App\Http\Requests\UpdateInventoryChannelLinkRequest;
use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Services\InventoryChannelLinkService;
use App\Services\InventoryMeliLinkImportService;
use App\Services\InventoryMeliStockPilotService;
use App\Services\InventoryMeliStockSyncService;
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

    public function show(Request $request, InventoryChannelLink $inventoryChannelLink): Response
    {
        $inventoryChannelLink->load('product:id,sku,name,barcode');
        $lastSuccess = $inventoryChannelLink->stockSyncs()->where('status', 'SUCCESS')->latest('id')->first();

        return Inertia::render('Inventory/Channels/Show', [
            'link' => $inventoryChannelLink,
            'canManage' => $request->user()?->isAdmin() ?? false,
            'lastSuccessfulStockSync' => $lastSuccess,
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

    public function meliImport(Request $request, InventoryMeliLinkImportService $importer): Response
    {
        $filters = $request->only(['search', 'result', 'account_key']);
        $preview = $request->boolean('analyze') ? $importer->preview($filters) : [
            'rows' => [],
            'counts' => array_fill_keys(InventoryMeliLinkImportService::classStatuses(), 0),
            'filters' => $filters,
        ];

        return Inertia::render('Inventory/Channels/MercadoLibreImport', [
            'preview' => $preview,
            'accounts' => MeliAccount::query()->orderBy('nickname')->get(['id', 'nickname', 'meli_user_id']),
            'canApply' => $request->user()?->isAdmin() ?? false,
        ]);
    }

    public function applyMeliImport(Request $request, InventoryMeliLinkImportService $importer): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $result = $importer->apply($request->only(['search', 'result', 'account_key']));

        return redirect()->route('inventory.channels.mercado-libre.import', [
            'analyze' => 1,
            ...array_filter($request->only(['search', 'account_key'])),
        ])->with('success', "Importación completada: {$result['imported']} vínculo(s) creado(s).")
            ->with('importErrors', $result['errors']);
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
