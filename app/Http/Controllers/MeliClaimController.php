<?php

namespace App\Http\Controllers;

use App\Models\MeliClaim;
use App\Models\MeliPublication;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use Illuminate\Support\Collection;
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
        $query = (clone $base)->with(['meliAccount:id,nickname,meli_user_id,is_default', 'reason:reason_id,name,detail', 'order.items'])
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
                    ->orWhereHas('order', fn (Builder $orders) => $orders
                        ->whereColumn('meli_orders.meli_account_id', 'meli_claims.meli_account_id')
                        ->whereHas('items', fn (Builder $items) => $items->where('item_id', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('title', 'like', $like))));
            });

        $claims = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('last_updated')->paginate(25)->withQueryString();
        $publications = $this->publicationMap($claims->getCollection());
        $claims->through(fn (MeliClaim $claim) => $this->claimData($claim, $publications));
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
        $claim->load(['meliAccount:id,nickname,meli_user_id,is_default', 'reason:reason_id,name,detail', 'order.items']);
        $publications = $this->publicationMap(collect([$claim]));
        return Inertia::render('MeliClaims/Show', ['claim' => [...$this->claimData($claim, $publications),
            'raw_detail' => $claim->raw_detail, 'status_history' => $claim->status_history ?? [],
            'actions_history' => $claim->actions_history ?? [], 'expected_resolutions' => $this->withoutParticipantIds($claim->expected_resolutions ?? []),
            'available_actions' => $claim->available_actions ?? [], 'reputation_has_incentive' => $claim->reputation_has_incentive,
            'reputation_due_date' => $claim->reputation_due_date?->toISOString(), 'resolution_reason' => $claim->resolution_reason,
            'messages' => $this->withoutParticipantIds($claim->messages ?? []), 'changes' => $this->withoutParticipantIds($claim->changes ?? []),
            'participants' => $this->participants($claim), 'deadlines' => $this->deadlines($claim),
            'timeline' => $this->timeline($claim),
            'order' => $this->orderData($claim),
        ]]);
    }

    public function sync(Request $request, MeliClaimsService $service): RedirectResponse
    {
        $account = $request->user()->meliAccounts()->findOrFail($request->integer('account_id'));
        try { $result = $service->syncAccount($account, null, 30, true); return back()->with('ok', "Reclamos actualizados: {$result['saved']}."); }
        catch (\Throwable $e) { report($e); return back()->with('err', 'No fue posible sincronizar reclamos: '.$e->getMessage()); }
    }

    public function refresh(Request $request, MeliClaim $claim, MeliClaimsService $service): RedirectResponse
    {
        $account = $request->user()->meliAccounts()->findOrFail($claim->meli_account_id);

        try {
            $service->syncClaim($account, $claim->claim_id, true);

            return redirect()->route('meli.claims.show', $claim)->with('ok', 'Reclamo actualizado.');
        } catch (\Throwable $e) {
            report($e);
            $claim->forceFill(['sync_error' => $service->safeErrorMessage($e)])->save();

            return redirect()->route('meli.claims.show', $claim)
                ->with('err', 'No fue posible actualizar la información del reclamo.');
        }
    }

    private function claimData(MeliClaim $claim, Collection $publications): array
    {
        $order = $claim->order && (int) $claim->order->meli_account_id === (int) $claim->meli_account_id
            ? $claim->order
            : null;
        $products = $order?->items?->map(function ($item) use ($claim, $publications, $order): array {
            $publication = $publications->get($claim->meli_account_id.'|'.$item->item_id);
            $publicationItem = MeliPublication::itemArrayFromRaw($publication?->raw);
            $unitPrice = $item->unit_price !== null ? (float) $item->unit_price : null;

            return [
                'mlm' => $item->item_id,
                'sku' => $item->sku ?: $publication?->sku,
                'title' => $item->title ?: ($publicationItem['title'] ?? null),
                'thumbnail' => $this->publicationThumbnail($publication),
                'variation' => $item->variation_text,
                'quantity' => (int) $item->quantity,
                'unit_price' => $unitPrice,
                'amount' => $unitPrice !== null ? $unitPrice * (int) $item->quantity : null,
                'variation_id' => $this->variationId($order, $item->item_id, $item->sku),
                'variation_text' => $item->variation_text,
            ];
        })->values()->all() ?? [];
        $sellerActs = in_array($claim->action_responsible, ['seller', 'respondent'], true);
        $open = ! in_array($claim->status, ['closed', 'resolved'], true);
        $critical = $open && (($sellerActs && $claim->due_date?->lte(now()->addDay())) || ($claim->affects_reputation && $claim->reputation_due_date?->lte(now()->addDay())));
        return [
            'id' => $claim->id, 'claim_id' => $claim->claim_id, 'order_id' => $claim->order_id, 'pack_id' => $claim->pack_id,
            'type' => $claim->type, 'stage' => $claim->stage, 'status' => $claim->status,
            'reason' => $claim->reason?->detail ?: ($claim->reason?->name ?: $claim->reason_id), 'reason_id' => $claim->reason_id,
            'detail_title' => $claim->detail_title, 'detail_description' => $claim->detail_description, 'problem' => $claim->problem,
            'action_responsible' => $claim->action_responsible, 'due_date' => $claim->due_date?->toISOString(),
            'affects_reputation' => $claim->affects_reputation, 'date_created' => $claim->date_created?->toISOString(),
            'last_updated' => $claim->last_updated?->toISOString(), 'last_synced_at' => $claim->last_synced_at?->toISOString(), 'sync_error' => $claim->sync_error,
            'urgency' => $critical ? 'critical' : ($open && $sellerActs ? 'attention' : 'waiting'),
            'account' => $claim->meliAccount ? ['id' => $claim->meliAccount->id, 'nickname' => $claim->meliAccount->nickname, 'meli_user_id' => $claim->meliAccount->meli_user_id, 'is_default' => (bool) $claim->meliAccount->is_default] : null,
            'products' => $products, 'product' => $products[0] ?? null,
            'order_amount' => collect($products)->contains(fn (array $product) => $product['amount'] !== null)
                ? collect($products)->sum(fn (array $product) => $product['amount'] ?? 0)
                : null,
        ];
    }

    private function publicationMap(Collection $claims): Collection
    {
        $pairs = $claims->filter(fn (MeliClaim $claim) => $claim->order && (int) $claim->order->meli_account_id === (int) $claim->meli_account_id)
            ->flatMap(fn (MeliClaim $claim) => $claim->order->items->map(fn ($item) => ['account' => $claim->meli_account_id, 'mlm' => $item->item_id]))
            ->filter(fn (array $pair) => filled($pair['mlm']))->unique(fn (array $pair) => $pair['account'].'|'.$pair['mlm']);

        if ($pairs->isEmpty()) {
            return collect();
        }

        return MeliPublication::query()
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn (Builder $candidate) => $candidate->where('meli_account_id', $pair['account'])->where('mlm', $pair['mlm']));
                }
            })
            ->orderByDesc('id')->get(['id', 'meli_account_id', 'mlm', 'sku', 'raw'])
            ->unique(fn (MeliPublication $publication) => $publication->meli_account_id.'|'.$publication->mlm)
            ->keyBy(fn (MeliPublication $publication) => $publication->meli_account_id.'|'.$publication->mlm);
    }

    private function publicationThumbnail(?MeliPublication $publication): ?string
    {
        $item = MeliPublication::itemArrayFromRaw($publication?->raw);
        $picture = is_array($item['pictures'][0] ?? null) ? $item['pictures'][0] : [];

        return $item['secure_thumbnail'] ?? $item['thumbnail'] ?? $picture['secure_url'] ?? $picture['url'] ?? null;
    }

    private function withoutParticipantIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return collect($value)->reject(fn (mixed $_, string|int $key) => $key === 'user_id')
            ->map(fn (mixed $item) => $this->withoutParticipantIds($item))->all();
    }

    private function participants(MeliClaim $claim): array
    {
        return collect((array) data_get($claim->raw_claim, 'players', []))
            ->filter('is_array')
            ->map(fn (array $player): array => array_filter([
                'role' => $player['role'] ?? null,
                'type' => $player['type'] ?? null,
            ], fn (mixed $value): bool => filled($value)))
            ->values()->all();
    }

    private function timeline(MeliClaim $claim): array
    {
        $events = collect($this->listPayload($claim->status_history))->map(fn (array $item): array => [...$item, 'source' => 'status']);
        $events = $events->concat(collect($this->listPayload($claim->actions_history))->map(fn (array $item): array => [...$item, 'source' => 'action']));
        $events = $events->concat(collect($this->listPayload($claim->changes))->map(fn (array $item): array => [...$item, 'source' => 'change']));

        return $events->sortByDesc(fn (array $item): string => (string) ($item['date'] ?? $item['date_created'] ?? $item['created_at'] ?? ''))
            ->values()->all();
    }

    private function deadlines(MeliClaim $claim): array
    {
        $deadlines = collect((array) data_get($claim->raw_claim, 'players', []))
            ->filter('is_array')->flatMap(fn (array $player) => collect((array) ($player['available_actions'] ?? []))
                ->filter('is_array')->map(fn (array $action): array => [
                    'role' => $player['role'] ?? $player['type'] ?? null,
                    'action' => $action['action'] ?? $action['name'] ?? null,
                    'due_date' => $action['due_date'] ?? null,
                    'mandatory' => $action['mandatory'] ?? null,
                ])->all());

        if ($claim->due_date !== null && $deadlines->where('due_date', $claim->due_date->toISOString())->isEmpty()) {
            $deadlines->push(['role' => $claim->action_responsible, 'action' => null, 'due_date' => $claim->due_date->toISOString(), 'mandatory' => null]);
        }

        return $deadlines->filter(fn (array $item): bool => filled($item['due_date']))->sortBy('due_date')->values()->all();
    }

    private function listPayload(mixed $value): array
    {
        if (! is_array($value)) return [];
        $items = array_is_list($value) ? $value : ($value['data'] ?? $value['results'] ?? []);

        return array_values(array_filter((array) $items, 'is_array'));
    }

    private function orderData(MeliClaim $claim): ?array
    {
        $order = $claim->order;
        if (! $order || (int) $order->meli_account_id !== (int) $claim->meli_account_id) return null;
        $raw = (array) $order->raw;

        return [
            'id' => $order->id,
            'order_id' => $order->order_id,
            'display_id' => $order->display_id,
            'status' => $order->status,
            'date_created' => data_get($raw, 'date_created'),
            'total_amount' => is_numeric(data_get($raw, 'total_amount')) ? (float) data_get($raw, 'total_amount') : null,
            'currency_id' => data_get($raw, 'currency_id'),
        ];
    }

    private function variationId($order, ?string $itemId, ?string $sku): ?string
    {
        foreach ((array) data_get($order?->raw, 'order_items', []) as $row) {
            if (! is_array($row) || (string) data_get($row, 'item.id') !== (string) $itemId) continue;
            $remoteSku = data_get($row, 'item.seller_sku');
            if (filled($sku) && filled($remoteSku) && (string) $remoteSku !== (string) $sku) continue;
            $id = data_get($row, 'item.variation_id');
            return filled($id) ? (string) $id : null;
        }

        return null;
    }
}
