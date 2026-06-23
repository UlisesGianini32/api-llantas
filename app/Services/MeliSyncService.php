<?php

namespace App\Services;

use App\Models\User;
use App\Models\Llanta;
use App\Models\SyscomMeliQueue;
use App\Models\ProductoCompuesto;
use App\Models\MeliPublication;
use App\Services\SyscomMeliPublishService;
use App\Services\SyscomProductPricingService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MeliSyncService
{
    protected $user;
    protected $client;
    protected $meliId;
    protected $accessToken;

    public function __construct(protected MeliOAuthService $meliOAuth)
    {
        $this->user = User::whereNotNull('meli_id')
            ->whereNotNull('access_token')
            ->first();

        if (!$this->user) {
            throw new \Exception('No hay usuario con cuenta de MercadoLibre vinculada.');
        }

        $this->meliId      = $this->user->meli_id;
        $this->accessToken = $this->user->access_token;

        $this->buildClient();
    }

    protected function buildClient(): void
    {
        $this->client = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    protected function syncConcurrency(): int
    {
        return max(1, min(25, (int) config('services.meli.sync_concurrency', 8)));
    }

    protected function noopSkipPutEnabled(): bool
    {
        return (bool) config('services.meli.sync_skip_noop_puts', true);
    }

    /**
     * Cuerpo de ítem tal como viene en nuestra tabla (PUT devuelve el ítem plano o anidado bajo item).
     */
    protected function itemPayloadFromPublication(?MeliPublication $pub): ?array
    {
        if (! $pub || ! is_array($pub->raw)) {
            return null;
        }

        $r = $pub->raw;
        if (isset($r['item']) && is_array($r['item'])) {
            return $r['item'];
        }

        return $r;
    }

    protected function pricesRoughlyEqual(float $a, float $b): bool
    {
        return (int) round($a * 100 + 1e-9) === (int) round($b * 100 + 1e-9);
    }

    /**
     * ¿El último estado guardado ya coincide con lo que enviaríamos en PUT?
     */
    protected function rowMatchesPublication(array $row, ?MeliPublication $pub): bool
    {
        $item = $this->itemPayloadFromPublication($pub);
        if ($item === null) {
            return false;
        }

        $targetQty = (int) $row['stock'];
        $curQty = (int) ($item['available_quantity'] ?? PHP_INT_MIN);
        if ($curQty !== $targetQty) {
            return false;
        }

        if (! isset($item['price'])) {
            return false;
        }

        $curPrice = (float) $item['price'];
        if (! $this->pricesRoughlyEqual($curPrice, (float) $row['price'])) {
            return false;
        }

        if (isset($row['status']) && trim((string) $row['status']) !== '') {
            $want = mb_strtolower(trim((string) $row['status']));
            $have = mb_strtolower(trim((string) ($item['status'] ?? '')));
            if ($have === '') {
                $have = mb_strtolower(trim((string) ($pub->status ?? '')));
            }

            return $want === $have;
        }

        return true;
    }

    /**
     * Sincroniza stock, precio y publicaciones
     */
    public function syncStock()
    {
        Log::info('MELI SYNC: Iniciando sincronización');
        $this->ensureFreshToken();

        $this->syncLlantas();
        $this->syncProductosCompuestos();
        $this->syncSyscomPublications();

        Log::info('MELI SYNC: Sincronización completada');
        Log::info('==============================');
    }

    /**
     * Solo publicaciones SYSCOM (stock Hermosillo + pausa si no hay existencia local).
     */
    public function syncSyscomPublicationsOnly(): void
    {
        $this->ensureFreshToken();
        $this->syncSyscomPublications();
    }

    protected function syncLlantas()
    {
        $llantas = Llanta::all();
        Log::info("MELI SYNC: Sincronizando {$llantas->count()} llantas");

        foreach ($llantas as $llanta) {
            try {
                Log::debug("MELI SYNC: [LLANTA] Procesando SKU {$llanta->sku} | stock={$llanta->stock} | precio={$llanta->precio_ML}");

                $mlms = $this->findItemsBySku($llanta->sku);

                if (!empty($mlms)) {

                    // (Opcional) mantener columna MLM principal
                    $mlmPrincipal = $mlms[0];
                    if ($llanta->MLM !== $mlmPrincipal) {
                        $llanta->MLM = $mlmPrincipal;
                        $llanta->save();
                        Log::info("MELI SYNC: MLM principal actualizado -> {$llanta->sku} -> {$mlmPrincipal}");
                    }

                    $pauseAt = max(0, (int) config('services.meli.pause_llantas_when_stock_equals', 1));

                    $pubsByMlm = MeliPublication::query()
                        ->where('user_id', $this->user->id)
                        ->whereIn('mlm', $mlms)
                        ->get()
                        ->keyBy('mlm');

                    $rows = [];
                    foreach ($mlms as $mlm) {
                        $stock = (int) $llanta->stock;
                        $price = (float) $llanta->precio_ML;

                        $pub = $pubsByMlm->get($mlm);
                        $mlStatus = mb_strtolower((string) ($pub->status ?? ''));

                        $row = ['sku' => $llanta->sku, 'mlm' => $mlm, 'stock' => $stock, 'price' => $price];

                        if ($pauseAt > 0 && $stock === $pauseAt) {
                            $row['stock'] = max(1, $stock);
                            $row['status'] = 'paused';
                            $rows[] = $row;
                            Log::info("MELI SYNC: [LLANTA] SKU {$llanta->sku} stock={$stock} -> pausada en ML ({$mlm})");

                            continue;
                        }

                        $resume = ($pauseAt > 0 && $stock > $pauseAt && $mlStatus === 'paused');
                        if ($resume) {
                            $row['status'] = 'active';
                        }

                        $rows[] = $row;
                    }

                    $this->applyItemUpdatesConcurrent($rows, $pubsByMlm);

                    Log::info("MELI SYNC: [LLANTA] OK -> SKU {$llanta->sku} | MLMs=" . implode(',', $mlms));

                } else {
                    Log::warning("MELI SYNC: [LLANTA] NO encontrado en ML -> SKU {$llanta->sku}");
                }

            } catch (\Throwable $e) {
                Log::error("MELI SYNC: Error llanta {$llanta->sku}: " . $e->getMessage());
            }
        }
    }

    protected function syncProductosCompuestos()
    {
        $productos = ProductoCompuesto::all();
        Log::info("MELI SYNC: Sincronizando {$productos->count()} compuestos");

        foreach ($productos as $producto) {
            try {
                Log::debug("MELI SYNC: [COMP] Procesando SKU {$producto->sku} | tipo={$producto->tipo} | stock={$producto->stock} | precio={$producto->precio_ML}");

                $mlms = $this->findItemsBySku($producto->sku);

                if (!empty($mlms)) {

                    $mlmPrincipal = $mlms[0];
                    if ($producto->MLM !== $mlmPrincipal) {
                        $producto->MLM = $mlmPrincipal;
                        $producto->save();
                        Log::info("MELI SYNC: MLM principal actualizado -> {$producto->sku} -> {$mlmPrincipal}");
                    }

                    $rows = [];
                    foreach ($mlms as $mlm) {
                        $rows[] = [
                            'sku' => $producto->sku,
                            'mlm' => $mlm,
                            'stock' => (int) $producto->stock,
                            'price' => (float) $producto->precio_ML,
                        ];
                    }

                    $this->applyItemUpdatesConcurrent($rows);

                    Log::info("MELI SYNC: [COMP] OK -> SKU {$producto->sku} | MLMs=" . implode(',', $mlms));

                } else {
                    Log::warning("MELI SYNC: [COMP] NO encontrado en ML -> SKU {$producto->sku} ({$producto->tipo})");
                }

            } catch (\Throwable $e) {
                Log::error("MELI SYNC: Error compuesto {$producto->sku}: " . $e->getMessage());
            }
        }
    }

    protected function syncSyscomPublications(): void
    {
        $pricing = app(SyscomProductPricingService::class);
        $queues = SyscomMeliQueue::query()
            ->whereNotNull('mlm')
            ->where('mlm', '!=', '')
            ->with('product')
            ->get();

        if ($queues->isEmpty()) {
            return;
        }

        Log::info('MELI SYNC: Sincronizando publicaciones SYSCOM: '.$queues->count());

        foreach ($queues->groupBy('user_id') as $userId => $list) {
            $mlUser = User::query()
                ->where('id', (int) $userId)
                ->whereNotNull('access_token')
                ->whereNotNull('meli_id')
                ->first();
            if (! $mlUser) {
                continue;
            }

            $this->runWithMeliUser($mlUser, function () use ($list, $pricing, $mlUser) {
                $pauseAtOrBelow = (int) config('services.meli.pause_syscom_when_stock_at_or_below', 0);

                $mlms = $list->pluck('mlm')->filter()->map(fn ($m) => (string) $m)->values()->all();
                $pubsByMlm = MeliPublication::query()
                    ->where('user_id', $mlUser->id)
                    ->whereIn('mlm', $mlms)
                    ->get()
                    ->keyBy('mlm');

                $rows = [];
                foreach ($list as $q) {
                    $p = $q->product;
                    if (! $p) {
                        continue;
                    }
                    try {
                        $scope = (string) ($q->price_scope ?: 'llanta');
                        $price = (float) $pricing->priceFor($p, $scope, $q);
                        $stock = (int) ($p->stock_hermosillo ?? 0);
                        if ($price <= 0) {
                            Log::warning("MELI SYNC: [SYSCOM] precio 0, omite -> {$q->mlm}");

                            continue;
                        }
                        $sku = app(SyscomMeliPublishService::class)->makeSku((int) $p->syscom_producto_id);
                        if (strtolower((string) ($q->price_mode ?? 'auto')) === 'manual') {
                            Log::debug("MELI SYNC: [SYSCOM] precio MANUAL \${$price} -> {$q->mlm}");
                        }

                        $mlm = (string) $q->mlm;
                        $row = [
                            'sku' => $sku,
                            'mlm' => $mlm,
                            'stock' => $stock,
                            'price' => $price,
                        ];

                        $pub = $pubsByMlm->get($mlm);
                        $mlStatus = mb_strtolower((string) ($pub->status ?? ''));

                        if ($pauseAtOrBelow >= 0 && $stock <= $pauseAtOrBelow) {
                            // Sin stock en Hermosillo: pausar en ML (no surtir de otra sucursal).
                            $row['status'] = 'paused';
                            Log::info("MELI SYNC: [SYSCOM] stock={$stock} (Hermosillo) -> pausada en ML ({$mlm})");
                        } elseif ($mlStatus === 'paused' && $stock > $pauseAtOrBelow) {
                            // Volvió a haber stock: reactivar la publicación.
                            $row['status'] = 'active';
                            Log::info("MELI SYNC: [SYSCOM] stock={$stock} (Hermosillo) -> reactivada en ML ({$mlm})");
                        }

                        $rows[] = $row;
                    } catch (\Throwable $e) {
                        Log::error("MELI SYNC: [SYSCOM] error armando fila {$q->mlm}: ".$e->getMessage());
                    }
                }

                $this->applyItemUpdatesConcurrent($rows, $pubsByMlm);
            });
        }
    }

    protected function runWithMeliUser(User $user, \Closure $fn): void
    {
        $backup = [
            'user' => $this->user,
            'meliId' => $this->meliId,
            'accessToken' => $this->accessToken,
        ];
        $this->user = $user;
        $this->meliId = (int) $user->meli_id;
        $this->accessToken = (string) $user->access_token;
        $this->buildClient();
        $this->ensureFreshToken();
        try {
            $fn();
        } finally {
            $this->user = $backup['user'];
            $this->meliId = $backup['meliId'];
            $this->accessToken = $backup['accessToken'];
            $this->buildClient();
        }
    }

    /**
     * Busca TODOS los items por seller_sku
     * ✅ Si recibe 401, refresca token y reintenta 1 vez.
     */
    protected function findItemsBySku(string $sku): array
    {
        $url = "https://api.mercadolibre.com/users/{$this->meliId}/items/search?seller_sku={$sku}";

        $response = $this->client->get($url);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 401) {
            Log::warning("MELI SYNC: findItemsBySku HTTP 401 | SKU {$sku} | body={$body}");
            $this->refreshTokenNow();
            $response = $this->client->get($url);

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        }

        if ($status !== 200) {
            Log::warning("MELI SYNC: findItemsBySku HTTP {$status} | SKU {$sku} | body={$body}");
            return [];
        }

        $data = json_decode($body, true);
        $results = $data['results'] ?? [];

        return array_values(array_filter($results, fn($x) => is_string($x) && $x !== ''));
    }

    /**
     * Trae detalle del item para saber status/sub_status/permalink
     * ✅ Si recibe 401, refresca token y reintenta 1 vez.
     */
    protected function getItemDetail(string $itemId): ?array
    {
        $url = "https://api.mercadolibre.com/items/{$itemId}";

        $response = $this->client->get($url);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 401) {
            Log::warning("MELI SYNC: getItemDetail HTTP 401 | item {$itemId} | body={$body}");
            $this->refreshTokenNow();
            $response = $this->client->get($url);

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
        }

        if ($status !== 200) {
            Log::warning("MELI SYNC: getItemDetail HTTP {$status} | item {$itemId} | body={$body}");
            return null;
        }

        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Normaliza sub_status del JSON de ML para guardar en meli_publications.
     */
    protected function normalizeSubStatusForPublication(array $item): ?array
    {
        $subStatus = $item['sub_status'] ?? null;
        if (is_string($subStatus) && $subStatus !== '') {
            return [$subStatus];
        }

        return is_array($subStatus) ? $subStatus : null;
    }

    protected function upsertPublicationFromItemPayload(string $sku, string $mlm, array $item): void
    {
        MeliPublication::updateOrCreate(
            [
                'user_id' => $this->user->id,
                'mlm'     => $mlm,
            ],
            [
                'sku'          => $sku,
                'status'       => $item['status'] ?? null,
                'sub_status'   => $this->normalizeSubStatusForPublication($item),
                'permalink'    => $item['permalink'] ?? null,
                'last_sync_at' => now(),
                'raw'          => $item,
            ]
        );
    }

    /**
     * Varios PUT /items/{id} en paralelo y persiste desde la respuesta (sin GET previo).
     *
     * @param array<int, array{sku: string, mlm: string, stock: int, price: float, status?: ?string}> $rows
     * @param Collection<string, MeliPublication>|null $pubScopeByMlm colección ya cargada (evita segundo SELECT en llantas)
     */
    protected function applyItemUpdatesConcurrent(array $rows, ?Collection $pubScopeByMlm = null): void
    {
        $rows = array_values($rows);
        if ($rows === []) {
            return;
        }

        $shouldPutRows = [];
        $skippedMlms = [];

        if ($this->noopSkipPutEnabled()) {
            $pubMap = $pubScopeByMlm;
            if ($pubMap === null) {
                $mlms = array_values(array_unique(array_map(static fn (array $r) => $r['mlm'], $rows)));
                $pubMap = MeliPublication::query()
                    ->where('user_id', $this->user->id)
                    ->whereIn('mlm', $mlms)
                    ->get()
                    ->keyBy('mlm');
            }

            foreach ($rows as $row) {
                $pub = $pubMap->get($row['mlm']);
                if ($this->rowMatchesPublication($row, $pub)) {
                    $skippedMlms[] = $row['mlm'];

                    continue;
                }
                $shouldPutRows[] = $row;
            }

            if ($skippedMlms !== []) {
                $uniqueSkipped = array_values(array_unique($skippedMlms));
                MeliPublication::query()
                    ->where('user_id', $this->user->id)
                    ->whereIn('mlm', $uniqueSkipped)
                    ->update(['last_sync_at' => now()]);
                Log::debug('MELI SYNC: PUT omitidos (sin cambios vs raw) '.count($uniqueSkipped).' ítems');
            }
        } else {
            $shouldPutRows = $rows;
        }

        $rows = array_values($shouldPutRows);
        if ($rows === []) {
            return;
        }

        $concurrency = $this->syncConcurrency();
        $indexes401 = [];

        $requests = function () use ($rows) {
            foreach ($rows as $row) {
                yield function () use ($row) {
                    $body = [
                        'available_quantity' => $row['stock'],
                        'price' => $row['price'],
                    ];
                    if (isset($row['status']) && $row['status'] !== null && $row['status'] !== '') {
                        $body['status'] = $row['status'];
                    }

                    return $this->client->putAsync(
                        'https://api.mercadolibre.com/items/' . $row['mlm'],
                        ['json' => $body]
                    );
                };
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $index) use ($rows, &$indexes401) {
                $row = $rows[$index];
                $mlm = $row['mlm'];
                $http = $response->getStatusCode();
                $respBody = (string) $response->getBody();

                if ($http === 401) {
                    $indexes401[] = $index;
                    Log::warning("MELI SYNC: updateItem (pool) HTTP 401 | item {$mlm} | body={$respBody}");

                    return;
                }

                if ($http !== 200) {
                    Log::error("MELI SYNC: Error actualizando item {$mlm} (HTTP {$http}) | body={$respBody}");

                    return;
                }

                $data = json_decode($respBody, true);
                if (is_array($data)) {
                    $this->upsertPublicationFromItemPayload($row['sku'], $mlm, $data);
                }
            },
            'rejected' => function ($reason, $index) use ($rows) {
                $mlm = $rows[$index]['mlm'] ?? '?';
                $msg = $reason instanceof TransferException ? $reason->getMessage() : (string) $reason;
                Log::error("MELI SYNC: fallo pool PUT item {$mlm}: {$msg}");
            },
        ]);

        $pool->promise()->wait();

        if ($indexes401 !== []) {
            $this->refreshTokenNow();
            foreach ($indexes401 as $index) {
                $row = $rows[$index];
                $wantStatus = (isset($row['status']) && $row['status'] !== '')
                    ? (string) $row['status']
                    : null;
                $payload = $this->updateItem(
                    $row['mlm'],
                    (int) $row['stock'],
                    (float) $row['price'],
                    $wantStatus,
                );
                if (is_array($payload)) {
                    $this->upsertPublicationFromItemPayload($row['sku'], $row['mlm'], $payload);
                }
            }
        }
    }

    /**
     * Actualiza stock y precio; opcionalmente status ML. Devuelve el JSON del ítem cuando HTTP 200.
     * ✅ Si recibe 401, refresca token y reintenta 1 vez.
     */
    protected function updateItem(string $itemId, int $stock, float $price, ?string $status = null): ?array
    {
        $url = "https://api.mercadolibre.com/items/{$itemId}";

        $body = [
            'available_quantity' => $stock,
            'price' => $price,
        ];
        if ($status !== null && $status !== '') {
            $body['status'] = $status;
        }

        $response = $this->client->put($url, ['json' => $body]);
        $http = $response->getStatusCode();
        $respBody = (string) $response->getBody();

        if ($http === 401) {
            Log::warning("MELI SYNC: updateItem HTTP 401 | item {$itemId} | body={$respBody}");
            $this->refreshTokenNow();

            $response = $this->client->put($url, ['json' => $body]);
            $http = $response->getStatusCode();
            $respBody = (string) $response->getBody();
        }

        if ($http !== 200) {
            Log::error("MELI SYNC: Error actualizando item {$itemId} (HTTP {$http}) | body={$respBody}");
            return null;
        }

        $data = json_decode($respBody, true);
        return is_array($data) ? $data : null;
    }

    /**
     * ✅ Si expira pronto, refresca antes de empezar
     */
    protected function ensureFreshToken(): void
    {
        if (!$this->user->expires_at) return;

        if ($this->user->expires_at->lte(now()->addMinutes(10))) {
            Log::info("MELI SYNC: Token por expirar ({$this->user->expires_at}), refrescando...");
            $this->refreshTokenNow();
        }
    }

    /**
     * ✅ Refresca token en caliente y reconstruye client
     */
    protected function refreshTokenNow(): void
    {
        $clientId = (string) config('services.meli.client_id', '');
        $clientSecret = (string) config('services.meli.client_secret', '');

        if ($clientId === '' || $clientSecret === '' || !$this->user->refresh_token) {
            Log::error('MELI SYNC: No se puede refrescar token (faltan credenciales o refresh_token)');
            return;
        }

        try {
            $data = $this->meliOAuth->refreshAccessToken(
                $clientId,
                $clientSecret,
                (string) $this->user->refresh_token,
            );
        } catch (\Throwable $e) {
            Log::error('MELI SYNC: refreshTokenNow falló | '.$e->getMessage());
            return;
        }

        $this->user->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $this->user->refresh_token,
            'expires_at'    => Carbon::now()->addSeconds((int)($data['expires_in'] ?? 0))->subMinutes(2),
        ]);

        $this->accessToken = $this->user->access_token;
        $this->buildClient();

        Log::info("MELI SYNC: Token refrescado OK | expira {$this->user->expires_at}");
    }
}
