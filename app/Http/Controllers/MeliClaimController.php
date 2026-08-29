<?php

namespace App\Http\Controllers;

use App\Models\MeliClaim;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeliClaimController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->meliAccounts()->orderByDesc('is_default')->orderBy('id')->get(['id', 'nickname', 'meli_user_id', 'is_default']);
        $account = $request->filled('account') ? $accounts->firstWhere('id', $request->integer('account')) : ($accounts->firstWhere('is_default', true) ?? $accounts->first());
        abort_if($request->filled('account') && ! $account, 404);
        $filters = collect(['status', 'stage', 'type', 'action_responsible'])->mapWithKeys(fn ($key) => [$key => trim($request->string($key)->toString())])->all();
        $reputation = $request->string('reputation', 'all')->toString();
        $search = trim($request->string('search')->toString());

        $base = MeliClaim::query()->whereIn('meli_account_id', $accounts->pluck('id'))
            ->when($account, fn (Builder $q) => $q->where('meli_account_id', $account->id))
            ->when(! $account, fn (Builder $q) => $q->whereRaw('1 = 0'));
        $query = (clone $base)->with(['meliAccount:id,nickname,meli_user_id', 'reason:reason_id,name', 'order.items'])
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['stage'] !== '', fn (Builder $q) => $q->where('stage', $filters['stage']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('type', $filters['type']))
            ->when($filters['action_responsible'] !== '', fn (Builder $q) => $q->where('action_responsible', $filters['action_responsible']))
            ->when($reputation === 'yes', fn (Builder $q) => $q->where('affects_reputation', true))
            ->when($reputation === 'no', fn (Builder $q) => $q->where('affects_reputation', false))
            ->when($reputation === 'unknown', fn (Builder $q) => $q->whereNull('affects_reputation'))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $q) => $q->where('claim_id', 'like', $like)
                    ->orWhere('order_id', 'like', $like)->orWhere('pack_id', 'like', $like)
                    ->orWhereHas('order.items', fn (Builder $items) => $items->where('item_id', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('title', 'like', $like)));
            });

        $claims = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('last_updated')->paginate(25)->withQueryString()
            ->through(fn (MeliClaim $claim) => $this->claimData($claim));
        $stats = (clone $base)->selectRaw("SUM(CASE WHEN status NOT IN ('closed','resolved') THEN 1 ELSE 0 END) as open_count")
            ->selectRaw("SUM(CASE WHEN status NOT IN ('closed','resolved') AND action_responsible IN ('seller','respondent') THEN 1 ELSE 0 END) as action_count")
            ->selectRaw("SUM(CASE WHEN status NOT IN ('closed','resolved') AND due_date IS NOT NULL AND due_date <= ? THEN 1 ELSE 0 END) as due_count", [now()->addDay()])
            ->selectRaw("SUM(CASE WHEN stage IN ('dispute','mediation') THEN 1 ELSE 0 END) as mediation_count")
            ->selectRaw("SUM(CASE WHEN status IN ('closed','resolved') THEN 1 ELSE 0 END) as closed_count")->first();

        return Inertia::render('MeliClaims/Index', [
            'accounts' => $accounts, 'selectedAccountId' => $account?->id, 'claims' => $claims,
            'stats' => ['open' => (int) $stats->open_count, 'action' => (int) $stats->action_count, 'due' => (int) $stats->due_count, 'mediation' => (int) $stats->mediation_count, 'closed' => (int) $stats->closed_count],
            'filters' => [...$filters, 'reputation' => $reputation, 'search' => $search],
            'options' => ['statuses' => (clone $base)->whereNotNull('status')->distinct()->orderBy('status')->pluck('status'), 'stages' => (clone $base)->whereNotNull('stage')->distinct()->orderBy('stage')->pluck('stage'), 'types' => (clone $base)->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')],
            'lastSyncedAt' => (clone $base)->max('last_synced_at'),
        ]);
    }

    public function show(Request $request, MeliClaim $claim): Response
    {
        abort_unless($request->user()->meliAccounts()->whereKey($claim->meli_account_id)->exists(), 404);
        $claim->load(['meliAccount:id,nickname,meli_user_id', 'reason:reason_id,name,detail', 'order.items']);
        return Inertia::render('MeliClaims/Show', ['claim' => [...$this->claimData($claim),
            'raw_detail' => $claim->raw_detail, 'status_history' => $claim->status_history ?? [],
            'actions_history' => $claim->actions_history ?? [], 'expected_resolutions' => $claim->expected_resolutions ?? [],
            'available_actions' => $claim->available_actions ?? [], 'reputation_has_incentive' => $claim->reputation_has_incentive,
            'reputation_due_date' => $claim->reputation_due_date?->toISOString(), 'resolution_reason' => $claim->resolution_reason,
        ]]);
    }

    public function sync(Request $request, MeliClaimsService $service): RedirectResponse
    {
        $account = $request->user()->meliAccounts()->findOrFail($request->integer('account_id'));
        try { $result = $service->syncAccount($account, null, 30, true); return back()->with('ok', "Reclamos actualizados: {$result['saved']}."); }
        catch (\Throwable $e) { report($e); return back()->with('err', 'No fue posible sincronizar reclamos: '.$e->getMessage()); }
    }

    private function claimData(MeliClaim $claim): array
    {
        $item = $claim->order?->items?->first();
        $sellerActs = in_array($claim->action_responsible, ['seller', 'respondent'], true);
        $open = ! in_array($claim->status, ['closed', 'resolved'], true);
        $critical = $open && (($sellerActs && $claim->due_date?->lte(now()->addDay())) || ($claim->affects_reputation && $claim->reputation_due_date?->lte(now()->addDay())));
        return [
            'id' => $claim->id, 'claim_id' => $claim->claim_id, 'order_id' => $claim->order_id, 'pack_id' => $claim->pack_id,
            'type' => $claim->type, 'stage' => $claim->stage, 'status' => $claim->status, 'reason' => $claim->reason?->name ?? $claim->reason_id,
            'detail_title' => $claim->detail_title, 'detail_description' => $claim->detail_description, 'problem' => $claim->problem,
            'action_responsible' => $claim->action_responsible, 'due_date' => $claim->due_date?->toISOString(),
            'affects_reputation' => $claim->affects_reputation, 'date_created' => $claim->date_created?->toISOString(),
            'last_updated' => $claim->last_updated?->toISOString(), 'last_synced_at' => $claim->last_synced_at?->toISOString(), 'sync_error' => $claim->sync_error,
            'urgency' => $critical ? 'critical' : ($open && $sellerActs ? 'attention' : 'waiting'),
            'account' => $claim->meliAccount, 'product' => $item ? ['mlm' => $item->item_id, 'sku' => $item->sku, 'title' => $item->title, 'quantity' => $item->quantity] : null,
            'order_amount' => $claim->order?->items?->sum(fn ($item) => (float) $item->unit_price * $item->quantity),
        ];
    }
}
