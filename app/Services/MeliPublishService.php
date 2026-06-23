<?php

namespace App\Services;

use App\Models\MeliPublication;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeliPublishService
{
    public function client(): Client
    {
        $opts = [
            'base_uri' => 'https://api.mercadolibre.com/',
            'timeout'  => 25,
        ];
        $proxy = config('services.meli.http_proxy');
        if (is_string($proxy) && $proxy !== '') {
            $opts['proxy'] = $proxy;
        }

        return new Client($opts);
    }

    /**
     * GET público sin lanzar excepción en 4xx/5xx (para poder leer cuerpo JSON de error).
     *
     * @return array{status: int, json: mixed}
     */
    private function meliPublicGet(string $uri, array $options = []): array
    {
        $options['http_errors'] = false;
        $options['headers'] = array_merge(
            ['Accept' => 'application/json'],
            $options['headers'] ?? []
        );
        $res = $this->client()->get($uri, $options);
        $status = $res->getStatusCode();
        $json = json_decode((string) $res->getBody(), true);

        return ['status' => $status, 'json' => $json];
    }

    /**
     * Mensaje legible para fallos en endpoints públicos de categorías.
     */
    private function formatMeliPublicApiError(int $status, mixed $json): string
    {
        $hint = '';
        if (is_array($json)) {
            $hint = trim((string) ($json['message'] ?? $json['error'] ?? $json['cause'] ?? ''));
        }

        if ($status === 403) {
            $base = 'Mercado Libre rechazó la consulta (403).
A veces bloquea IPs de hosting/datacenters (PolicyAgent). Probá desde otra red o pedí a ML/hosting que permitan el IP del servidor.';

            return $hint !== '' ? $base.' Detalle: '.$hint : $base;
        }

        if ($status === 429) {
            return 'Mercado Libre limitó la velocidad de consultas (429). Probá de nuevo en un minuto.';
        }

        if ($hint !== '') {
            return 'Mercado Libre respondió con error (HTTP '.$status.'): '.$hint;
        }

        return 'Mercado Libre respondió con error (HTTP '.$status.') al listar categorías.';
    }

    protected function headers(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . $user->access_token,
            'Accept'        => 'application/json',
        ];
    }

    private function throwClientException(ClientException $e, string $tag): void
    {
        $status = $e->getResponse()?->getStatusCode() ?? 0;
        $body = (string) ($e->getResponse()?->getBody() ?? '');
        Log::warning("ML {$tag} error status={$status} body={$body}");
        throw new \RuntimeException("ML_ERROR:{$status}:{$body}");
    }

    /**
     * Mensaje legible a partir de RuntimeException ML_ERROR o validación previa.
     */
    public static function friendlyMlErrorMessage(string $message): string
    {
        if (! str_starts_with($message, 'ML_ERROR:')) {
            return $message;
        }

        $jsonPart = preg_replace('/^ML_ERROR:\d+:/', '', $message) ?? $message;
        $data = json_decode($jsonPart, true);
        if (! is_array($data)) {
            return 'Mercado Libre rechazó la actualización. Revisá el estado de la publicación en tu panel de ML.';
        }

        $causes = is_array($data['cause'] ?? null) ? $data['cause'] : [];
        $codes = [];
        foreach ($causes as $c) {
            if (is_array($c) && ! empty($c['code'])) {
                $codes[] = (string) $c['code'];
            }
        }

        $mlMsg = (string) ($data['message'] ?? '');
        $itemStatus = null;
        if (preg_match('/\[status:([a-z_]+)/i', $mlMsg, $m)) {
            $itemStatus = strtolower($m[1]);
        }

        $notModifiable = in_array('item.price.not_modifiable', $codes, true)
            || in_array('field_not_updatable', $codes, true);

        if ($notModifiable || in_array($itemStatus, ['inactive', 'closed', 'under_review', 'suspended'], true)) {
            $estado = match ($itemStatus) {
                'inactive' => 'inactiva',
                'closed' => 'cerrada',
                'under_review' => 'en revisión',
                'suspended' => 'suspendida',
                'paused' => 'pausada',
                default => 'no editable',
            };

            return "La publicación en Mercado Libre está {$estado} y no acepta cambios de precio ni stock. "
                .'En SYSCOM usá «Republicar» para crear una publicación nueva con el precio actual.';
        }

        $first = '';
        foreach ($causes as $c) {
            if (is_array($c) && ! empty($c['message'])) {
                $first = (string) $c['message'];
                break;
            }
        }

        if ($first !== '') {
            return $first;
        }

        return (string) ($data['message'] ?? 'Mercado Libre rechazó la actualización.');
    }

    /**
     * Sube una imagen binaria a ML para usarla en publicaciones.
     *
     * Endpoint oficial: POST https://api.mercadolibre.com/pictures/items/upload
     * (multipart/form-data con campo `file`).
     *
     * Devuelve el id de imagen ML (ej. "905487-MLM84321") para usarlo como `pictures: [{id: ...}]`
     * al crear/editar el item, o null si la subida falló (el caller debe tener fallback).
     *
     * @see https://developers.mercadolibre.com.mx/es_ar/imagenes-de-publicaciones
     */
    public function uploadPictureBytes(User $user, string $bytes, string $filename = 'image.jpg', string $mimeType = 'image/jpeg'): ?string
    {
        if ($bytes === '') {
            return null;
        }

        try {
            $res = $this->client()->post('pictures/items/upload', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $user->access_token,
                    'Accept' => 'application/json',
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $bytes,
                        'filename' => $filename,
                        'headers' => ['Content-Type' => $mimeType],
                    ],
                ],
                'timeout' => 60,
            ]);

            $json = json_decode((string) $res->getBody(), true);
            $id = is_array($json) ? trim((string) ($json['id'] ?? '')) : '';

            if ($id === '') {
                Log::warning('ML uploadPictureBytes sin id', ['body' => $json]);

                return null;
            }

            return $id;
        } catch (ClientException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $body = (string) ($e->getResponse()?->getBody() ?? '');
            Log::warning('ML uploadPictureBytes ClientException', [
                'status' => $status,
                'body' => $body,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('ML uploadPictureBytes excepción', ['err' => $e->getMessage()]);

            return null;
        }
    }

    // ==========================
    // CATEGORY INFO
    // ==========================
    public function getCategory(User $user, string $categoryId): array
    {
        try {
            $res = $this->client()->get("categories/{$categoryId}", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string) $res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getCategory');
        }
    }

    public function categoryIsCatalogLike(array $cat): bool
    {
        $settings = is_array($cat['settings'] ?? null) ? $cat['settings'] : [];
        $tags = $cat['tags'] ?? [];
        $tags = is_array($tags) ? $tags : [];

        return
            (bool)($settings['buy_box'] ?? false) ||
            (bool)($settings['catalog_listing'] ?? false) ||
            in_array('buy_box', $tags, true) ||
            in_array('catalog', $tags, true) ||
            in_array('catalog_listing', $tags, true);
    }

    public function getCategoryAttributes(User $user, string $categoryId): array
    {
        try {
            $res = $this->client()->get("categories/{$categoryId}/attributes", [
                'headers' => $this->headers($user),
            ]);

            $json = json_decode((string) $res->getBody(), true);
            return is_array($json) ? $json : [];
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getCategoryAttributes');
        }
    }

    /**
     * Atributos de categoría sin token. Incluye filas marcadas como hidden (p. ej. EMPTY_GTIN_REASON),
     * que a veces no vienen en la misma llamada autenticada y dejan sin cumplir el par condicional GTIN.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoryAttributesPublic(string $categoryId): array
    {
        try {
            $res = $this->client()->get("categories/{$categoryId}/attributes", [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $json = json_decode((string) $res->getBody(), true);

            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            Log::warning('ML getCategoryAttributesPublic falló', [
                'category' => $categoryId,
                'err' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Une atributos autenticados + públicos por id (sin pisar lo que ya trae ML con el token).
     * Rellena huecos sobre todo para atributos hidden visibles sólo en la respuesta pública.
     *
     * @param  array<int, array<string, mixed>>  $authAttrs
     * @param  array<int, array<string, mixed>>  $publicAttrs
     * @return array<int, array<string, mixed>>
     */
    public function mergeCategoryAttributes(array $authAttrs, array $publicAttrs): array
    {
        $by = [];
        foreach ($authAttrs as $a) {
            if (! is_array($a)) {
                continue;
            }
            $id = strtoupper(trim((string) ($a['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $by[$id] = $a;
        }
        foreach ($publicAttrs as $a) {
            if (! is_array($a)) {
                continue;
            }
            $id = strtoupper(trim((string) ($a['id'] ?? '')));
            if ($id === '' || isset($by[$id])) {
                continue;
            }
            $by[$id] = $a;
        }

        return array_values($by);
    }

    public function pickAttrIdFromCategory(array $catAttrs, array $candidates): ?string
    {
        $wanted = array_flip(array_map(
            fn ($x) => strtoupper(trim((string) $x)),
            $candidates
        ));

        foreach ($catAttrs as $attr) {
            $id = strtoupper(trim((string)($attr['id'] ?? '')));
            if ($id !== '' && isset($wanted[$id])) {
                return (string)($attr['id'] ?? null);
            }
        }

        return null;
    }

    public function tireAttributeIdsFromCategoryAttrs(array $catAttrs): array
    {
        return [
            'section_width' => $this->pickAttrIdFromCategory($catAttrs, [
                'VEHICLE_TIRE_SECTION_WIDTH',
                'AUTOMOTIVE_TIRE_SECTION_WIDTH',
                'SECTION_WIDTH',
            ]),

            'aspect_ratio' => $this->pickAttrIdFromCategory($catAttrs, [
                'VEHICLE_TIRE_ASPECT_RATIO',
                'AUTOMOTIVE_TIRE_ASPECT_RATIO',
                'TIRE_ASPECT_RATIO',
                'ASPECT_RATIO',
            ]),

            'rim_diameter' => $this->pickAttrIdFromCategory($catAttrs, [
                'VEHICLE_TIRE_RIM_DIAMETER',
                'AUTOMOTIVE_TIRE_RIM_DIAMETER',
                'RIM_DIAMETER',
            ]),

            'load_index' => $this->pickAttrIdFromCategory($catAttrs, [
                'VEHICLE_TIRE_LOAD_INDEX',
                'LOAD_INDEX',
            ]),

            'speed_index' => $this->pickAttrIdFromCategory($catAttrs, [
                'VEHICLE_TIRE_SPEED_INDEX',
                'SPEED_INDEX',
            ]),

            'line' => $this->pickAttrIdFromCategory($catAttrs, [
                'LINE',
            ]),

            'sidewall' => $this->pickAttrIdFromCategory($catAttrs, [
                'SIDEWALL',
                'LETTER',
            ]),

            'service_type' => $this->pickAttrIdFromCategory($catAttrs, [
                'SERVICE_TYPE',
                'TIRE_SERVICE_TYPE',
                'VEHICLE_TIRE_SERVICE_TYPE',
            ]),

            'run_flat' => $this->pickAttrIdFromCategory($catAttrs, [
                'IS_RUN_FLAT',
                'RUN_FLAT',
            ]),

            'utqg' => $this->pickAttrIdFromCategory($catAttrs, [
                'UTQG',
            ]),

            'terrain_type' => $this->pickAttrIdFromCategory($catAttrs, [
                'TERRAIN_TYPE',
                'TRACTION_TYPE',
            ]),

            'construction_type' => $this->pickAttrIdFromCategory($catAttrs, [
                'TIRE_CONSTRUCTION_TYPE',
                'VEHICLE_TIRE_CONSTRUCTION_TYPE',
                'CONSTRUCTION_TYPE',
            ]),

            'load_range' => $this->pickAttrIdFromCategory($catAttrs, [
                'LOAD_RANGE',
            ]),

            'tire_quantity' => $this->pickAttrIdFromCategory($catAttrs, [
                'TIRES_NUMBER',
                'TIRE_QUANTITY',
                'TIRES_QUANTITY',
                'QUANTITY_OF_TIRES',
            ]),

            'gtin' => $this->pickAttrIdFromCategory($catAttrs, [
                'GTIN',
            ]),

            'empty_gtin_reason' => $this->pickAttrIdFromCategory($catAttrs, [
                'EMPTY_GTIN_REASON',
            ]),

            'seller_sku' => $this->pickAttrIdFromCategory($catAttrs, [
                'SELLER_SKU',
            ]),
        ];
    }

    // ==========================
    // USER / SELLER MODE
    // ==========================
    public function getMe(User $user): array
    {
        try {
            $res = $this->client()->get("users/me", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string)$res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getMe');
        }
    }

    public function isUserProductSeller(User $user): bool
    {
        $me = $this->getMe($user);
        $tags = is_array($me['tags'] ?? null) ? $me['tags'] : [];

        return in_array('user_product_seller', $tags, true);
    }

    public function getBrandsByUser(User $user, ?string $meliUserId = null): array
    {
        $meliUserId = $meliUserId ?: (string) $user->meli_id;

        if (!$meliUserId) {
            $me = $this->getMe($user);
            $meliUserId = (string) ($me['id'] ?? '');
        }

        if (!$meliUserId) {
            throw new \RuntimeException('No se pudo resolver el meli user id.');
        }

        try {
            $res = $this->client()->get("users/{$meliUserId}/brands", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string)$res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getBrandsByUser');
        }
    }

    // ==========================
    // PRODUCT INFO (MLPxxxx)
    // ==========================
    public function getProduct(User $user, string $productId): array
    {
        try {
            $res = $this->client()->get("products/{$productId}", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string) $res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getProduct');
        }
    }

    // ==========================
    // ITEM INFO (MLMxxxx)
    // ==========================
    public function getItem(User $user, string $mlm): array
    {
        try {
            $res = $this->client()->get("items/{$mlm}", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string) $res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getItem');
        }
    }

    public function getModerations(User $user, string $mlm): array
    {
        try {
            $res = $this->client()->get("items/{$mlm}/moderations", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string) $res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'getModerations');
        }
    }

    public function normalizeCatalogProductId(User $user, string $maybeCatalogId): string
    {
        $id = trim($maybeCatalogId);
        if ($id === '') {
            return $id;
        }

        if (str_starts_with($id, 'MLP')) {
            return $id;
        }

        if (str_starts_with($id, 'MLM')) {
            try {
                $item = $this->getItem($user, $id);
                $real = $item['catalog_product_id'] ?? null;

                if (is_string($real) && $real !== '' && str_starts_with($real, 'MLP')) {
                    return $real;
                }
            } catch (\Throwable $e) {
                Log::warning('ML normalizeCatalogProductId failed', [
                    'input' => $id,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        return $id;
    }

    // ==========================
    // SEARCH CATALOG
    // ==========================
    public function searchCatalogProducts(User $user, string $query, ?string $categoryId = null, int $limit = 10): array
    {
        $limit = max(1, min(20, (int)$limit));

        $params = [
            'q' => $query,
            'limit' => $limit,
            'catalog_listing' => 'true',
        ];

        if ($categoryId) {
            $params['category'] = $categoryId;
        }

        try {
            $res = $this->client()->get("sites/MLM/search", [
                'headers' => ['Accept' => 'application/json'],
                'query' => $params,
            ]);

            $json = json_decode((string)$res->getBody(), true);

            $out = [];
            foreach (($json['results'] ?? []) as $r) {
                $out[] = [
                    'title' => $r['title'] ?? $r['name'] ?? null,
                    'id' => $r['id'] ?? null,
                    'permalink' => $r['permalink'] ?? null,
                    'price' => $r['price'] ?? null,
                    'catalog_product_id' => $r['catalog_product_id'] ?? null,
                    'thumbnail' => $r['thumbnail'] ?? null,
                ];
            }

            $out = array_values(array_filter($out, fn ($x) => !empty($x['catalog_product_id'])));

            foreach ($out as &$row) {
                if (empty($row['title']) && !empty($row['catalog_product_id'])) {
                    try {
                        $p = $this->getProduct($user, (string)$row['catalog_product_id']);
                        $row['title'] = $p['name'] ?? $p['title'] ?? (string)$row['catalog_product_id'];
                        $row['thumbnail'] = $row['thumbnail'] ?: ($p['pictures'][0]['url'] ?? null);
                    } catch (\Throwable $e) {
                        $row['title'] = (string)$row['catalog_product_id'];
                    }
                }
            }
            unset($row);

            return $out;
        } catch (ClientException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $body = (string)($e->getResponse()?->getBody() ?? '');
            Log::warning("ML searchCatalogProducts sites/MLM/search failed status={$status} body={$body}");
        }

        try {
            $q2 = [
                'site_id' => 'MLM',
                'q' => $query,
                'limit' => $limit,
                'status' => 'active',
            ];

            if ($categoryId) {
                $q2['category'] = $categoryId;
            }

            $res2 = $this->client()->get("products/search", [
                'headers' => $this->headers($user),
                'query'   => $q2,
            ]);

            $json2 = json_decode((string)$res2->getBody(), true);
            $results = $json2['results'] ?? [];

            $out2 = [];

            foreach ($results as $p) {
                $pid = is_array($p) ? ($p['id'] ?? null) : null;
                if (!$pid) {
                    continue;
                }

                $title = $p['name'] ?? $p['title'] ?? null;

                if (!$title) {
                    try {
                        $pp = $this->getProduct($user, (string)$pid);
                        $title = $pp['name'] ?? $pp['title'] ?? (string)$pid;
                    } catch (\Throwable $e) {
                        $title = (string)$pid;
                    }
                }

                $out2[] = [
                    'title' => $title,
                    'id' => null,
                    'permalink' => null,
                    'price' => null,
                    'catalog_product_id' => (string)$pid,
                    'thumbnail' => $p['thumbnail'] ?? ($p['pictures'][0]['url'] ?? null),
                ];
            }

            return $out2;
        } catch (ClientException $e2) {
            $this->throwClientException($e2, 'searchCatalogProducts');
        }
    }

    public function searchCatalog(User $user, string $query, ?string $categoryId = null, int $limit = 10): array
    {
        return $this->searchCatalogProducts($user, $query, $categoryId, $limit);
    }

    // ==========================
    // UPSERT PUBLICATION
    // ==========================
    public function upsertPublication(User $user, string $sku, array $item): MeliPublication
    {
        $mlm = $item['id'] ?? null;
        if (! $mlm) {
            throw new \RuntimeException('Item sin id (MLM).');
        }

        $mlm = (string) $mlm;
        $subStatus = $this->normalizeSubStatusForStorage($item['sub_status'] ?? null);
        $now = now();

        $row = [
            'sku' => $sku,
            'status' => $item['status'] ?? null,
            'sub_status' => $subStatus !== null ? json_encode($subStatus, JSON_UNESCAPED_UNICODE) : null,
            'permalink' => $item['permalink'] ?? null,
            'raw' => json_encode($item, JSON_UNESCAPED_UNICODE),
            'last_sync_at' => $now,
            'updated_at' => $now,
        ];

        // Evita Eloquent updateOrCreate: filas con JSON corrupto en BD rompían al hidratar el modelo (500 en sync).
        $existingId = DB::table('meli_publications')
            ->where('user_id', $user->id)
            ->where('mlm', $mlm)
            ->value('id');

        if ($existingId) {
            DB::table('meli_publications')->where('id', $existingId)->update($row);
            $id = (int) $existingId;
        } else {
            $id = (int) DB::table('meli_publications')->insertGetId(array_merge($row, [
                'user_id' => $user->id,
                'mlm' => $mlm,
                'created_at' => $now,
            ]));
        }

        $pub = new MeliPublication([
            'user_id' => $user->id,
            'sku' => $sku,
            'mlm' => $mlm,
            'status' => $row['status'],
            'sub_status' => $subStatus,
            'permalink' => $row['permalink'],
            'raw' => $item,
            'last_sync_at' => $now,
        ]);
        $pub->id = $id;
        $pub->exists = true;

        return $pub;
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeSubStatusForStorage(mixed $subStatus): ?array
    {
        if (is_string($subStatus) && $subStatus !== '') {
            return [$subStatus];
        }
        if (! is_array($subStatus) || $subStatus === []) {
            return null;
        }

        return array_values(array_map('strval', $subStatus));
    }

    // ==========================
    // REFRESH STATUS
    // ==========================
    public function refreshStatus(User $user, string $mlm, ?string $sku = null): MeliPublication
    {
        $mlm = trim($mlm);

        if ($mlm === '') {
            throw new \RuntimeException('MLM vacío.');
        }

        $item = $this->getItemOrNull($user, $mlm);
        if ($item === null) {
            return $this->markPublicationClosedNotFoundOnMl($user, $mlm, $sku);
        }

        $moderations = null;
        try {
            $moderations = $this->getModerations($user, $mlm);
        } catch (\Throwable $e) {
            Log::warning('ML getModerations failed (best-effort)', [
                'mlm' => $mlm,
                'err' => $e->getMessage(),
            ]);
        }

        $subStatus = $item['sub_status'] ?? null;

        if (is_string($subStatus) && $subStatus !== '') {
            $subStatus = [$subStatus];
        } elseif (!is_array($subStatus)) {
            $subStatus = null;
        }

        $existing = MeliPublication::where('user_id', $user->id)
            ->where('mlm', $mlm)
            ->first();

        return MeliPublication::updateOrCreate(
            ['user_id' => $user->id, 'mlm' => $mlm],
            [
                'sku'          => $sku ?: ($existing?->sku),
                'status'       => $item['status'] ?? null,
                'sub_status'   => $subStatus,
                'permalink'    => $item['permalink'] ?? null,
                'raw'          => [
                    'item' => $item,
                    'moderations' => $moderations,
                ],
                'last_sync_at' => now(),
            ]
        );
    }

    /**
     * @return array<string, mixed>|null null si ML respondió 404 (publicación eliminada o inexistente).
     */
    private function getItemOrNull(User $user, string $mlm): ?array
    {
        try {
            return $this->getItem($user, $mlm);
        } catch (\RuntimeException $e) {
            if (preg_match('/^ML_ERROR:404:/', $e->getMessage())) {
                Log::info('ML getItem 404 — publicación no existe en ML', ['mlm' => $mlm]);

                return null;
            }

            throw $e;
        }
    }

    private function markPublicationClosedNotFoundOnMl(User $user, string $mlm, ?string $sku): MeliPublication
    {
        $existing = MeliPublication::where('user_id', $user->id)
            ->where('mlm', $mlm)
            ->first();

        return MeliPublication::updateOrCreate(
            ['user_id' => $user->id, 'mlm' => $mlm],
            [
                'sku'          => $sku ?: ($existing?->sku),
                'status'       => 'closed',
                'sub_status'   => ['not_found'],
                'permalink'    => null,
                'raw'          => [
                    'item' => [
                        'id' => $mlm,
                        'status' => 'closed',
                    ],
                    'moderations' => null,
                    'sync_note' => 'Item no encontrado en Mercado Libre (404).',
                ],
                'last_sync_at' => now(),
            ]
        );
    }


    /**
     * Categorías raíz del sitio (México). Endpoint público, no requiere token.
     *
     * @return array{
     *   children: list<array{id: string, name: string, has_children: bool|null}>,
     *   error: string|null,
     *   status: int
     * }
     */
    public function getSiteRootCategories(string $siteId = 'MLM'): array
    {
        try {
            $packet = $this->meliPublicGet("sites/{$siteId}/categories");
            $status = $packet['status'];
            $raw = $packet['json'];

            if ($status !== 200) {
                Log::warning('ML getSiteRootCategories HTTP error', ['status' => $status, 'json' => $raw]);

                return [
                    'children' => [],
                    'error' => $this->formatMeliPublicApiError($status, $raw),
                    'status' => $status,
                ];
            }

            // Un error JSON ({ "message", "code" }) es asociativo: array_is_list es false.
            if (! is_array($raw) || ! array_is_list($raw)) {
                Log::warning('ML getSiteRootCategories formato inesperado', ['json' => $raw]);

                return [
                    'children' => [],
                    'error' => 'Respuesta inesperada de Mercado Libre al listar categorías raíz (no es una lista).',
                    'status' => $status,
                ];
            }

            $out = [];
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $children = $row['children_categories'] ?? [];
                $hasChildren = is_array($children) && $children !== [];

                $out[] = [
                    'id' => $id,
                    'name' => $name !== '' ? $name : $id,
                    // El listado de raíz a veces no trae children_categories; se confirma al abrir el nodo.
                    'has_children' => $hasChildren ? true : null,
                ];
            }

            return [
                'children' => $out,
                'error' => null,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            Log::warning('ML getSiteRootCategories falló', ['err' => $e->getMessage()]);

            return [
                'children' => [],
                'error' => 'No se pudo conectar con Mercado Libre: '.$e->getMessage(),
                'status' => 0,
            ];
        }
    }

    /**
     * Detalle de categoría y hijos directos (endpoint público).
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   path_from_root: list<array{id: string, name: string}>,
     *   children: list<array{id: string, name: string, has_children: bool|null}>,
     *   error?: string|null,
     * }
     */
    public function getCategoryBrowseNode(string $categoryId): array
    {
        $categoryId = trim($categoryId);
        if ($categoryId === '') {
            return [
                'id' => '',
                'name' => '',
                'path_from_root' => [],
                'children' => [],
                'error' => null,
            ];
        }

        try {
            $packet = $this->meliPublicGet("categories/{$categoryId}");
            $status = $packet['status'];
            $cat = $packet['json'];

            if ($status !== 200) {
                Log::warning('ML getCategoryBrowseNode HTTP error', [
                    'category_id' => $categoryId,
                    'status' => $status,
                    'json' => $cat,
                ]);

                return [
                    'id' => $categoryId,
                    'name' => $categoryId,
                    'path_from_root' => [],
                    'children' => [],
                    'error' => $status === 404
                        ? 'Categoría no encontrada en Mercado Libre ('.$categoryId.').'
                        : $this->formatMeliPublicApiError($status, $cat),
                ];
            }

            if (! is_array($cat) || trim((string) ($cat['id'] ?? '')) === '') {
                Log::warning('ML getCategoryBrowseNode JSON inválido', ['category_id' => $categoryId, 'json' => $cat]);

                return [
                    'id' => $categoryId,
                    'name' => $categoryId,
                    'path_from_root' => [],
                    'children' => [],
                    'error' => 'Respuesta inesperada de Mercado Libre al leer la categoría.',
                ];
            }

            $pathFromRoot = [];
            $pathRaw = $cat['path_from_root'] ?? [];
            if (is_array($pathRaw)) {
                foreach ($pathRaw as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $pid = trim((string) ($p['id'] ?? ''));
                    $pname = trim((string) ($p['name'] ?? ''));
                    if ($pid !== '') {
                        $pathFromRoot[] = ['id' => $pid, 'name' => $pname !== '' ? $pname : $pid];
                    }
                }
            }

            $childrenOut = [];
            $children = $cat['children_categories'] ?? [];
            if (is_array($children)) {
                foreach ($children as $child) {
                    if (is_string($child)) {
                        $cid = trim($child);
                        if ($cid === '') {
                            continue;
                        }
                        $childrenOut[] = [
                            'id' => $cid,
                            'name' => $cid,
                            'has_children' => null,
                        ];

                        continue;
                    }
                    if (! is_array($child)) {
                        continue;
                    }
                    $cid = trim((string) ($child['id'] ?? ''));
                    $cname = trim((string) ($child['name'] ?? ''));
                    if ($cid === '') {
                        continue;
                    }
                    $nested = $child['children_categories'] ?? null;
                    $hasChildren = is_array($nested) && $nested !== [];

                    $childrenOut[] = [
                        'id' => $cid,
                        'name' => $cname !== '' ? $cname : $cid,
                        'has_children' => $hasChildren,
                    ];
                }
            }

            return [
                'id' => trim((string) ($cat['id'] ?? $categoryId)),
                'name' => trim((string) ($cat['name'] ?? $categoryId)),
                'path_from_root' => $pathFromRoot,
                'children' => $childrenOut,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('ML getCategoryBrowseNode falló', [
                'category_id' => $categoryId,
                'err' => $e->getMessage(),
            ]);

            return [
                'id' => $categoryId,
                'name' => $categoryId,
                'path_from_root' => [],
                'children' => [],
                'error' => 'No se pudo conectar con Mercado Libre: '.$e->getMessage(),
            ];
        }
    }

    // ==========================
    // SUGGEST CATEGORIES
    // ==========================
    public function suggestCategories(User $user, string $query, int $limit = 8): array
    {
        // La API suele aceptar pocos resultados; valores altos pueden devolver 400.
        $limit = max(1, min(8, (int) $limit));

        try {
            $res = $this->client()->get("sites/MLM/domain_discovery/search", [
                'headers' => $this->headers($user),
                'query'   => ['q' => $query, 'limit' => $limit],
            ]);

            $raw = json_decode((string) $res->getBody(), true);

            return $this->normalizeDomainDiscoveryCategories($raw);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'suggestCategories');
        }
    }

    /**
     * ML devuelve category_id / category_name (no id / name). Unificamos para la UI y selects.
     *
     * @param  mixed  $raw
     */
    private function normalizeDomainDiscoveryCategories($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        if (isset($raw['results']) && is_array($raw['results'])) {
            $rows = $raw['results'];
        } elseif (isset($raw[0]) || $raw === []) {
            $rows = $raw;
        } else {
            $rows = array_values($raw);
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['category_id'] ?? $row['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $name = trim((string) ($row['category_name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['domain_name'] ?? ''));
            }
            if ($name === '') {
                $name = 'Categoría ML';
            }

            $out[] = [
                'id'   => (string) $id,
                'name' => $name,
            ];
        }

        return $out;
    }

    // ==========================
    // CREATE ITEM / DESCRIPTION / UPDATE
    // ==========================
    public function createItem(User $user, array $payload): array
    {
        try {
            $res = $this->client()->post("items", [
                'headers' => $this->headers($user) + ['Content-Type' => 'application/json'],
                'json'    => $payload,
            ]);

            return json_decode((string) $res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'createItem');
        }
    }

    public function updateItem(User $user, string $mlm, array $payload): array
    {
        try {
            $res = $this->client()->put("items/{$mlm}", [
                'headers' => $this->headers($user) + ['Content-Type' => 'application/json'],
                'json'    => $payload,
            ]);

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (ClientException $e) {
            $this->throwClientException($e, 'updateItem');
        }
    }

    public function createDescription(User $user, string $mlm, string $plainText): array
    {
        try {
            $res = $this->client()->post("items/{$mlm}/description", [
                'headers' => $this->headers($user) + ['Content-Type' => 'application/json'],
                'json'    => ['plain_text' => $plainText],
            ]);

            return json_decode((string) $res->getBody(), true);
        } catch (ClientException $e) {
            $this->throwClientException($e, 'createDescription');
        }
    }
}