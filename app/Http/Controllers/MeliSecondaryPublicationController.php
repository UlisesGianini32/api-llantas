<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMeliAccountPublicationsJob;
use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use App\Models\User;
use App\Services\MeliAccountPublicationSyncService;
use App\Services\MeliRepublishService;
use App\Services\MeliSharedStockManager;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeliSecondaryPublicationController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $accounts = MeliAccount::query()
            ->where('user_id', $owner->id)
            ->orderByDesc('is_default')
            ->orderBy('nickname')
            ->get(['id', 'nickname', 'meli_user_id', 'is_default']);

        $selectedAccountId = (int) $request->integer(
            'account_id',
            (int) ($accounts->firstWhere('is_default', true)?->id ?? $accounts->first()?->id ?? 0)
        );

        if ($selectedAccountId > 0 && ! $accounts->contains('id', $selectedAccountId)) {
            abort(403, 'La cuenta de Mercado Libre seleccionada no pertenece al usuario.');
        }

        if ($request->boolean('sync_all')) {
            $account = MeliAccount::query()
                ->where('user_id', $owner->id)
                ->findOrFail($selectedAccountId);

            $syncKey = MeliAccountPublicationSyncService::cacheKey((int) $owner->id, (int) $account->id);
            $currentState = Cache::get($syncKey, []);

            if (! in_array((string) ($currentState['status'] ?? ''), ['queued', 'running'], true)) {
                Cache::put($syncKey, [
                    'status' => 'queued',
                    'phase' => 'queued',
                    'message' => 'Sincronización enviada a la cola...',
                    'account_id' => (int) $account->id,
                    'started_at' => now()->toDateTimeString(),
                ], now()->addDay());

                SyncMeliAccountPublicationsJob::dispatch((int) $owner->id, (int) $account->id)
                    ->onQueue('meli');
            }

            return redirect()
                ->route('meli.publications.index', ['account_id' => $selectedAccountId])
                ->with('ok', 'La sincronización de todas las publicaciones fue enviada a la cola.');
        }

        $search = trim((string) $request->input('search', ''));
        $filter = strtolower(trim((string) $request->input('filter', 'all')));
        $sort = strtolower(trim((string) $request->input('sort', 'updated')));
        $direction = strtolower(trim((string) $request->input('direction', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $perPage = max(20, min(100, (int) $request->integer('per_page', 40)));

        $query = MeliPublication::query()
            ->with('meliAccount:id,nickname,is_default')
            ->where('user_id', $owner->id)
            ->where('is_current', true);

        $this->applyVisibleScope($query);
        $this->applyExternallyManagedInventoryScope($query);

        if ($selectedAccountId > 0) {
            $query->where('meli_account_id', $selectedAccountId);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($q) use ($like) {
                $q->where('sku', 'like', $like)
                    ->orWhere('mlm', 'like', $like)
                    ->orWhere('source_mlm', 'like', $like)
                    ->orWhereRaw(
                        "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.title')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.title')), '') LIKE ?",
                        [$like]
                    )
                    ->orWhereRaw('CAST(raw AS CHAR) LIKE ?', [$like]);
            });
        }

        $this->applyFilter($query, $filter);
        $this->applySort($query, $sort, $direction);

        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        $publicationIds = collect($paginator->items())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $sharedMembers = MeliSharedStockMember::query()
            ->with(['group.members' => fn ($memberQuery) => $memberQuery->where('is_active', true)])
            ->where('user_id', $owner->id)
            ->where('is_active', true)
            ->whereIn('meli_publication_id', $publicationIds)
            ->get()
            ->groupBy('meli_publication_id');

        $paginator->through(fn (MeliPublication $publication) => $this->publicationToArray(
            $publication,
            $sharedMembers->get($publication->id, collect()),
        ));

        $statsQuery = MeliPublication::query()
            ->where('user_id', $owner->id)
            ->where('is_current', true);

        $this->applyVisibleScope($statsQuery);
        $this->applyExternallyManagedInventoryScope($statsQuery);

        if ($selectedAccountId > 0) {
            $statsQuery->where('meli_account_id', $selectedAccountId);
        }

        $stats = [
            'all' => (clone $statsQuery)->count(),
            'active' => (clone $statsQuery)->where('status', 'active')->count(),
            'out_of_stock' => (clone $statsQuery)
                ->whereRaw('('.$this->availableQuantitySql().') <= 0')
                ->count(),
            'paused' => (clone $statsQuery)->where('status', 'paused')->count(),
        ];

        $syncState = $selectedAccountId > 0
            ? Cache::get(MeliAccountPublicationSyncService::cacheKey((int) $owner->id, $selectedAccountId))
            : null;

        $sharedGroupIds = MeliSharedStockGroup::query()
            ->where('user_id', $owner->id)
            ->where('is_enabled', true)
            ->pluck('id');

        $sharedStats = [
            'groups' => $sharedGroupIds->count(),
            'members' => MeliSharedStockMember::query()
                ->whereIn('group_id', $sharedGroupIds)
                ->where('is_active', true)
                ->count(),
            'master_members' => MeliSharedStockMember::query()
                ->whereIn('group_id', $sharedGroupIds)
                ->where('is_active', true)
                ->where('role', 'master')
                ->count(),
            'mirror_members' => MeliSharedStockMember::query()
                ->whereIn('group_id', $sharedGroupIds)
                ->where('is_active', true)
                ->where('role', 'mirror')
                ->count(),
            'errors' => MeliSharedStockMember::query()
                ->whereIn('group_id', $sharedGroupIds)
                ->where('last_push_status', 'error')
                ->count(),
        ];

        return Inertia::render('MeliPublications/Index', [
            'accounts' => $accounts->map(fn (MeliAccount $account) => [
                'id' => $account->id,
                'nickname' => $account->nickname ?: 'Cuenta '.$account->id,
                'meli_user_id' => (string) $account->meli_user_id,
                'is_default' => (bool) $account->is_default,
            ])->values()->all(),
            'selectedAccountId' => $selectedAccountId,
            'publications' => $paginator,
            'stats' => $stats,
            'syncState' => is_array($syncState) ? $syncState : null,
            'sharedStats' => $sharedStats,
            'filters' => [
                'search' => $search,
                'filter' => $filter,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
        ]);
    }


    public function edit(Request $request, MeliPublication $publication): Response
    {
        /** @var User $owner */
        $owner = $request->user();
        $publication = $this->ownedPublication($request, $publication->id);

        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $logisticType = strtolower(
            trim((string) data_get($item, 'shipping.logistic_type', ''))
        );

        if (
            ! (bool) $publication->is_current
            || in_array((string) $publication->status, [
                'deleted',
                'under_review',
                'blocked',
                'inactive',
                'suspended',
                'closed',
            ], true)
            || $logisticType === 'fulfillment'
        ) {
            abort(404, 'Esta publicación no está disponible en el inventario de bodega.');
        }

        $sharedMembers = MeliSharedStockMember::query()
            ->with([
                'group.members' => fn ($query) => $query->where('is_active', true),
            ])
            ->where('user_id', $owner->id)
            ->where('meli_publication_id', $publication->id)
            ->where('is_active', true)
            ->get();

        return Inertia::render('MeliPublications/Edit', [
            'publication' => $this->publicationToArray(
                $publication,
                $sharedMembers,
            ),
            'backUrl' => route('meli.publications.index', [
                'account_id' => $publication->meli_account_id,
            ]),
        ]);
    }

    public function update(
        Request $request,
        MeliPublication $publication,
        MeliSharedStockManager $sharedStock,
    ): JsonResponse {
        $publication = $this->ownedPublication($request, $publication->id);

        $validated = $request->validate([
            'price' => ['nullable', 'numeric', 'min:1'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'variation_id' => ['nullable', 'string', 'max:64'],
        ]);

        if (! array_key_exists('price', $validated) && ! array_key_exists('stock', $validated)) {
            throw ValidationException::withMessages([
                'publication' => 'Debes enviar precio, stock o ambos.',
            ]);
        }

        if (! MeliPublication::permiteActualizarPrecioStock($publication->status)) {
            throw ValidationException::withMessages([
                'publication' => 'Mercado Libre no permite editar precio o stock mientras la publicación está '.$publication->status.'.',
            ]);
        }

        $snapshot = MeliPublication::itemArrayFromRaw($publication->raw);
        $variations = collect((array) ($snapshot['variations'] ?? []))
            ->filter(fn ($variation) => is_array($variation))
            ->values();
        $hasVariations = $variations->isNotEmpty();
        $variationId = filled($validated['variation_id'] ?? null)
            ? trim((string) $validated['variation_id'])
            : null;
        $logisticType = strtolower(trim((string) data_get($snapshot, 'shipping.logistic_type', '')));
        $hasStockChange = array_key_exists('stock', $validated) && $validated['stock'] !== null;

        if ($hasStockChange && $hasVariations && $variationId === null) {
            throw ValidationException::withMessages([
                'publication' => 'Selecciona la variante cuyo stock deseas modificar.',
            ]);
        }

        if ($hasStockChange && $variationId !== null && ! $variations->contains(
            fn (array $variation) => (string) ($variation['id'] ?? '') === $variationId,
        )) {
            throw ValidationException::withMessages([
                'publication' => 'La variante seleccionada ya no existe en esta publicación.',
            ]);
        }

        if ($hasStockChange && $logisticType === 'fulfillment') {
            throw ValidationException::withMessages([
                'publication' => 'El stock FULL se administra físicamente en las bodegas de Mercado Libre y no puede editarse desde este campo.',
            ]);
        }

        $sharedMember = $hasStockChange
            ? $sharedStock->memberForPublication((int) $publication->id, $variationId)
            : null;

        if ($sharedMember && $sharedMember->role !== 'master') {
            throw ValidationException::withMessages([
                'publication' => 'Esta publicación está conectada. La cuenta 1 controla el stock y la cuenta 2 recibe automáticamente el mismo valor.',
            ]);
        }

        $payload = [];

        if (array_key_exists('price', $validated) && $validated['price'] !== null) {
            $payload['price'] = round((float) $validated['price'], 2);
        }

        if ($hasStockChange && ! $sharedMember) {
            if ($variationId !== null) {
                $payload['variations'] = [[
                    'id' => is_numeric($variationId) ? (int) $variationId : $variationId,
                    'available_quantity' => (int) $validated['stock'],
                ]];
            } else {
                $payload['available_quantity'] = (int) $validated['stock'];
            }
        }

        if ($payload !== []) {
            $response = $this->meliRequest($publication, 'put', '/items/'.$publication->mlm, $payload);
            $item = $response->json();
            if (is_array($item)) {
                $this->saveSnapshot($publication, $item);
            }
        }

        $pushResult = null;
        if ($hasStockChange && $sharedMember) {
            $result = $sharedStock->setStockFromMaster(
                member: $sharedMember,
                stock: (int) $validated['stock'],
                actorUserId: (int) $request->user()->id,
                metadata: [
                    'source' => 'meli_publications_panel',
                    'publication_id' => $publication->id,
                    'mlm' => $publication->mlm,
                    'variation_id' => $variationId,
                ],
            );
            $pushResult = $result['push'];
        }

        $publication = $publication->fresh(['meliAccount:id,nickname,is_default']);
        $members = MeliSharedStockMember::query()
            ->with(['group.members' => fn ($query) => $query->where('is_active', true)])
            ->where('meli_publication_id', $publication->id)
            ->where('is_active', true)
            ->get();

        $message = $sharedMember
            ? sprintf(
                'Stock maestro actualizado. Publicaciones actualizadas: %d; omitidas: %d; errores: %d.',
                (int) ($pushResult['updated'] ?? 0),
                (int) ($pushResult['skipped'] ?? 0),
                (int) ($pushResult['errors'] ?? 0),
            )
            : 'Precio y stock actualizados en Mercado Libre.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'publication' => $this->publicationToArray($publication, $members),
        ]);
    }

    public function refresh(Request $request, MeliPublication $publication): JsonResponse
    {
        $publication = $this->ownedPublication($request, $publication->id);

        $response = $this->meliRequest($publication, 'get', '/items/'.$publication->mlm);
        $item = $response->json();

        $this->saveSnapshot($publication, is_array($item) ? $item : []);

        return response()->json([
            'success' => true,
            'message' => 'Publicación sincronizada.',
            'publication' => $this->publicationToArray($publication->fresh(['meliAccount:id,nickname,is_default'])),
        ]);
    }

    public function changeStatus(Request $request, MeliPublication $publication): JsonResponse
    {
        $publication = $this->ownedPublication($request, $publication->id);

        $validated = $request->validate([
            'status' => ['required', 'in:active,paused'],
        ]);

        $response = $this->meliRequest($publication, 'put', '/items/'.$publication->mlm, [
            'status' => $validated['status'],
        ]);

        $item = $response->json();
        $this->saveSnapshot($publication, is_array($item) ? $item : []);

        return response()->json([
            'success' => true,
            'message' => $validated['status'] === 'active' ? 'Publicación reactivada.' : 'Publicación pausada.',
            'publication' => $this->publicationToArray($publication->fresh(['meliAccount:id,nickname,is_default'])),
        ]);
    }

    public function destroy(Request $request, MeliRepublishService $service, ?MeliPublication $publication = null): JsonResponse
    {
        $publicationId = $publication?->id ?? (int) $request->input('publication_id');

        if ($publicationId <= 0) {
            throw ValidationException::withMessages([
                'publication_id' => 'Selecciona una publicación válida.',
            ]);
        }

        $publication = $this->ownedPublication($request, $publicationId);

        if (! $publication->meliAccount) {
            throw ValidationException::withMessages([
                'publication_id' => 'La publicación no tiene una cuenta de Mercado Libre asociada.',
            ]);
        }

        if ($publication->meliAccount->is_default) {
            throw ValidationException::withMessages([
                'publication_id' => 'Por seguridad, la eliminación permanente solo está habilitada para cuentas secundarias.',
            ]);
        }

        /** @var User $owner */
        $owner = $request->user();
        $apiUser = $this->makeApiUser($owner, $publication->meliAccount);

        $result = $service->deleteItemPermanently($apiUser, $publication->mlm);

        $raw = is_array($publication->raw) ? $publication->raw : [];
        $raw['deleted_from_panel_at'] = now()->toDateTimeString();

        $publication->update([
            'status' => 'deleted',
            'last_sync_at' => now(),
            'raw' => $raw,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'La publicación secundaria fue eliminada.',
            'publication_id' => $publication->id,
            'mlm' => $publication->mlm,
            'result' => $result,
        ]);
    }

    protected function ownedPublication(Request $request, int $publicationId): MeliPublication
    {
        return MeliPublication::query()
            ->with('meliAccount')
            ->where('user_id', $request->user()->id)
            ->findOrFail($publicationId);
    }

    protected function meliRequest(MeliPublication $publication, string $method, string $path, array $payload = []): HttpResponse
    {
        $account = $publication->meliAccount;

        if (! $account || empty($account->access_token)) {
            throw ValidationException::withMessages([
                'publication' => 'La cuenta de Mercado Libre no tiene un token de acceso válido.',
            ]);
        }

        $client = Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->timeout(30);

        $url = 'https://api.mercadolibre.com'.$path;

        $response = match (strtolower($method)) {
            'get' => $client->get($url, $payload),
            'post' => $client->post($url, $payload),
            'delete' => $client->delete($url, $payload),
            default => $client->put($url, $payload),
        };

        if (! $response->successful()) {
            $message = (string) data_get($response->json(), 'message', '');
            $causeMessage = (string) data_get($response->json(), 'cause.0.message', '');
            $detail = trim($causeMessage !== '' ? $causeMessage : $message);

            throw ValidationException::withMessages([
                'publication' => 'Mercado Libre respondió HTTP '.$response->status().($detail !== '' ? ': '.$detail : '.'),
            ]);
        }

        return $response;
    }

    protected function saveSnapshot(MeliPublication $publication, array $item): void
    {
        $oldRaw = is_array($publication->raw) ? $publication->raw : [];
        $moderations = $oldRaw['moderations'] ?? null;

        $raw = isset($oldRaw['item']) ? array_merge($oldRaw, ['item' => $item]) : $item;

        if ($moderations !== null && ! isset($raw['moderations'])) {
            $raw = ['item' => $item, 'moderations' => $moderations];
        }

        $publication->update([
            'status' => (string) ($item['status'] ?? $publication->status),
            'sub_status' => $item['sub_status'] ?? $publication->sub_status,
            'permalink' => $item['permalink'] ?? $publication->permalink,
            'category_id' => $item['category_id'] ?? $publication->category_id,
            'pictures' => $item['pictures'] ?? $publication->pictures,
            'raw' => $raw,
            'last_sync_at' => now(),
        ]);
    }

    protected function publicationToArray(
        MeliPublication $publication,
        ?Collection $sharedMembers = null,
    ): array {
        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $pictures = $item['pictures'] ?? $publication->pictures ?? [];
        $thumbnail = $item['thumbnail'] ?? data_get($pictures, '0.secure_url') ?? data_get($pictures, '0.url');

        $availableQuantity = (int) ($item['available_quantity'] ?? 0);
        $soldQuantity = (int) ($item['sold_quantity'] ?? 0);
        $health = isset($item['health']) && is_numeric($item['health'])
            ? round((float) $item['health'] * ((float) $item['health'] <= 1 ? 100 : 1))
            : null;

        $sharedMembers = $sharedMembers ?? collect();
        $simpleMember = $sharedMembers->first(fn (MeliSharedStockMember $member) => blank($member->variation_id));

        $rawVariations = isset($item['variations']) && is_array($item['variations']) ? $item['variations'] : [];
        $hasVariations = $rawVariations !== [];
        $logisticType = strtolower(trim((string) data_get($item, 'shipping.logistic_type', '')));
        $canUpdate = MeliPublication::permiteActualizarPrecioStock($publication->status);
        $isDefaultAccount = (bool) ($publication->meliAccount?->is_default ?? false);

        $variations = collect($rawVariations)->map(function ($variation) use (
            $sharedMembers,
            $canUpdate,
            $logisticType,
            $isDefaultAccount,
        ): array {
            $variation = is_array($variation) ? $variation : [];
            $variationId = trim((string) ($variation['id'] ?? ''));
            $member = $sharedMembers->first(
                fn (MeliSharedStockMember $candidate) => (string) $candidate->variation_id === $variationId,
            );
            $group = $member?->group;
            $attributes = collect((array) ($variation['attribute_combinations'] ?? []))
                ->filter(fn ($attribute) => is_array($attribute))
                ->map(function (array $attribute): string {
                    $name = trim((string) ($attribute['name'] ?? $attribute['id'] ?? 'Variante'));
                    $value = trim((string) ($attribute['value_name'] ?? $attribute['value_id'] ?? ''));

                    return $value !== '' ? $name.': '.$value : $name;
                })
                ->filter()
                ->values();

            $sku = $this->extractSkuFromItem($variation, '');
            $stock = $group ? max(0, (int) $group->stock) : max(0, (int) ($variation['available_quantity'] ?? 0));
            $connectedMembers = $group?->members?->where('is_active', true)->count() ?? 0;

            return [
                'id' => $variationId,
                'label' => $attributes->isNotEmpty() ? $attributes->implode(' · ') : 'Variante '.$variationId,
                'sku' => $sku,
                'stock' => $stock,
                'raw_stock' => max(0, (int) ($variation['available_quantity'] ?? 0)),
                'shared' => (bool) $member,
                'shared_role' => $member?->role,
                'shared_group_id' => $group?->id,
                'connected_members' => $connectedMembers,
                'can_update_stock' => $canUpdate
                    && $logisticType !== 'fulfillment'
                    && (! $member || ($member->role === 'master' && $isDefaultAccount)),
                'last_push_status' => $member?->last_push_status,
                'last_error' => $member?->last_error,
            ];
        })->values()->all();

        if ($simpleMember?->group) {
            $availableQuantity = max(0, (int) $simpleMember->group->stock);
        }

        $canUpdateStock = $canUpdate
            && ! $hasVariations
            && $logisticType !== 'fulfillment'
            && (! $simpleMember || ($simpleMember->role === 'master' && $isDefaultAccount));

        $visits = data_get($publication->raw, 'metrics.visits')
            ?? data_get($publication->raw, 'visits')
            ?? data_get($item, 'visits');

        $conversion = data_get($publication->raw, 'metrics.conversion')
            ?? data_get($publication->raw, 'conversion');

        return [
            'id' => $publication->id,
            'meli_account_id' => $publication->meli_account_id,
            'account_name' => $publication->meliAccount?->nickname ?: 'Cuenta '.$publication->meli_account_id,
            'is_default_account' => $isDefaultAccount,
            'sku' => (string) ($publication->sku ?? ''),
            'mlm' => (string) $publication->mlm,
            'source_mlm' => (string) ($publication->source_mlm ?? ''),
            'title' => (string) ($item['title'] ?? 'Publicación sin título'),
            'thumbnail' => $thumbnail,
            'status' => (string) ($publication->status ?? ''),
            'status_label' => MeliPublication::etiquetaEstadoPublicacion($publication->status) ?? 'SIN ESTADO',
            'sub_status' => $publication->sub_status_text,
            'block_reason' => $publication->block_reason,
            'price' => MeliPublication::listPriceFromRaw($publication->raw),
            'stock' => $availableQuantity,
            'raw_stock' => max(0, (int) ($item['available_quantity'] ?? 0)),
            'sold_quantity' => $soldQuantity,
            'health' => $health,
            'visits' => is_numeric($visits) ? (int) $visits : null,
            'conversion' => is_numeric($conversion) ? round((float) $conversion, 2) : null,
            'listing_type_id' => $item['listing_type_id'] ?? null,
            'catalog_listing' => (bool) ($item['catalog_listing'] ?? false),
            'logistic_type' => $logisticType,
            'has_variations' => $hasVariations,
            'variations_count' => count($variations),
            'variations' => $variations,
            'shared' => (bool) $simpleMember || $sharedMembers->isNotEmpty(),
            'shared_role' => $simpleMember?->role,
            'shared_group_id' => $simpleMember?->group?->id,
            'connected_members' => $simpleMember?->group?->members?->where('is_active', true)->count() ?? 0,
            'shared_last_push_status' => $simpleMember?->last_push_status,
            'shared_last_error' => $simpleMember?->last_error,
            'permalink' => $publication->permalink ?: ($item['permalink'] ?? null),
            'last_sync_at' => optional($publication->last_sync_at)->format('d/m/Y H:i'),
            'can_update' => $canUpdate,
            'can_update_stock' => $canUpdateStock,
            'can_delete' => ! $isDefaultAccount,
        ];
    }

    /** @param array<string, mixed> $item */
    protected function extractSkuFromItem(array $item, string $fallback = ''): string
    {
        $sellerSkuAttribute = collect((array) ($item['attributes'] ?? []))
            ->first(fn ($attribute) => is_array($attribute) && strtoupper((string) ($attribute['id'] ?? '')) === 'SELLER_SKU');

        $candidates = [
            $item['seller_custom_field'] ?? null,
            is_array($sellerSkuAttribute)
                ? ($sellerSkuAttribute['value_name'] ?? $sellerSkuAttribute['value_id'] ?? null)
                : null,
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }


    /**
     * Oculta del Centro de Publicaciones los inventarios administrados
     * desde otros módulos:
     * - llantas individuales
     * - pares y juegos registrados en producto_compuestos
     * - productos SYSCOM
     *
     * El filtro solo afecta esta pantalla y sus contadores.
     */
    protected function applyExternallyManagedInventoryScope($query): void
    {
        $query->where(function ($outerQuery) {
            $outerQuery
                ->whereNull('sku')
                ->orWhere('sku', '')
                ->orWhere(function ($skuQuery) {
                    $skuQuery
                        ->where('sku', 'not like', 'SYSCOM-%')
                        ->whereNotExists(function ($subQuery) {
                            $subQuery
                                ->selectRaw('1')
                                ->from('llantas as hidden_llantas')
                                ->whereColumn(
                                    'hidden_llantas.sku',
                                    'meli_publications.sku'
                                );
                        })
                        ->whereNotExists(function ($subQuery) {
                            $subQuery
                                ->selectRaw('1')
                                ->from(
                                    'producto_compuestos as hidden_compuestos'
                                )
                                ->whereColumn(
                                    'hidden_compuestos.sku',
                                    'meli_publications.sku'
                                );
                        });
                });
        });
    }

    protected function applyFilter($query, string $filter): void
    {
        match ($filter) {
            'active' => $query->where('status', 'active'),
            'out_of_stock' => $query->whereRaw('('.$this->availableQuantitySql().') <= 0'),
            'paused' => $query->where('status', 'paused'),
            default => null,
        };
    }

    protected function applyVisibleScope($query): void
    {
        $query->where(function ($scope): void {
            $scope->whereNull('status')
                ->orWhereNotIn('status', [
                    'deleted',
                    'under_review',
                    'blocked',
                    'inactive',
                    'suspended',
                    'closed',
                ]);
        });

        /*
         * Este centro muestra solamente publicaciones cuyo inventario
         * prepara y controla la bodega propia. Las publicaciones FULL
         * permanecen disponibles en el módulo independiente Inventario FULL.
         *
         * raw puede guardar el item directamente o dentro de { item: ... }.
         */
        $query->whereRaw(
            "LOWER(TRIM(COALESCE(".
            "JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.shipping.logistic_type')), ".
            "JSON_UNQUOTE(JSON_EXTRACT(raw, '$.shipping.logistic_type')), ".
            "''".
            "))) <> ?",
            ['fulfillment']
        );
    }

    protected function applySort($query, string $sort, string $direction): void
    {
        match ($sort) {
            'stock' => $query->orderByRaw($this->availableQuantitySql().' '.$direction),
            'price' => $query->orderByRaw($this->priceSql().' '.$direction),
            'sales' => $query->orderByRaw($this->soldQuantitySql().' '.$direction),
            'quality' => $query->orderByRaw($this->healthSql().' '.$direction),
            'title' => $query->orderByRaw($this->titleSql().' '.$direction),
            default => $query->orderBy('updated_at', $direction),
        };

        $query->orderByDesc('id');
    }

    protected function availableQuantitySql(): string
    {
        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.available_quantity')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.available_quantity')), 0) AS SIGNED)";
    }

    protected function soldQuantitySql(): string
    {
        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.sold_quantity')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.sold_quantity')), 0) AS SIGNED)";
    }

    protected function priceSql(): string
    {
        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.price')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.price')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.base_price')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.base_price')), 0) AS DECIMAL(14,2))";
    }

    protected function healthSql(): string
    {
        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.health')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.health')), 0) AS DECIMAL(10,4))";
    }

    protected function titleSql(): string
    {
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.item.title')), JSON_UNQUOTE(JSON_EXTRACT(raw, '$.title')), '')";
    }

    protected function makeApiUser(User $owner, MeliAccount $account): User
    {
        /** @var User $apiUser */
        $apiUser = clone $owner;

        $apiUser->forceFill([
            'meli_id' => $account->meli_user_id,
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_at' => $account->expires_at,
            'official_store_id' => $account->official_store_id,
        ]);

        $apiUser->setAttribute('id', $owner->id);

        return $apiUser;
    }
}
