<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\StoreMeliBrandGroupRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBrandGroupRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBrandGroupStatusRequest;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeliBrandGroupController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'meli_user_id', 'nickname', 'is_default']);
        $selectedAccount = $this->selectedAccount($request, $accounts);
        $selectedAccountId = $selectedAccount?->id;

        $brands = MeliBrandGroup::query()
            ->with(['aliases' => fn ($query) => $query
                ->orderByDesc('priority')
                ->orderByDesc('active')
                ->orderBy('normalized_alias')])
            ->withCount('aliases')
            ->withCount([
                'items as categorized_items_count' => fn (Builder $query) => $query
                    ->when($selectedAccountId, fn (Builder $query, int $accountId) => $query->where('meli_account_id', $accountId))
                    ->when(! $selectedAccountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                    ->where('classification_status', 'categorized'),
                'suggestedItems as suggested_items_count' => fn (Builder $query) => $query
                    ->when($selectedAccountId, fn (Builder $query, int $accountId) => $query->where('meli_account_id', $accountId))
                    ->when(! $selectedAccountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                    ->where('classification_status', 'suggested'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $preview = $request->session()->get('meli_price_manager_reclassification_preview');
        if (is_array($preview) && (int) ($preview['meli_account_id'] ?? 0) !== (int) $selectedAccountId) {
            $preview = null;
        }

        return Inertia::render('MeliPriceManager/Brands', [
            'accounts' => $accounts,
            'selectedAccountId' => $selectedAccountId,
            'brands' => $brands,
            'matchTypes' => [
                'exact' => ['label' => 'Coincidencia exacta', 'help' => 'La marca normalizada debe ser idéntica al alias.'],
                'starts_with' => ['label' => 'Comienza con', 'help' => 'La marca debe iniciar con el alias como frase completa.'],
                'contains' => ['label' => 'Contiene', 'help' => 'Busca el alias como frase completa dentro de la marca.'],
                'manual' => ['label' => 'Solo manual', 'help' => 'Este alias no participa en clasificación automática.'],
            ],
            'preview' => $preview,
        ]);
    }

    public function store(StoreMeliBrandGroupRequest $request): RedirectResponse
    {
        $data = $request->validated();
        MeliBrandGroup::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'active' => $data['active'],
            'sort_order' => $data['sort_order'],
        ]);

        return back()->with('success', 'Marca creada correctamente. No se reclasificaron publicaciones.');
    }

    public function update(UpdateMeliBrandGroupRequest $request, MeliBrandGroup $brand): RedirectResponse
    {
        $data = $request->validated();
        $brand->forceFill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'active' => $data['active'],
            'sort_order' => $data['sort_order'],
        ])->save();

        return back()->with('success', 'Marca actualizada correctamente. No se reclasificaron publicaciones.');
    }

    public function status(UpdateMeliBrandGroupStatusRequest $request, MeliBrandGroup $brand): RedirectResponse
    {
        $active = $request->boolean('active');
        $assignedItems = $brand->items()->count();
        $brand->forceFill(['active' => $active])->save();

        $message = $active ? 'Marca activada.' : 'Marca desactivada.';
        if (! $active && $assignedItems > 0) {
            $message .= " Conserva {$assignedItems} publicaciones asignadas; no se modificó ninguna clasificación.";
        }

        return back()->with('success', $message);
    }

    private function selectedAccount(Request $request, $accounts): ?MeliAccount
    {
        if ($request->filled('account')) {
            $accountId = $request->integer('account');
            $account = $accounts->firstWhere('id', $accountId);
            abort_if($account === null, 404, 'La cuenta de Mercado Libre no pertenece al usuario autenticado.');

            return $account;
        }

        return $accounts->firstWhere('is_default', true) ?? $accounts->first();
    }
}
