<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\DispatchMeliPriceManagerSyncRequest;
use App\Jobs\SyncMeliPriceManagerItemsJob;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MeliPriceManagerDashboardController extends Controller
{
    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'title' => 'title',
        'sku' => 'sku',
        'price' => 'current_price',
        'stock' => 'available_quantity',
        'last_synced_at' => 'last_synced_at',
    ];

    public function index(Request $request): Response
    {
        $accounts = $request->user()->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'meli_user_id', 'nickname', 'is_default']);
        $selectedAccount = $this->selectedAccount($request, $accounts);
        $accountId = $selectedAccount?->id;
        $staleHours = max(1, (int) config('meli_price_manager.stale_after_hours', 24));
        $staleBefore = now()->subHours($staleHours);

        $availableStatuses = $accountId
            ? MeliPriceManagerItem::query()
                ->managedCatalog()
                ->where('meli_account_id', $accountId)
                ->whereNotNull('status')->where('status', '!=', '')->distinct()->orderBy('status')->pluck('status')->values()
            : collect();
        $availableCategories = $accountId
            ? MeliPriceManagerItem::query()
                ->managedCatalog()
                ->where('meli_account_id', $accountId)
                ->where('classification_status', 'categorized')
                ->whereNotNull('category_id')->where('category_id', '!=', '')->distinct()->orderBy('category_id')->pluck('category_id')->values()
            : collect();

        $selectedBrandId = $request->filled('brand') ? $request->integer('brand') : null;
        if ($selectedBrandId !== null) {
            abort_unless(
                MeliBrandGroup::query()->whereKey($selectedBrandId)->where('active', true)->exists(),
                404,
                'La marca seleccionada no existe o está inactiva.',
            );
        }

        $status = trim($request->string('status')->toString());
        if ($status !== '' && ! $availableStatuses->containsStrict($status)) {
            $status = '';
        }
        $stock = $request->string('stock', 'all')->toString();
        if (! in_array($stock, ['all', 'in_stock', 'out_of_stock'], true)) {
            $stock = 'all';
        }
        $sync = $request->string('sync', 'all')->toString();
        if (! in_array($sync, ['all', 'recent', 'stale', 'never'], true)) {
            $sync = 'all';
        }
        $sort = array_key_exists($request->string('sort')->toString(), self::SORT_COLUMNS)
            ? $request->string('sort')->toString()
            : 'title';
        $direction = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 50;
        $search = trim($request->string('search')->toString());
        $categoryId = trim($request->string('category_id')->toString());

        $itemsQuery = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->select([
                'id', 'meli_account_id', 'meli_item_id', 'sku', 'title', 'category_id', 'meli_brand',
                'brand_group_id', 'classification_status', 'current_price', 'available_quantity',
                'currency_id', 'status', 'permalink', 'thumbnail', 'last_synced_at',
            ])
            ->with('brandGroup:id,name')
            ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
            ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('classification_status', 'categorized')
            ->when($selectedBrandId, fn (Builder $query, int $id) => $query->where('brand_group_id', $id))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(fn (Builder $query) => $query
                    ->where('title', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('meli_item_id', 'like', $like)
                    ->orWhere('meli_brand', 'like', $like));
            })
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($categoryId !== '', fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($request->filled('min_price') && is_numeric($request->input('min_price')), fn (Builder $query) => $query->where('current_price', '>=', (float) $request->input('min_price')))
            ->when($request->filled('max_price') && is_numeric($request->input('max_price')), fn (Builder $query) => $query->where('current_price', '<=', (float) $request->input('max_price')));

        match ($stock) {
            'in_stock' => $itemsQuery->where('available_quantity', '>', 0),
            'out_of_stock' => $itemsQuery->where(fn (Builder $query) => $query
                ->whereNull('available_quantity')->orWhere('available_quantity', '<=', 0)),
            default => null,
        };
        match ($sync) {
            'recent' => $itemsQuery->where('last_synced_at', '>=', $staleBefore),
            'stale' => $itemsQuery->whereNotNull('last_synced_at')->where('last_synced_at', '<', $staleBefore),
            'never' => $itemsQuery->whereNull('last_synced_at'),
            default => null,
        };

        $items = $itemsQuery
            ->orderBy(self::SORT_COLUMNS[$sort], $direction)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('MeliPriceManager/Index', [
            'accounts' => $accounts,
            'selectedAccountId' => $accountId,
            'taxProfile' => $this->taxProfile($selectedAccount),
            'summary' => $this->summary($accountId, $staleBefore),
            'syncStatus' => [
                'queued' => $accountId ? $this->syncQueued($accountId) : false,
                'stale_after_hours' => $staleHours,
                'stale_before' => $staleBefore->toISOString(),
            ],
            'brands' => $this->brandSummary($accountId),
            'selectedBrandId' => $selectedBrandId,
            'items' => $items,
            'availableStatuses' => $availableStatuses,
            'availableCategories' => $availableCategories,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'category_id' => $categoryId,
                'min_price' => $request->input('min_price'),
                'max_price' => $request->input('max_price'),
                'stock' => $stock,
                'sync' => $sync,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function sync(DispatchMeliPriceManagerSyncRequest $request): RedirectResponse
    {
        $accountId = $request->integer('meli_account_id');
        $cacheKey = SyncMeliPriceManagerItemsJob::statusCacheKey($accountId);
        if ($this->syncQueued($accountId)) {
            return back()->with('warning', 'Ya existe una sincronización en cola o en proceso para esta cuenta.');
        }

        $reserved = Cache::add($cacheKey, ['queued_at' => now()->toISOString()], now()->addMinutes(30));

        if (! $reserved) {
            return back()->with('warning', 'Ya existe una sincronización en cola o en proceso para esta cuenta.');
        }

        try {
            SyncMeliPriceManagerItemsJob::dispatch($accountId);
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);
            throw $exception;
        }

        return back()->with('success', 'Sincronización enviada a la cola. No modifica publicaciones en Mercado Libre.');
    }

    private function syncQueued(int $accountId): bool
    {
        $job = new SyncMeliPriceManagerItemsJob($accountId);

        return Cache::has(SyncMeliPriceManagerItemsJob::statusCacheKey($accountId))
            || Cache::has(UniqueLock::getKey($job));
    }

    private function summary(?int $accountId, $staleBefore): array
    {
        $activeBrands = MeliBrandGroup::query()->where('active', true)->count();
        if ($accountId === null) {
            return [
                'total' => 0, 'categorized' => 0, 'suggested' => 0, 'uncategorized' => 0,
                'ignored' => 0, 'active_brands' => $activeBrands, 'pending' => 0,
                'last_synced_at' => null, 'recently_synced' => 0, 'never_synced' => 0, 'stale' => 0,
            ];
        }

        $row = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->where('meli_account_id', $accountId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN classification_status = 'categorized' THEN 1 ELSE 0 END) as categorized")
            ->selectRaw("SUM(CASE WHEN classification_status = 'suggested' THEN 1 ELSE 0 END) as suggested")
            ->selectRaw("SUM(CASE WHEN classification_status = 'uncategorized' THEN 1 ELSE 0 END) as uncategorized")
            ->selectRaw("SUM(CASE WHEN classification_status = 'ignored' THEN 1 ELSE 0 END) as ignored")
            ->selectRaw('MAX(last_synced_at) as last_synced_at')
            ->selectRaw('SUM(CASE WHEN last_synced_at >= ? THEN 1 ELSE 0 END) as recently_synced', [$staleBefore])
            ->selectRaw('SUM(CASE WHEN last_synced_at IS NULL THEN 1 ELSE 0 END) as never_synced')
            ->selectRaw('SUM(CASE WHEN last_synced_at IS NULL OR last_synced_at < ? THEN 1 ELSE 0 END) as stale', [$staleBefore])
            ->first();

        return [
            'total' => (int) $row->total,
            'categorized' => (int) $row->categorized,
            'suggested' => (int) $row->suggested,
            'uncategorized' => (int) $row->uncategorized,
            'ignored' => (int) $row->ignored,
            'active_brands' => $activeBrands,
            'pending' => (int) $row->suggested + (int) $row->uncategorized,
            'last_synced_at' => $row->last_synced_at,
            'recently_synced' => (int) $row->recently_synced,
            'never_synced' => (int) $row->never_synced,
            'stale' => (int) $row->stale,
        ];
    }

    private function brandSummary(?int $accountId)
    {
        return MeliBrandGroup::query()
            ->where('active', true)
            ->withCount(['items as categorized_items_count' => fn (Builder $query) => $query
                ->managedCatalog()
                ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
                ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('classification_status', 'categorized')])
            ->withCount(['suggestedItems as suggested_items_count' => fn (Builder $query) => $query
                ->managedCatalog()
                ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
                ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('classification_status', 'suggested')])
            ->withMin(['items as min_price' => fn (Builder $query) => $query
                ->managedCatalog()
                ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
                ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('classification_status', 'categorized')], 'current_price')
            ->withMax(['items as max_price' => fn (Builder $query) => $query
                ->managedCatalog()
                ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
                ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('classification_status', 'categorized')], 'current_price')
            ->withSum(['items as total_stock' => fn (Builder $query) => $query
                ->managedCatalog()
                ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
                ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('classification_status', 'categorized')], 'available_quantity')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'active', 'sort_order']);
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

    /** @return array<string, mixed>|null */
    private function taxProfile(?MeliAccount $account): ?array
    {
        if ($account === null || ! Schema::hasTable('meli_account_tax_profiles')) {
            return null;
        }

        $profile = $account->taxProfile()->first();
        if ($profile === null) {
            return null;
        }

        return [
            'enabled' => (bool) $profile->enabled,
            'vat_included_rate' => $profile->vat_included_rate !== null ? (float) $profile->vat_included_rate : null,
            'vat_withholding_rate' => $profile->vat_withholding_rate !== null ? (float) $profile->vat_withholding_rate : null,
            'income_tax_withholding_rate' => $profile->income_tax_withholding_rate !== null ? (float) $profile->income_tax_withholding_rate : null,
            'effective_from' => $profile->effective_from?->toDateString(),
            'notes' => $profile->notes,
        ];
    }
}
