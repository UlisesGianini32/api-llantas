<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliFullStock;
use App\Models\MeliPublication;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MeliFullStockService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    /**
     * Mercado Libre documenta un máximo de 20 ítems por llamada multiget.
     */
    private const ITEM_MULTIGET_SIZE = 20;

    /**
     * /user-products/{id}/stock está limitado a 100 solicitudes por minuto.
     * 650 ms deja margen sin frenar las consultas clásicas por inventory_id.
     */
    private const USER_PRODUCT_MIN_INTERVAL_SECONDS = 0.65;

    /** @var array<string, array<string, mixed>|null> */
    private array $stockCache = [];

    /**
     * Evita repetir exactamente la misma fila MLM/variante durante una corrida.
     * No deduplica inventory_id ni user_product_id compartidos.
     *
     * @var array<string, true>
     */
    private array $savedStockKeys = [];

    /** @var array<string, true> */
    private array $knownFullUserProductIds = [];

    private float $lastUserProductRequestAt = 0.0;

    public function __construct(
        private readonly MeliOAuthService $oauth,
    ) {
    }

    /**
     * Descubre directamente las publicaciones cuyo logistic_type es
     * fulfillment y guarda cada publicación y cada variante, aunque varias
     * compartan el mismo inventario físico FULL.
     *
     * Esto evita recorrer las publicaciones cross_docking, Flex, custom o sin
     * logística FULL. Por defecto usa modo rápido:
     *
     * - Consulta detalles de publicaciones en lotes de 20 mediante multiget.
     * - Consulta stock por inventory_id sin la pausa de User Products.
     * - Consulta /user-products/{id}/stock únicamente cuando el ítem muestra
     *   indicadores de FULL o ya fue reconocido previamente como FULL.
     *
     * Con $deep=true también revisa todos los user_product_id. Ese modo es más
     * completo para coexistencia FULL/Flex, pero puede tardar por el límite de
     * 100 solicitudes por minuto del endpoint de User Products.
     *
     * @param  callable(string):void|null  $progress
     * @return array<string, int>
     */
    public function syncAccount(
        MeliAccount $account,
        ?string $onlyMlm = null,
        ?callable $progress = null,
        bool $deep = false,
    ): array {
        $this->ensureFreshAccessToken($account);
        $syncStartedAt = now();
        $this->stockCache = [];
        $this->savedStockKeys = [];
        $this->lastUserProductRequestAt = 0.0;
        $this->knownFullUserProductIds = $this->loadKnownFullUserProductIds($account);

        $onlyMlm = $onlyMlm !== null ? strtoupper(trim($onlyMlm)) : null;

        $stats = [
            'remote_items_found' => 0,
            'item_batches' => 0,
            'publications_scanned' => 0,
            'publications_with_full' => 0,
            'stock_candidates_checked' => 0,
            'user_products_skipped_fast' => 0,
            'full_rows_saved' => 0,
            'full_rows_removed' => 0,
            'errors' => 0,
        ];

        if ($onlyMlm !== null && $onlyMlm !== '') {
            $itemIds = [$onlyMlm];
        } else {
            if ($progress !== null) {
                $progress('Buscando únicamente publicaciones con logistic_type=fulfillment...');
            }

            $itemIds = $this->sellerItemIds($account, $progress);
        }

        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => strtoupper(trim((string) $id)),
            $itemIds,
        ))));

        $stats['remote_items_found'] = count($itemIds);

        if ($itemIds === []) {
            if ($progress !== null) {
                $progress('Mercado Libre no devolvió publicaciones para esta cuenta.');
            }

            return $stats;
        }

        if ($progress !== null) {
            $progress('Publicaciones FULL encontradas en Mercado Libre: '.count($itemIds));
            $progress($deep
                ? 'Modo profundo: se revisarán todos los User Products; puede tardar por el límite de 100 RPM.'
                : 'Modo rápido: detalles en lotes de 20 y stock solo para candidatos FULL.');
        }

        $localPublications = $this->localPublicationsByMlm($account, $itemIds);
        $allSeenStockKeys = [];
        $chunks = array_chunk($itemIds, self::ITEM_MULTIGET_SIZE);
        $totalBatches = count($chunks);

        foreach ($chunks as $batchIndex => $ids) {
            $stats['item_batches']++;

            try {
                $batch = $this->itemDetailsBatch($account, $ids);
            } catch (Throwable $exception) {
                $stats['errors'] += count($ids);

                foreach ($ids as $mlm) {
                    $this->markPublicationError($account, $mlm, $exception->getMessage());
                }

                if ($progress !== null) {
                    $progress(sprintf(
                        'ERROR lote %d/%d: %s',
                        $batchIndex + 1,
                        $totalBatches,
                        $exception->getMessage(),
                    ));
                }

                continue;
            }

            foreach ($ids as $mlm) {
                $stats['publications_scanned']++;
                $item = $batch['items'][$mlm] ?? null;

                if (! is_array($item)) {
                    $stats['errors']++;
                    $message = $batch['errors'][$mlm] ?? 'Mercado Libre no devolvió el detalle de la publicación.';
                    $this->markPublicationError($account, $mlm, $message);
                    continue;
                }

                try {
                    $result = $this->syncItem(
                        $account,
                        $item,
                        $localPublications[$mlm] ?? null,
                        $deep,
                    );

                    if ($result['has_full']) {
                        $stats['publications_with_full']++;
                    }

                    foreach ($result['seen_keys'] as $stockKey) {
                        $allSeenStockKeys[$stockKey] = true;
                    }

                    $stats['stock_candidates_checked'] += $result['checked'];
                    $stats['user_products_skipped_fast'] += $result['skipped_fast'];
                    $stats['full_rows_saved'] += $result['saved'];
                    $stats['errors'] += $result['errors'];
                } catch (Throwable $exception) {
                    $stats['errors']++;
                    $this->markPublicationError($account, $mlm, $exception->getMessage());

                    Log::warning('MELI FULL: publicación no sincronizada', [
                        'meli_account_id' => $account->id,
                        'mlm' => $mlm,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($progress !== null) {
                $progress(sprintf(
                    'Lote %d/%d | Revisadas: %d/%d | Con FULL: %d | Filas: %d | Errores: %d',
                    $batchIndex + 1,
                    $totalBatches,
                    $stats['publications_scanned'],
                    count($itemIds),
                    $stats['publications_with_full'],
                    $stats['full_rows_saved'],
                    $stats['errors'],
                ));
            }
        }

        /*
         * Como la búsqueda remota ya viene filtrada por logistic_type=fulfillment,
         * una ejecución completa y sin errores representa el conjunto FULL actual.
         * Eliminamos únicamente filas que no fueron actualizadas durante esta corrida.
         * Si hubo cualquier error, no se elimina nada para evitar pérdidas por una
         * respuesta parcial de Mercado Libre.
         */
        if ($stats['errors'] === 0) {
            $staleQuery = MeliFullStock::query()
                ->where('user_id', $account->user_id)
                ->where('meli_account_id', $account->id)
                ->where(function ($query) use ($syncStartedAt) {
                    $query->whereNull('synced_at')
                        ->orWhere('synced_at', '<', $syncStartedAt);
                });

            if ($onlyMlm !== null && $onlyMlm !== '') {
                $staleQuery->where('mlm', $onlyMlm);
            }

            $stats['full_rows_removed'] = $staleQuery->delete();
        }

        return $stats;
    }

    /**
     * Obtiene solamente los ítems FULL mediante el filtro oficial disponible
     * en la búsqueda privada del vendedor: logistic_type=fulfillment.
     * Usa search_type=scan para seguir funcionando si la cuenta supera 1,000 FULL.
     *
     * @param  callable(string):void|null  $progress
     * @return array<int, string>
     */
    private function sellerItemIds(MeliAccount $account, ?callable $progress = null): array
    {
        $sellerId = trim((string) $account->meli_user_id);

        if ($sellerId === '') {
            throw new RuntimeException('La cuenta no tiene meli_user_id configurado.');
        }

        $baseQuery = [
            'logistic_type' => 'fulfillment',
            'limit' => 100,
        ];

        /*
         * Primero hacemos una búsqueda normal para conocer el total exacto del
         * filtro. Mientras sea menor o igual a 1,000, la paginación por offset
         * es más simple y estable. Si en el futuro supera 1,000, usamos scan.
         */
        $firstResponse = $this->get(
            $account,
            '/users/'.rawurlencode($sellerId).'/items/search',
            array_merge($baseQuery, ['offset' => 0]),
        );

        if (! $firstResponse->successful()) {
            throw new RuntimeException($this->responseMessage(
                $firstResponse,
                'No se pudo obtener la lista de publicaciones FULL.',
            ));
        }

        $firstData = $firstResponse->json();

        if (! is_array($firstData)) {
            throw new RuntimeException('Mercado Libre devolvió una búsqueda FULL sin JSON válido.');
        }

        $total = max(0, (int) data_get($firstData, 'paging.total', 0));

        if ($progress !== null) {
            $progress('El filtro logistic_type=fulfillment reporta '.$total.' publicaciones.');
        }

        if ($total <= 1000) {
            $itemIds = [];
            $appendResults = static function (array $data) use (&$itemIds): void {
                $results = is_array($data['results'] ?? null) ? $data['results'] : [];

                foreach ($results as $itemId) {
                    $itemId = strtoupper(trim((string) $itemId));

                    if ($itemId !== '') {
                        $itemIds[$itemId] = true;
                    }
                }
            };

            $appendResults($firstData);

            for ($offset = 100; $offset < $total; $offset += 100) {
                $response = $this->get(
                    $account,
                    '/users/'.rawurlencode($sellerId).'/items/search',
                    array_merge($baseQuery, ['offset' => $offset]),
                );

                if (! $response->successful()) {
                    throw new RuntimeException($this->responseMessage(
                        $response,
                        'No se pudo continuar la paginación de publicaciones FULL.',
                    ));
                }

                $data = $response->json();

                if (! is_array($data)) {
                    throw new RuntimeException('Mercado Libre devolvió una página FULL sin JSON válido.');
                }

                $appendResults($data);

                if ($progress !== null) {
                    $progress('Descubiertas '.count($itemIds).' de '.$total.' publicaciones FULL...');
                }
            }

            return array_keys($itemIds);
        }

        $itemIds = [];
        $scrollId = null;
        $page = 0;

        do {
            $page++;

            $query = [
                'search_type' => 'scan',
                'logistic_type' => 'fulfillment',
                'limit' => 100,
            ];

            if ($scrollId !== null && $scrollId !== '') {
                $query['scroll_id'] = $scrollId;
            }

            $response = $this->get(
                $account,
                '/users/'.rawurlencode($sellerId).'/items/search',
                $query,
            );

            if (! $response->successful()) {
                throw new RuntimeException($this->responseMessage(
                    $response,
                    'No se pudo obtener la lista completa de publicaciones FULL.',
                ));
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException('Mercado Libre devolvió un scan FULL sin JSON válido.');
            }

            $results = is_array($data['results'] ?? null) ? $data['results'] : [];

            foreach ($results as $itemId) {
                $itemId = strtoupper(trim((string) $itemId));

                if ($itemId !== '') {
                    $itemIds[$itemId] = true;
                }
            }

            if ($progress !== null && ($page === 1 || $page % 10 === 0 || $results === [])) {
                $progress('Descubiertas '.count($itemIds).' publicaciones FULL...');
            }

            $responseScrollId = trim((string) ($data['scroll_id'] ?? ''));

            if ($responseScrollId !== '') {
                $scrollId = $responseScrollId;
            }

            if ($results === [] || $scrollId === null || $scrollId === '') {
                break;
            }

            if ($page >= 10000) {
                throw new RuntimeException('La búsqueda FULL scan excedió el límite de seguridad de páginas.');
            }
        } while (true);

        return array_keys($itemIds);
    }

    /**
     * Consulta hasta 20 publicaciones en una sola llamada.
     *
     * @param  array<int, string>  $itemIds
     * @return array{items: array<string, array<string, mixed>>, errors: array<string, string>}
     */
    private function itemDetailsBatch(MeliAccount $account, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => strtoupper(trim((string) $id)),
            $itemIds,
        ))));

        if ($itemIds === []) {
            return ['items' => [], 'errors' => []];
        }

        $response = $this->get($account, '/items', [
            'ids' => implode(',', $itemIds),
            'attributes' => implode(',', [
                'id',
                'seller_id',
                'title',
                'permalink',
                'thumbnail',
                'pictures',
                'shipping',
                'status',
                'sub_status',
                'tags',
                'available_quantity',
                'inventory_id',
                'user_product_id',
                'variations',
                'seller_custom_field',
                'attributes',
            ]),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->responseMessage(
                $response,
                'No se pudo consultar el lote de publicaciones.',
            ));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Mercado Libre devolvió un multiget sin JSON válido.');
        }

        $items = [];
        $errors = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = (int) ($entry['code'] ?? 0);
            $body = is_array($entry['body'] ?? null) ? $entry['body'] : [];
            $mlm = strtoupper(trim((string) ($body['id'] ?? '')));

            if ($mlm === '') {
                $requestedId = trim((string) ($entry['id'] ?? ''));
                $mlm = strtoupper($requestedId);
            }

            if ($code >= 200 && $code < 300 && $body !== [] && $mlm !== '') {
                $items[$mlm] = $body;
                continue;
            }

            if ($mlm !== '') {
                $message = trim((string) ($body['message'] ?? data_get($body, 'cause.0.message', '')));
                $errors[$mlm] = 'HTTP '.$code.': '.($message !== '' ? $message : 'No se pudo consultar la publicación.');
            }
        }

        /*
         * Algunas respuestas de error no incluyen id. Completamos los faltantes
         * para que el contador y la auditoría sean consistentes.
         */
        foreach ($itemIds as $mlm) {
            if (! isset($items[$mlm]) && ! isset($errors[$mlm])) {
                $errors[$mlm] = 'Mercado Libre no devolvió la publicación dentro del multiget.';
            }
        }

        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{has_full: bool, saved: int, errors: int, checked: int, skipped_fast: int, seen_keys: array<int, string>}
     */
    private function syncItem(
        MeliAccount $account,
        array $item,
        ?MeliPublication $publication,
        bool $deep,
    ): array {
        $mlm = strtoupper(trim((string) ($item['id'] ?? $publication?->mlm ?? '')));

        if ($mlm === '') {
            throw new RuntimeException('La publicación no contiene un MLM válido.');
        }

        $sellerId = trim((string) ($item['seller_id'] ?? ''));

        if ($sellerId !== '' && $sellerId !== trim((string) $account->meli_user_id)) {
            throw new RuntimeException('La publicación no pertenece a la cuenta seleccionada.');
        }

        $publicationStatus = strtolower(trim((string) ($item['status'] ?? '')));
        $publicationSubStatus = $this->normalizeStringList($item['sub_status'] ?? []);
        $publicationTags = $this->normalizeStringList($item['tags'] ?? []);

        $candidates = $this->stockCandidates($item, $publication);
        $seenKeys = [];
        $saved = 0;
        $errors = 0;
        $checked = 0;
        $skippedFast = 0;
        $hasFull = false;

        foreach ($candidates as $candidate) {
            if (! $this->shouldCheckCandidate($candidate, $deep)) {
                $skippedFast++;
                continue;
            }

            $checked++;

            try {
                $stock = $this->fetchFullStock($account, $candidate);

                if ($stock === null) {
                    continue;
                }

                $hasFull = true;
                $stockKey = (string) $candidate['stock_key'];
                $seenKeys[$stockKey] = true;

                if (! isset($this->savedStockKeys[$stockKey])) {
                    MeliFullStock::query()->updateOrCreate(
                        [
                            'meli_account_id' => $account->id,
                            'stock_key' => $stockKey,
                        ],
                        [
                            'user_id' => $account->user_id,
                            'meli_publication_id' => $publication?->id,
                            'mlm' => $mlm,
                            'variation_id' => $candidate['variation_id'],
                            'sku' => $candidate['sku'],
                            'title' => $candidate['title'],
                            'variation_label' => $candidate['variation_label'],
                            'thumbnail' => $candidate['thumbnail'],
                            'permalink' => $candidate['permalink'],
                            'publication_status' => $publicationStatus !== '' ? $publicationStatus : null,
                            'publication_sub_status' => $publicationSubStatus,
                            'publication_tags' => $publicationTags,
                            'inventory_id' => $candidate['inventory_id'],
                            'user_product_id' => $candidate['user_product_id'],
                            'stock_source' => $stock['source'],
                            'full_available_quantity' => $stock['available'],
                            'full_not_available_quantity' => $stock['not_available'],
                            'full_total_quantity' => $stock['total'],
                            'not_available_detail' => $stock['detail'],
                            'raw_stock' => $stock['raw'],
                            'last_error' => null,
                            'synced_at' => now(),
                        ],
                    );

                    $this->savedStockKeys[$stockKey] = true;
                    $saved++;
                }
            } catch (Throwable $exception) {
                $errors++;

                MeliFullStock::query()
                    ->where('meli_account_id', $account->id)
                    ->where('stock_key', $candidate['stock_key'])
                    ->update(['last_error' => $exception->getMessage()]);

                Log::warning('MELI FULL: inventario no sincronizado', [
                    'meli_account_id' => $account->id,
                    'mlm' => $mlm,
                    'variation_id' => $candidate['variation_id'],
                    'inventory_id' => $candidate['inventory_id'],
                    'user_product_id' => $candidate['user_product_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'has_full' => $hasFull,
            'saved' => $saved,
            'errors' => $errors,
            'checked' => $checked,
            'skipped_fast' => $skippedFast,
            'seen_keys' => array_keys($seenKeys),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function shouldCheckCandidate(array $candidate, bool $deep): bool
    {
        $inventoryId = trim((string) ($candidate['inventory_id'] ?? ''));

        if ($inventoryId !== '') {
            return true;
        }

        $userProductId = strtoupper(trim((string) ($candidate['user_product_id'] ?? '')));

        if ($userProductId === '') {
            return false;
        }

        if ($deep || (bool) ($candidate['likely_full'] ?? false)) {
            return true;
        }

        return isset($this->knownFullUserProductIds[$userProductId]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{source:string, available:int, not_available:?int, total:?int, detail:array, raw:array}|null
     */
    private function fetchFullStock(MeliAccount $account, array $candidate): ?array
    {
        $inventoryId = trim((string) ($candidate['inventory_id'] ?? ''));
        $userProductId = trim((string) ($candidate['user_product_id'] ?? ''));
        $cacheKey = $inventoryId !== ''
            ? 'inventory:'.strtoupper($inventoryId)
            : ($userProductId !== '' ? 'user_product:'.strtoupper($userProductId) : '');

        if ($cacheKey !== '' && array_key_exists($cacheKey, $this->stockCache)) {
            return $this->stockCache[$cacheKey];
        }

        $inventoryFailure = null;

        if ($inventoryId !== '') {
            /*
             * Este endpoint no comparte el límite documentado de 100 RPM de
             * User Products, por eso no aplicamos la pausa artificial aquí.
             */
            $response = $this->get(
                $account,
                '/inventories/'.rawurlencode($inventoryId).'/stock/fulfillment',
            );

            if ($response->successful()) {
                $data = $response->json();
                $data = is_array($data) ? $data : [];

                $result = [
                    'source' => 'inventory',
                    'available' => max(0, (int) ($data['available_quantity'] ?? 0)),
                    'not_available' => isset($data['not_available_quantity'])
                        ? max(0, (int) $data['not_available_quantity'])
                        : null,
                    'total' => isset($data['total']) ? max(0, (int) $data['total']) : null,
                    'detail' => is_array($data['not_available_detail'] ?? null)
                        ? $data['not_available_detail']
                        : [],
                    'raw' => $data,
                ];

                $this->stockCache[$cacheKey] = $result;

                return $result;
            }

            if (! in_array($response->status(), [400, 404], true)) {
                $inventoryFailure = $this->responseMessage(
                    $response,
                    "No se pudo consultar el inventory_id {$inventoryId}.",
                );
            }
        }

        if ($userProductId !== '') {
            $this->throttleUserProductRequests();

            $response = $this->get(
                $account,
                '/user-products/'.rawurlencode($userProductId).'/stock',
            );

            if ($response->successful()) {
                $data = $response->json();
                $data = is_array($data) ? $data : [];
                $locations = is_array($data['locations'] ?? null) ? $data['locations'] : [];

                $fullLocations = array_values(array_filter(
                    $locations,
                    static fn ($location): bool => is_array($location)
                        && ($location['type'] ?? null) === 'meli_facility',
                ));

                if ($fullLocations === []) {
                    $this->stockCache[$cacheKey] = null;

                    return null;
                }

                $available = array_reduce(
                    $fullLocations,
                    static fn (int $total, array $location): int => $total
                        + max(0, (int) ($location['quantity'] ?? 0)),
                    0,
                );

                $result = [
                    'source' => 'user_product',
                    'available' => $available,
                    'not_available' => null,
                    'total' => $available,
                    'detail' => [],
                    'raw' => $data,
                ];

                $this->stockCache[$cacheKey] = $result;

                return $result;
            }

            if (in_array($response->status(), [400, 404], true)) {
                $this->stockCache[$cacheKey] = null;

                return null;
            }

            throw new RuntimeException($this->responseMessage(
                $response,
                "No se pudo consultar el user_product_id {$userProductId}.",
            ));
        }

        if ($inventoryFailure !== null) {
            throw new RuntimeException($inventoryFailure);
        }

        if ($cacheKey !== '') {
            $this->stockCache[$cacheKey] = null;
        }

        return null;
    }

    private function throttleUserProductRequests(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastUserProductRequestAt;
        $remaining = self::USER_PRODUCT_MIN_INTERVAL_SECONDS - $elapsed;

        if ($this->lastUserProductRequestAt > 0 && $remaining > 0) {
            usleep((int) ceil($remaining * 1_000_000));
        }

        $this->lastUserProductRequestAt = microtime(true);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    private function stockCandidates(array $item, ?MeliPublication $publication): array
    {
        $mlm = strtoupper((string) ($item['id'] ?? $publication?->mlm ?? ''));
        $title = trim((string) ($item['title'] ?? 'Publicación sin título'));
        $permalink = (string) ($item['permalink'] ?? $publication?->permalink ?? '');
        $pictures = is_array($item['pictures'] ?? null) ? $item['pictures'] : [];
        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        $likelyFull = $this->itemLikelyUsesFull($item);

        if ($variations === []) {
            $inventoryId = $item['inventory_id'] ?? null;
            $userProductId = $item['user_product_id'] ?? null;

            return [[
                'stock_key' => $this->stockKey($mlm, null, $inventoryId, $userProductId),
                'variation_id' => null,
                'sku' => $this->sellerSku($item) ?: (string) ($publication?->sku ?? ''),
                'title' => $title,
                'variation_label' => null,
                'thumbnail' => $this->pictureUrl($pictures, null, (string) ($item['thumbnail'] ?? '')),
                'permalink' => $permalink,
                'inventory_id' => $inventoryId,
                'user_product_id' => $userProductId,
                'likely_full' => $likelyFull || filled($inventoryId),
            ]];
        }

        $candidates = [];

        foreach ($variations as $variation) {
            if (! is_array($variation) || ! isset($variation['id'])) {
                continue;
            }

            $variationId = (string) $variation['id'];
            $pictureId = is_array($variation['picture_ids'] ?? null)
                ? (string) ($variation['picture_ids'][0] ?? '')
                : '';
            $inventoryId = $variation['inventory_id'] ?? null;
            $userProductId = $variation['user_product_id'] ?? null;

            $candidates[] = [
                'stock_key' => $this->stockKey($mlm, $variationId, $inventoryId, $userProductId),
                'variation_id' => $variationId,
                'sku' => $this->sellerSku($variation) ?: (string) ($publication?->sku ?? ''),
                'title' => $title,
                'variation_label' => $this->variationLabel($variation),
                'thumbnail' => $this->pictureUrl($pictures, $pictureId, (string) ($item['thumbnail'] ?? '')),
                'permalink' => $permalink,
                'inventory_id' => $inventoryId,
                'user_product_id' => $userProductId,
                'likely_full' => $likelyFull || filled($inventoryId),
            ];
        }

        return $candidates;
    }

    /**
     * La identidad de la fila pertenece a la publicación y a su variante.
     *
     * Dos MLM distintos pueden compartir el mismo inventory_id o
     * user_product_id porque Mercado Libre los conecta al mismo inventario
     * físico. Aun así, ambos deben aparecer en la pantalla. El stock remoto se
     * sigue consultando una sola vez mediante stockCache, pero se guarda una
     * fila independiente por MLM/variante.
     */
    private function stockKey(
        string $mlm,
        ?string $variationId,
        mixed $inventoryId,
        mixed $userProductId,
    ): string {
        $mlm = strtoupper(trim($mlm));
        $variationId = trim((string) $variationId);

        return $variationId !== ''
            ? $mlm.':variation:'.$variationId
            : $mlm.':main';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemLikelyUsesFull(array $item): bool
    {
        $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
        $logisticType = strtolower(trim((string) ($shipping['logistic_type'] ?? '')));
        $tags = is_array($shipping['tags'] ?? null) ? $shipping['tags'] : [];

        if ($logisticType === 'fulfillment') {
            return true;
        }

        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));

            if (str_contains($tag, 'fulfillment') || str_contains($tag, 'fbm')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function loadKnownFullUserProductIds(MeliAccount $account): array
    {
        return MeliFullStock::query()
            ->where('user_id', $account->user_id)
            ->where('meli_account_id', $account->id)
            ->whereNotNull('user_product_id')
            ->pluck('user_product_id')
            ->map(static fn ($id): string => strtoupper(trim((string) $id)))
            ->filter()
            ->mapWithKeys(static fn (string $id): array => [$id => true])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($entry): string => strtolower(trim((string) $entry)),
            $value,
        ))));
    }

    private function markPublicationError(MeliAccount $account, string $mlm, string $message): void
    {
        MeliFullStock::query()
            ->where('user_id', $account->user_id)
            ->where('meli_account_id', $account->id)
            ->where('mlm', strtoupper(trim($mlm)))
            ->update(['last_error' => $message]);

        Log::warning('MELI FULL: publicación no sincronizada', [
            'meli_account_id' => $account->id,
            'mlm' => strtoupper(trim($mlm)),
            'error' => $message,
        ]);
    }

    /**
     * @return array<string, MeliPublication>
     */
    private function localPublicationsByMlm(MeliAccount $account, array $itemIds): array
    {
        $map = [];

        foreach (array_chunk($itemIds, 500) as $chunk) {
            $rows = MeliPublication::query()
                ->where('user_id', $account->user_id)
                ->where('meli_account_id', $account->id)
                ->whereIn('mlm', $chunk)
                ->orderByDesc('is_current')
                ->orderByDesc('id')
                ->get();

            foreach ($rows as $publication) {
                $mlm = strtoupper(trim((string) $publication->mlm));

                if ($mlm !== '' && ! isset($map[$mlm])) {
                    $map[$mlm] = $publication;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sellerSku(array $data): string
    {
        $direct = trim((string) ($data['seller_custom_field'] ?? ''));

        if ($direct !== '') {
            return $direct;
        }

        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || strtoupper((string) ($attribute['id'] ?? '')) !== 'SELLER_SKU') {
                continue;
            }

            return trim((string) ($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $variation
     */
    private function variationLabel(array $variation): ?string
    {
        $combinations = is_array($variation['attribute_combinations'] ?? null)
            ? $variation['attribute_combinations']
            : [];

        $labels = [];

        foreach ($combinations as $combination) {
            if (! is_array($combination)) {
                continue;
            }

            $name = trim((string) ($combination['name'] ?? $combination['id'] ?? ''));
            $value = trim((string) ($combination['value_name'] ?? $combination['value_id'] ?? ''));

            if ($name !== '' && $value !== '') {
                $labels[] = $name.': '.$value;
            } elseif ($value !== '') {
                $labels[] = $value;
            }
        }

        return $labels !== [] ? implode(' · ', $labels) : null;
    }

    /**
     * @param  array<int, mixed>  $pictures
     */
    private function pictureUrl(array $pictures, ?string $preferredId, string $fallback): ?string
    {
        foreach ($pictures as $picture) {
            if (! is_array($picture)) {
                continue;
            }

            if ($preferredId !== null && $preferredId !== '' && (string) ($picture['id'] ?? '') !== $preferredId) {
                continue;
            }

            $url = trim((string) ($picture['secure_url'] ?? $picture['url'] ?? ''));

            if ($url !== '') {
                return $url;
            }
        }

        foreach ($pictures as $picture) {
            if (! is_array($picture)) {
                continue;
            }

            $url = trim((string) ($picture['secure_url'] ?? $picture['url'] ?? ''));

            if ($url !== '') {
                return $url;
            }
        }

        return $fallback !== '' ? $fallback : null;
    }

    private function get(MeliAccount $account, string $path, array $query = []): Response
    {
        $response = null;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->ensureFreshAccessToken($account);

            $response = Http::withToken((string) $account->access_token)
                ->acceptJson()
                ->timeout(35)
                ->get(self::API_BASE.$path, $query);

            if ($response->status() === 401 && $attempt === 1) {
                $this->ensureFreshAccessToken($account, true);
                continue;
            }

            if ($response->status() === 429 && $attempt < 4) {
                $retryAfter = max(1, (int) $response->header('Retry-After', '2'));
                sleep(min($retryAfter, 10));
                continue;
            }

            if ($response->serverError() && $attempt < 4) {
                sleep($attempt);
                continue;
            }

            return $response;
        }

        if (! $response instanceof Response) {
            throw new RuntimeException('No se pudo realizar la consulta a Mercado Libre.');
        }

        return $response;
    }

    private function ensureFreshAccessToken(MeliAccount $account, bool $force = false): void
    {
        $hasUsableToken = filled($account->access_token)
            && $account->expires_at !== null
            && $account->expires_at->greaterThan(now()->addMinutes(5));

        if (! $force && $hasUsableToken) {
            return;
        }

        if (! filled($account->refresh_token)) {
            if (filled($account->access_token) && ! $force) {
                return;
            }

            throw new RuntimeException('La cuenta de Mercado Libre no tiene refresh_token válido.');
        }

        $clientId = (string) config('services.meli.client_id', config('services.meli.app_id', ''));
        $clientSecret = (string) config('services.meli.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Faltan MELI_CLIENT_ID y MELI_CLIENT_SECRET.');
        }

        $data = $this->oauth->refreshAccessToken(
            $clientId,
            $clientSecret,
            (string) $account->refresh_token,
        );

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()
                ->addSeconds((int) ($data['expires_in'] ?? 21600))
                ->subMinutes(2),
        ]);

        $account->refresh();
        $account->user?->syncMeliColumnsFromDefaultAccount();
    }

    private function responseMessage(Response $response, string $fallback): string
    {
        $data = $response->json();
        $message = is_array($data)
            ? trim((string) ($data['message'] ?? data_get($data, 'cause.0.message', '')))
            : '';

        return 'HTTP '.$response->status().': '.($message !== '' ? $message : $fallback);
    }
}
