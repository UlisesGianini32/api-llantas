<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeliUncategorizedItemController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'meli_user_id', 'nickname', 'is_default']);
        $selectedAccount = $this->selectedAccount($request, $accounts);
        $accountId = $selectedAccount?->id;
        $classificationStatus = $request->string('classification_status', 'pending')->toString();
        if (! in_array($classificationStatus, ['pending', 'uncategorized', 'suggested', 'ignored'], true)) {
            $classificationStatus = 'pending';
        }

        $query = MeliPriceManagerItem::query()
            ->focusedCatalog()
            ->select([
                'id', 'meli_account_id', 'meli_item_id', 'sku', 'title', 'category_id', 'meli_brand',
                'normalized_brand', 'brand_group_id', 'suggested_brand_group_id', 'matched_brand_alias_id',
                'classification_status', 'classification_source', 'classification_confidence',
                'classification_metadata', 'current_price', 'available_quantity', 'currency_id', 'status',
                'permalink', 'thumbnail',
            ])
            ->with([
                'brandGroup:id,name',
                'suggestedBrandGroup:id,name',
                'matchedBrandAlias:id,brand_group_id,alias,normalized_alias,match_type',
                'category:id,category_id,name',
            ])
            ->when($accountId, fn (Builder $query, int $id) => $query->where('meli_account_id', $id))
            ->when(! $accountId, fn (Builder $query) => $query->whereRaw('1 = 0'));

        match ($classificationStatus) {
            'uncategorized', 'suggested', 'ignored' => $query->where('classification_status', $classificationStatus),
            default => $query->whereIn('classification_status', ['uncategorized', 'suggested']),
        };

        $search = trim($request->string('search')->toString());
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where('title', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('meli_item_id', 'like', $like)
                    ->orWhere('meli_brand', 'like', $like);
            });
        }

        foreach (['sku', 'meli_item_id', 'meli_brand', 'category_id'] as $field) {
            $value = trim($request->string($field)->toString());
            if ($value !== '') {
                $query->where($field, 'like', '%'.$value.'%');
            }
        }

        if ($request->filled('min_price') && is_numeric($request->input('min_price'))) {
            $query->where('current_price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price') && is_numeric($request->input('max_price'))) {
            $query->where('current_price', '<=', (float) $request->input('max_price'));
        }

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true)
            ? $request->integer('per_page')
            : 25;
        $items = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        $counts = ['pending' => 0, 'uncategorized' => 0, 'suggested' => 0, 'ignored' => 0];
        if ($accountId) {
            $byStatus = MeliPriceManagerItem::query()
                ->focusedCatalog()
                ->where('meli_account_id', $accountId)
                ->whereIn('classification_status', ['uncategorized', 'suggested', 'ignored'])
                ->selectRaw('classification_status, COUNT(*) as aggregate')
                ->groupBy('classification_status')
                ->pluck('aggregate', 'classification_status');
            $counts['uncategorized'] = (int) ($byStatus['uncategorized'] ?? 0);
            $counts['suggested'] = (int) ($byStatus['suggested'] ?? 0);
            $counts['ignored'] = (int) ($byStatus['ignored'] ?? 0);
            $counts['pending'] = $counts['uncategorized'] + $counts['suggested'];
        }

        return Inertia::render('MeliPriceManager/Uncategorized', [
            'accounts' => $accounts,
            'selectedAccountId' => $accountId,
            'items' => $items,
            'brands' => MeliBrandGroup::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
            'counts' => $counts,
            'filters' => [
                'search' => $search,
                'sku' => $request->string('sku')->toString(),
                'meli_item_id' => $request->string('meli_item_id')->toString(),
                'meli_brand' => $request->string('meli_brand')->toString(),
                'category_id' => $request->string('category_id')->toString(),
                'classification_status' => $classificationStatus,
                'min_price' => $request->input('min_price'),
                'max_price' => $request->input('max_price'),
                'per_page' => $perPage,
            ],
            'matchTypes' => [
                'exact' => 'Coincidencia exacta',
                'starts_with' => 'Comienza con',
                'contains' => 'Contiene',
                'title_contains' => 'Título contiene',
                'manual' => 'Solo manual',
            ],
        ]);
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
