<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryChannelLinkRequest;
use App\Http\Requests\UpdateInventoryChannelLinkRequest;
use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Services\InventoryChannelLinkService;
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

        return Inertia::render('Inventory/Channels/Show', [
            'link' => $inventoryChannelLink,
            'canManage' => $request->user()?->isAdmin() ?? false,
        ]);
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
