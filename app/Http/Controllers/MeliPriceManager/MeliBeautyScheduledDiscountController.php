<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\StoreMeliBeautyScheduledDiscountRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBeautyScheduledDiscountRequest;
use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliBeautyKitDetector;
use App\Services\MercadoLibre\PriceManager\MeliBeautyPromotionWindow;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPromotionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MeliBeautyScheduledDiscountController extends Controller
{
    public function index(
        Request $request,
        MeliBeautyScheduledPriceService $service,
        MeliBeautyKitDetector $kits,
    ): Response|JsonResponse {
        if ($request->boolean('catalog')) {
            return $this->items($request, $service, $kits);
        }

        $accounts = $request->user()->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'meli_user_id', 'nickname', 'is_default']);
        $selectedAccount = $this->selectedAccount($request, $accounts);
        $accountId = $selectedAccount?->id;

        $rules = $accountId === null
            ? collect()
            : MeliBeautyScheduledDiscount::query()
                ->where('meli_account_id', $accountId)
                ->with([
                    'brandGroup:id,name,slug',
                    'meliAccount:id,nickname',
                    'scheduledItems:id,meli_beauty_scheduled_discount_id,price_manager_item_id,discount_percentage',
                    'priceStates:id,meli_beauty_scheduled_discount_id,status,last_confirmed_remote_price,restored_at,failure_message',
                ])
                ->orderBy('brand_group_id')
                ->get()
                ->map(function (MeliBeautyScheduledDiscount $rule): array {
                    $rule->setAttribute('selected_items_count', $rule->scheduledItems->count());
                    $rule->setAttribute('schedule_status', app(MeliBeautyPromotionWindow::class)->status($rule));
                    $rule->setAttribute('state_summary', [
                        'active' => $rule->priceStates->where('status', 'active')->count(),
                        'restore_pending' => $rule->priceStates->where('status', 'restore_pending')->count(),
                        'failed' => $rule->priceStates->where('status', 'failed')->count(),
                        'confirmed_prices' => $rule->priceStates->whereNotNull('last_confirmed_remote_price')->count(),
                    ]);

                    return $rule->toArray();
                });

        $brandOptions = $accountId === null
            ? collect()
            : MeliBrandGroup::query()
                ->where('active', true)
                ->orderByRaw('LOWER(name)')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'slug'])
                ->map(function (MeliBrandGroup $brand) use ($accountId, $service): ?array {
                    $candidate = new MeliBeautyScheduledDiscount([
                        'meli_account_id' => $accountId,
                        'brand_group_id' => $brand->id,
                    ]);
                    $count = $service->selectableItemsQuery($candidate)->count();
                    if ($count === 0) {
                        return null;
                    }

                    return [
                        'id' => (int) $brand->id,
                        'name' => (string) $brand->name,
                        'slug' => (string) $brand->slug,
                        'eligible_items_count' => $count,
                    ];
                })
                ->filter()
                ->values();

        return Inertia::render('MeliPriceManager/ScheduledDiscounts', [
            'accounts' => $accounts,
            'selectedAccountId' => $accountId,
            'rules' => $rules,
            'brandOptions' => $brandOptions,
            'defaultTimezone' => MeliBeautyPromotionWindow::TIMEZONE,
            'automationEnabled' => (bool) config('meli_price_manager.beauty_scheduled_prices.enabled', false)
                && (bool) config('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false),
            'promotionalPricesEnabled' => (bool) config('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false),
            'schedulerEnabled' => (bool) config('meli_price_manager.beauty_scheduled_prices.enabled', false)
                && (bool) config('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false)
                && (bool) config('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false),
        ]);
    }

    public function items(
        Request $request,
        MeliBeautyScheduledPriceService $service,
        MeliBeautyKitDetector $kits,
    ): JsonResponse {
        $data = $request->validate([
            'meli_account_id' => ['required', 'integer', 'exists:meli_accounts,id'],
            'brand_group_id' => ['required', 'integer', 'exists:meli_brand_groups,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:title,price_asc,price_desc,kits_first,kits_last'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_unless($request->user()->meliAccounts()->whereKey($data['meli_account_id'])->exists(), 404);

        $candidate = new MeliBeautyScheduledDiscount([
            'meli_account_id' => $data['meli_account_id'],
            'brand_group_id' => $data['brand_group_id'],
        ]);
        $query = $service->selectableItemsQuery($candidate);
        if (($search = trim((string) ($data['search'] ?? ''))) !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('meli_item_id', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $sort = (string) ($data['sort'] ?? 'title');
        $perPage = 50;
        $page = (int) ($data['page'] ?? 1);

        if (in_array($sort, ['kits_first', 'kits_last'], true)) {
            $allRows = $query->with('scheduledPriceState')->get()
                ->map(fn (MeliPriceManagerItem $item): array => $this->itemPayload($item, $kits))
                ->sort(function (array $a, array $b) use ($sort): int {
                    $comparison = $sort === 'kits_first'
                        ? ((int) $b['is_kit']) <=> ((int) $a['is_kit'])
                        : ((int) $a['is_kit']) <=> ((int) $b['is_kit']);

                    return $comparison !== 0
                        ? $comparison
                        : (strcasecmp($a['title'], $b['title']) ?: strcmp($a['meli_item_id'], $b['meli_item_id']));
                })->values();
            $rows = $allRows->forPage($page, $perPage)->values();
            $total = $allRows->count();
            $allIds = $allRows->pluck('id')->all();
        } else {
            if ($sort === 'price_asc') {
                $query->orderByRaw('current_price IS NULL')->orderBy('current_price');
            } elseif ($sort === 'price_desc') {
                $query->orderByDesc('current_price');
            }
            $query->orderByRaw('LOWER(title)')->orderBy('meli_item_id');
            $total = (clone $query)->reorder()->count();
            $allIds = (clone $query)->pluck('id')->all();
            $rows = (clone $query)->with('scheduledPriceState')->forPage($page, $perPage)->get()
                ->map(fn (MeliPriceManagerItem $item): array => $this->itemPayload($item, $kits));
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
                'all_ids' => $allIds,
            ],
        ]);
    }

    public function store(StoreMeliBeautyScheduledDiscountRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);
        $validated['discount_percentage'] ??= $items[0]['discount_percentage'];

        $rule = DB::transaction(function () use ($validated, $items, $request): MeliBeautyScheduledDiscount {
            $rule = MeliBeautyScheduledDiscount::query()->create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);
            $rule->scheduledItems()->createMany($items);

            return $rule;
        });

        return back()->with('success', "Promoción programada #{$rule->id} creada.");
    }

    public function update(
        UpdateMeliBeautyScheduledDiscountRequest $request,
        MeliBeautyScheduledDiscount $discount,
    ): RedirectResponse {
        $this->assertOwned($request, $discount);
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);
        $validated['discount_percentage'] ??= $items[0]['discount_percentage'];
        DB::transaction(function () use ($discount, $validated, $items): void {
            $discount->forceFill($validated)->save();
            $discount->scheduledItems()->delete();
            $discount->scheduledItems()->createMany($items);
        });

        return back()->with('success', 'Promoción programada actualizada.');
    }

    public function status(
        Request $request,
        MeliBeautyScheduledDiscount $discount,
        MeliBeautyScheduledPromotionGuard $guard,
        MeliBeautyPromotionWindow $window,
    ): RedirectResponse {
        $this->assertOwned($request, $discount);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        if ($data['active']) {
            $discount->load('scheduledItems');
            if ($window->bounds($discount) === null || $discount->scheduledItems->isEmpty()) {
                return back()->withErrors(['active' => 'Configura un periodo válido y selecciona al menos una publicación antes de habilitarla.']);
            }
            $itemIds = $discount->scheduledItems->pluck('price_manager_item_id')->map(fn ($id): int => (int) $id)->all();
            if ($guard->ineligibleItemIds($discount, $itemIds) !== []) {
                return back()->withErrors(['active' => 'La promoción contiene publicaciones que ya no son Beauty administrables.']);
            }
            $conflict = $guard->conflictingPromotion(
                $discount->forceFill(['active' => true]),
                $itemIds,
                (int) $discount->id,
            );
            if ($conflict !== null) {
                return back()->withErrors(['active' => "No puede habilitarse: se solapa con la promoción #{$conflict->id}."]);
            }
        }
        $discount->forceFill(['active' => $data['active']])->save();

        return back()->with('success', $data['active'] ? 'Promoción habilitada.' : 'Promoción deshabilitada; el scheduler restaurará cualquier precio activo.');
    }

    private function assertOwned(Request $request, MeliBeautyScheduledDiscount $discount): void
    {
        abort_unless(
            $request->user()->meliAccounts()->whereKey($discount->meli_account_id)->exists(),
            404,
            'La cuenta no pertenece al usuario autenticado.',
        );
    }

    /** @return array<string, mixed> */
    private function itemPayload(MeliPriceManagerItem $item, MeliBeautyKitDetector $kits): array
    {
        return [
            'id' => (int) $item->id,
            'meli_item_id' => (string) $item->meli_item_id,
            'title' => (string) $item->title,
            'sku' => (string) ($item->sku ?? ''),
            'current_price' => is_numeric($item->current_price) ? (float) $item->current_price : null,
            'is_kit' => $kits->isKit($item),
            'state' => $item->scheduledPriceState?->status,
            'state_promotion_id' => $item->scheduledPriceState?->meli_beauty_scheduled_discount_id,
        ];
    }

    private function selectedAccount(Request $request, $accounts): ?MeliAccount
    {
        if ($request->filled('account')) {
            $account = $accounts->firstWhere('id', $request->integer('account'));
            abort_if($account === null, 404, 'La cuenta de Mercado Libre no pertenece al usuario autenticado.');

            return $account;
        }

        return $accounts->firstWhere('is_default', true) ?? $accounts->first();
    }
}
