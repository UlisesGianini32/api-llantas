<?php

namespace App\Services;

use App\Support\SyscomHermosilloStock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyscomApiService
{
    protected string $baseUrl;

    protected string $oauthUrl;

    protected string $clientId;

    protected string $clientSecret;

    /**
     * Token OAuth obtenido durante el mismo proceso.
     *
     * Esto evita solicitar un token nuevo por cada producto.
     */
    protected ?string $runtimeOAuthToken = null;

    /**
     * Tipo de cambio calculado durante el mismo proceso.
     *
     * Esto evita llamar repetidamente a /tipocambio.
     */
    protected ?float $runtimeTipoCambio = null;

    /**
     * Cuando SYSCOM devuelve 401, bloquea nuevos intentos OAuth
     * durante el mismo proceso.
     */
    protected bool $oauthDisabled = false;

    /**
     * Motivo por el que se deshabilitó OAuth durante el proceso.
     */
    protected ?string $oauthDisabledReason = null;

    /**
     * Clave utilizada para compartir temporalmente el fallo OAuth
     * entre diferentes procesos o Jobs.
     */
    protected string $oauthFailureCacheKey = 'syscom.oauth.temporarily_disabled';

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config(
                'services.syscom.base_url',
                'https://developers.syscom.mx/api/v1'
            ),
            '/'
        );

        $this->oauthUrl = (string) config(
            'services.syscom.oauth_url',
            'https://developers.syscom.mx/oauth/token'
        );

        $this->clientId = trim(
            (string) config('services.syscom.client_id', '')
        );

        $this->clientSecret = trim(
            (string) config('services.syscom.client_secret', '')
        );
    }

    /**
     * Obtiene el token de acceso para SYSCOM.
     *
     * Prioridad:
     * 1. Token fijo configurado.
     * 2. Token obtenido durante este proceso.
     * 3. Token OAuth cacheado.
     * 4. Solicitud nueva a /oauth/token.
     */
    public function getAccessToken(): string
    {
        $configuredToken = trim(
            (string) config('services.syscom.access_token', '')
        );

        if ($configuredToken !== '') {
            return $configuredToken;
        }

        if (
            $this->runtimeOAuthToken !== null
            && $this->runtimeOAuthToken !== ''
        ) {
            return $this->runtimeOAuthToken;
        }

        if ($this->oauthDisabled) {
            throw new \RuntimeException(
                $this->oauthDisabledReason
                    ?? 'Autenticación SYSCOM deshabilitada durante este proceso.'
            );
        }

        /*
         * Evita que diferentes workers sigan intentando autenticarse
         * después de que alguno ya recibió un 401.
         */
        $cachedFailure = Cache::get(
            $this->oauthFailureCacheKey
        );

        if (
            is_string($cachedFailure)
            && trim($cachedFailure) !== ''
        ) {
            $this->oauthDisabled = true;
            $this->oauthDisabledReason = trim($cachedFailure);

            throw new \RuntimeException(
                $this->oauthDisabledReason
            );
        }

        if (
            $this->clientId === ''
            || $this->clientSecret === ''
        ) {
            $message = 'Faltan credenciales de SYSCOM '
                . '(SYSCOM_CLIENT_ID/SYSCOM_CLIENT_SECRET).';

            $this->disableOAuthTemporarily($message);

            throw new \RuntimeException($message);
        }

        /*
         * Cacheamos el token entre procesos para no llamar a OAuth
         * en cada ejecución.
         */
        $tokenCacheKey = $this->oauthTokenCacheKey();

        $cachedToken = trim(
            (string) Cache::get($tokenCacheKey, '')
        );

        if ($cachedToken !== '') {
            $this->runtimeOAuthToken = $cachedToken;

            return $cachedToken;
        }

        /*
         * Reducimos los reintentos por defecto.
         *
         * Antes eran 6, lo cual podía producir esperas acumuladas
         * de varios minutos.
         */
        $maxAttempts = max(
            1,
            min(
                3,
                (int) config(
                    'syscom.oauth_max_attempts',
                    2
                )
            )
        );

        $lastError = '';

        for (
            $attempt = 1;
            $attempt <= $maxAttempts;
            $attempt++
        ) {
            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(25)
                    ->post(
                        $this->oauthUrl,
                        [
                            'grant_type' => 'client_credentials',
                            'client_id' => $this->clientId,
                            'client_secret' => $this->clientSecret,
                        ]
                    );
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();

                Log::warning(
                    'SYSCOM oauth exception',
                    [
                        'attempt' => $attempt,
                        'error' => $exception->getMessage(),
                    ]
                );

                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException(
                        'No se pudo conectar con OAuth de SYSCOM: '
                        . $lastError
                    );
                }

                sleep(min(3, $attempt));

                continue;
            }

            $status = $response->status();

            /*
             * Un 401 significa que las credenciales no son válidas.
             *
             * No tiene sentido volver a intentar con los mismos datos.
             */
            if ($status === 401) {
                $body = trim((string) $response->body());

                $message = 'No se pudo autenticar con SYSCOM: 401';

                if ($body !== '') {
                    $message .= ' ' . mb_substr(
                        $body,
                        0,
                        500
                    );
                }

                $this->disableOAuthTemporarily($message);

                Log::error(
                    'SYSCOM oauth credenciales inválidas',
                    [
                        'status' => $status,
                        'body' => mb_substr($body, 0, 500),
                    ]
                );

                throw new \RuntimeException($message);
            }

            /*
             * Si SYSCOM limita las solicitudes, hacemos una espera corta.
             *
             * Ya no esperamos 5, 10, 20, 40, 80 segundos.
             */
            if ($status === 429) {
                $wait = min(
                    10,
                    2 * $attempt
                );

                $lastError = '429 too many requests (oauth)';

                Log::warning(
                    'SYSCOM oauth 429, reintento corto',
                    [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'wait_s' => $wait,
                    ]
                );

                if ($attempt < $maxAttempts) {
                    sleep($wait);
                    continue;
                }

                throw new \RuntimeException(
                    'No se pudo autenticar con SYSCOM: '
                    . $lastError
                );
            }

            if (! $response->successful()) {
                $lastError = $status
                    . ' '
                    . mb_substr(
                        (string) $response->body(),
                        0,
                        500
                    );

                /*
                 * Solo se reintentan errores temporales 5xx.
                 */
                if (
                    $status >= 500
                    && $attempt < $maxAttempts
                ) {
                    sleep(min(4, 2 * $attempt));

                    continue;
                }

                throw new \RuntimeException(
                    'No se pudo autenticar con SYSCOM: '
                    . $lastError
                );
            }

            $value = trim(
                (string) (
                    $response->json('access_token')
                    ?? ''
                )
            );

            if ($value === '') {
                throw new \RuntimeException(
                    'SYSCOM no devolvió access_token en oauth/token.'
                );
            }

            $expiresIn = (int) (
                $response->json('expires_in')
                ?? 3600
            );

            /*
             * Dejamos un margen antes de la expiración real.
             */
            $cacheSeconds = max(
                60,
                $expiresIn - 120
            );

            $this->runtimeOAuthToken = $value;

            Cache::put(
                $tokenCacheKey,
                $value,
                now()->addSeconds($cacheSeconds)
            );

            /*
             * Si la autenticación ya funciona, eliminamos el bloqueo temporal.
             */
            Cache::forget(
                $this->oauthFailureCacheKey
            );

            return $value;
        }

        throw new \RuntimeException(
            'No se pudo autenticar con SYSCOM: '
            . $lastError
        );
    }

    public function getBranches(string $token): array
    {
        $response = $this->request(
            $token,
            '/carrito/sucursales'
        );

        return is_array($response)
            ? $response
            : [];
    }

    /**
     * Categorías nivel 1.
     *
     * GET /categorias
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(string $token): array
    {
        $response = $this->request(
            $token,
            '/categorias'
        );

        return is_array($response)
            ? $response
            : [];
    }

    /**
     * Detalle de categoría con subcategorías.
     *
     * GET /categorias/{id}
     *
     * @return array<string, mixed>
     */
    public function getCategory(
        string $token,
        int|string $id
    ): array {
        $response = $this->request(
            $token,
            '/categorias/' . trim((string) $id)
        );

        return is_array($response)
            ? $response
            : [];
    }

    /**
     * Tipo de cambio SYSCOM.
     *
     * GET /tipocambio
     *
     * @return array<string, mixed>
     */
    public function getTipoCambio(
        string $token
    ): array {
        $response = $this->request(
            $token,
            '/tipocambio'
        );

        return is_array($response)
            ? $response
            : [];
    }

    /**
     * Tipo de cambio MXN usado por SYSCOM.
     *
     * El valor se reutiliza durante todo el proceso.
     * Si falla SYSCOM, el fallback también se conserva en memoria
     * para evitar nuevos intentos OAuth por cada producto.
     */
    public function getTipoCambioMxn(): float
    {
        if (
            $this->runtimeTipoCambio !== null
            && $this->runtimeTipoCambio > 0
        ) {
            return $this->runtimeTipoCambio;
        }

        $kind = trim(
            (string) config(
                'syscom.tc_kind',
                'preferencial'
            )
        );

        if ($kind === '') {
            $kind = 'preferencial';
        }

        $minutes = max(
            1,
            (int) config(
                'syscom.tc_cache_minutes',
                60
            )
        );

        $fallback = (float) config(
            'syscom.tc_fallback',
            17.5
        );

        if ($fallback <= 0) {
            $fallback = 17.5;
        }

        $cacheKey = "syscom.tc.{$kind}";

        $cached = Cache::get($cacheKey);

        if (
            is_numeric($cached)
            && (float) $cached > 0
        ) {
            $this->runtimeTipoCambio = (float) $cached;

            return $this->runtimeTipoCambio;
        }

        /*
         * Si ya falló OAuth durante este proceso, no intentamos otra vez.
         */
        if ($this->oauthDisabled) {
            $this->runtimeTipoCambio = $fallback;

            return $this->runtimeTipoCambio;
        }

        try {
            $token = $this->getAccessToken();
            $tipoCambio = $this->getTipoCambio($token);
        } catch (\Throwable $exception) {
            /*
             * Solo registramos el primer fallo relevante del proceso.
             */
            if ($this->runtimeTipoCambio === null) {
                Log::warning(
                    'SYSCOM tipo_cambio falló, usando fallback',
                    [
                        'error' => $exception->getMessage(),
                        'fallback' => $fallback,
                    ]
                );
            }

            $this->runtimeTipoCambio = $fallback;

            /*
             * Guardamos temporalmente el fallback para que otros procesos
             * tampoco vuelvan a consultar inmediatamente.
             */
            Cache::put(
                $cacheKey,
                $fallback,
                now()->addMinutes(
                    min($minutes, 10)
                )
            );

            return $this->runtimeTipoCambio;
        }

        $value = $tipoCambio[$kind]
            ?? null;

        if (
            ! is_numeric($value)
            || (float) $value <= 0
        ) {
            $value = $tipoCambio['preferencial']
                ?? $tipoCambio['normal']
                ?? null;
        }

        if (
            ! is_numeric($value)
            || (float) $value <= 0
        ) {
            $value = $fallback;
        }

        $this->runtimeTipoCambio = (float) $value;

        Cache::put(
            $cacheKey,
            $this->runtimeTipoCambio,
            now()->addMinutes($minutes)
        );

        return $this->runtimeTipoCambio;
    }

    public function resolveBranchCodeByName(
        string $token,
        string $branchName
    ): ?string {
        $needle = mb_strtolower(
            trim($branchName)
        );

        if ($needle === '') {
            return null;
        }

        foreach (
            $this->getBranches($token)
            as $branch
        ) {
            if (! is_array($branch)) {
                continue;
            }

            $name = mb_strtolower(
                trim(
                    (string) (
                        $branch['nombre_sucursal']
                        ?? ''
                    )
                )
            );

            $code = trim(
                (string) ($branch['codigo'] ?? '')
            );

            if (
                $code === ''
                || $name === ''
            ) {
                continue;
            }

            if (str_contains($name, $needle)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Búsqueda de productos en sucursal.
     *
     * La API exige al menos uno:
     * - busqueda
     * - marca
     * - categoria
     *
     * @param array{
     *     busqueda?: string,
     *     marca?: string,
     *     categoria?: string
     * } $filter
     */
    public function searchProducts(
        string $token,
        string $branchCode,
        int $page = 1,
        bool $onlyInStock = true,
        array $filter = []
    ): array {
        $query = [
            'sucursal' => $branchCode,
            'pagina' => max(1, $page),
            'agrupar' => 0,
            'orden' => 'relevancia',
        ];

        if ($onlyInStock) {
            $query['stock'] = 1;
        }

        $search = trim(
            (string) ($filter['busqueda'] ?? '')
        );

        $brand = trim(
            (string) ($filter['marca'] ?? '')
        );

        $category = trim(
            (string) ($filter['categoria'] ?? '')
        );

        if (
            $search === ''
            && $brand === ''
            && $category === ''
        ) {
            $brand = trim(
                (string) config(
                    'syscom.default_productos_marca',
                    ''
                )
            );

            $category = trim(
                (string) config(
                    'syscom.default_productos_categoria',
                    ''
                )
            );

            $search = trim(
                (string) config(
                    'syscom.default_productos_busqueda',
                    'a'
                )
            );
        }

        if (
            $search === ''
            && $brand === ''
            && $category === ''
        ) {
            $search = 'a';
        }

        if ($search !== '') {
            $query['busqueda'] = $search;
        } elseif ($brand !== '') {
            $query['marca'] = $brand;
        } else {
            $query['categoria'] = $category;
        }

        $response = $this->request(
            $token,
            '/productos',
            $query
        );

        return is_array($response)
            ? $response
            : [];
    }


    /**
     * Consulta fichas de productos en un solo request.
     *
     * SYSCOM acepta hasta 300 IDs separados por coma y devuelve:
     * - un objeto cuando se solicita un solo ID;
     * - una lista cuando se solicitan varios.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function getProductsByIds(
        string $token,
        array $productIds,
        bool $inventarios = true,
        string $moneda = 'usd'
    ): array {
        $ids = collect($productIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        if (count($ids) > 300) {
            throw new \InvalidArgumentException(
                'SYSCOM permite consultar como máximo 300 IDs por petición.'
            );
        }

        $query = [
            'inventarios' => $inventarios ? 1 : 0,
            'moneda' => trim($moneda) !== '' ? trim($moneda) : 'usd',
        ];

        $response = $this->request(
            $token,
            '/productos/'.implode(',', $ids),
            $query
        );

        if (! is_array($response) || $response === []) {
            return [];
        }

        if (
            isset($response['producto_id'])
            || isset($response['id'])
        ) {
            return [$response];
        }

        if (isset($response['productos']) && is_array($response['productos'])) {
            return array_values(
                array_filter($response['productos'], 'is_array')
            );
        }

        if (array_is_list($response)) {
            return array_values(
                array_filter($response, 'is_array')
            );
        }

        $nestedItems = array_values(
            array_filter($response, 'is_array')
        );

        return $nestedItems;
    }

    /**
     * Detalle de producto.
     *
     * El query sucursal alinea las existencias con la sucursal
     * seleccionada cuando está habilitado en configuración.
     */
    public function getProduct(
        string $token,
        int|string $productId,
        ?string $sucursal = null
    ): array {
        $sucursal = $sucursal !== null
            ? trim($sucursal)
            : '';

        $useQueryFirst = (bool) config(
            'syscom.get_product_with_sucursal_query',
            false
        ) && $sucursal !== '';

        if ($useQueryFirst) {
            $response = $this->fetchProduct(
                $token,
                $productId,
                $sucursal
            );

            $response[
                '__branch_scoped_existencia'
            ] = true;

            return $response;
        }

        $response = $this->fetchProduct(
            $token,
            $productId,
            null
        );

        if ($sucursal === '') {
            return $response;
        }

        $existencia = is_array(
            $response['existencia'] ?? null
        )
            ? $response['existencia']
            : [];

        $branchName = (string) config(
            'syscom.sucursal_nombre',
            'hermosillo'
        );

        $stock = SyscomHermosilloStock::fromProductDetail(
            $existencia,
            $sucursal,
            $branchName,
            (int) ($response['total_existencia'] ?? 0)
        );

        if (
            $stock > 0
            || $existencia !== []
        ) {
            return $response;
        }

        $scoped = $this->fetchProduct(
            $token,
            $productId,
            $sucursal
        );

        if (! is_array($scoped)) {
            return $response;
        }

        $scopedExistence = is_array(
            $scoped['existencia'] ?? null
        )
            ? $scoped['existencia']
            : [];

        if ($scopedExistence === []) {
            return $response;
        }

        $scoped[
            '__branch_scoped_existencia'
        ] = true;

        return $scoped;
    }

    /**
     * Detalle crudo del producto.
     *
     * @return array<string, mixed>
     */
    public function getProductRaw(
        string $token,
        int|string $productId,
        ?string $sucursal = null
    ): array {
        return $this->fetchProduct(
            $token,
            $productId,
            $sucursal
        );
    }

    /**
     * Busca un producto dentro de una sucursal.
     *
     * @return array{
     *     found: bool,
     *     total_existencia: int,
     *     existencia: array<string, mixed>,
     *     item: array<string, mixed>
     * }|null
     */
    public function findProductInBranchSearch(
        string $token,
        string $branchCode,
        int|string $productId,
        string $busqueda,
        int $maxPages = 3
    ): ?array {
        $busqueda = trim($busqueda);
        $branchCode = trim($branchCode);

        if (
            $busqueda === ''
            || $branchCode === ''
        ) {
            return null;
        }

        $productId = (int) $productId;

        for (
            $page = 1;
            $page <= max(1, $maxPages);
            $page++
        ) {
            $result = $this->searchProducts(
                $token,
                $branchCode,
                $page,
                true,
                [
                    'busqueda' => $busqueda,
                ]
            );

            $items = is_array(
                $result['productos'] ?? null
            )
                ? $result['productos']
                : [];

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (
                    (int) (
                        $item['producto_id']
                        ?? 0
                    ) === $productId
                ) {
                    return [
                        'found' => true,
                        'total_existencia' => max(
                            0,
                            (int) (
                                $item['total_existencia']
                                ?? 0
                            )
                        ),
                        'existencia' => is_array(
                            $item['existencia'] ?? null
                        )
                            ? $item['existencia']
                            : [],
                        'item' => $item,
                    ];
                }
            }

            $pages = (int) (
                $result['paginas']
                ?? 1
            );

            if ($page >= $pages) {
                break;
            }
        }

        return [
            'found' => false,
            'total_existencia' => 0,
            'existencia' => [],
            'item' => [],
        ];
    }

    /**
     * Obtiene el stock confiable de un producto en una sucursal.
     */
    public function getBranchStock(
        string $token,
        string $branchCode,
        int|string $productId,
        string $busqueda
    ): int {
        $hit = $this->findProductInBranchSearch(
            $token,
            $branchCode,
            $productId,
            $busqueda
        );

        if (
            $hit === null
            || ! $hit['found']
        ) {
            return 0;
        }

        $branchName = (string) config(
            'syscom.sucursal_nombre',
            'hermosillo'
        );

        $existencia = is_array(
            $hit['existencia'] ?? null
        )
            ? $hit['existencia']
            : [];

        $quantity = SyscomHermosilloStock::forBranch(
            $existencia,
            $branchCode,
            $branchName
        );

        if ($quantity > 0) {
            return $quantity;
        }

        /*
         * Si aparece en la búsqueda con stock=1 pero no viene cantidad
         * detallada, consideramos por lo menos una pieza.
         */
        return 1;
    }

    /**
     * Limpia los bloqueos y cachés de autenticación SYSCOM.
     *
     * Útil después de corregir las credenciales.
     */
    public function clearAuthenticationCache(): void
    {
        $this->runtimeOAuthToken = null;
        $this->runtimeTipoCambio = null;
        $this->oauthDisabled = false;
        $this->oauthDisabledReason = null;

        Cache::forget(
            $this->oauthFailureCacheKey
        );

        Cache::forget(
            $this->oauthTokenCacheKey()
        );
    }

    /**
     * Devuelve si OAuth quedó bloqueado durante este proceso.
     */
    public function isOAuthDisabled(): bool
    {
        return $this->oauthDisabled;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProduct(
        string $token,
        int|string $productId,
        ?string $sucursal
    ): array {
        $query = [];

        if (
            $sucursal !== null
            && trim($sucursal) !== ''
        ) {
            $query['sucursal'] = trim($sucursal);
        }

        $response = $this->request(
            $token,
            '/productos/' . trim((string) $productId),
            $query
        );

        return is_array($response)
            ? $response
            : [];
    }

    /**
     * Ejecuta una petición GET contra SYSCOM.
     */
    protected function request(
        string $token,
        string $path,
        array $query = []
    ): mixed {
        $token = trim($token);

        if ($token === '') {
            throw new \RuntimeException(
                'SYSCOM request cancelado: token vacío.'
            );
        }

        if ($this->oauthDisabled) {
            throw new \RuntimeException(
                $this->oauthDisabledReason
                    ?? 'Autenticación SYSCOM deshabilitada durante este proceso.'
            );
        }

        $url = $this->baseUrl
            . '/'
            . ltrim($path, '/');

        $maxAttempts = max(
            1,
            min(
                3,
                (int) config(
                    'syscom.request_max_attempts',
                    2
                )
            )
        );

        $lastError = '';

        for (
            $attempt = 1;
            $attempt <= $maxAttempts;
            $attempt++
        ) {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->get($url, $query);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();

                Log::warning(
                    'SYSCOM request exception',
                    [
                        'url' => $url,
                        'attempt' => $attempt,
                        'error' => $exception->getMessage(),
                    ]
                );

                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException(
                        'SYSCOM request falló: '
                        . $lastError
                    );
                }

                sleep(min(3, $attempt));

                continue;
            }

            $status = $response->status();

            /*
             * Token inválido o expirado.
             *
             * Se elimina el token cacheado y no se repite la petición
             * con el mismo token.
             */
            if ($status === 401) {
                Cache::forget(
                    $this->oauthTokenCacheKey()
                );

                $this->runtimeOAuthToken = null;

                $body = trim(
                    (string) $response->body()
                );

                $message = 'SYSCOM request falló: 401';

                if ($body !== '') {
                    $message .= ' '
                        . mb_substr(
                            $body,
                            0,
                            500
                        );
                }

                $this->disableOAuthTemporarily(
                    $message
                );

                Log::error(
                    'SYSCOM API token inválido',
                    [
                        'url' => $url,
                        'query' => $query,
                        'body' => mb_substr(
                            $body,
                            0,
                            500
                        ),
                    ]
                );

                throw new \RuntimeException($message);
            }

            if ($status === 429) {
                $wait = min(
                    8,
                    2 * $attempt
                );

                $lastError = '429 '
                    . mb_substr(
                        (string) $response->body(),
                        0,
                        300
                    );

                Log::warning(
                    'SYSCOM GET 429, espera corta',
                    [
                        'url' => $url,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'wait_s' => $wait,
                    ]
                );

                if ($attempt < $maxAttempts) {
                    sleep($wait);
                    continue;
                }

                throw new \RuntimeException(
                    'SYSCOM request limitado por 429: '
                    . $lastError
                );
            }

            if (! $response->successful()) {
                $lastError = $status
                    . ' '
                    . mb_substr(
                        (string) $response->body(),
                        0,
                        500
                    );

                Log::warning(
                    'SYSCOM request failed',
                    [
                        'url' => $url,
                        'query' => $query,
                        'status' => $status,
                    ]
                );

                if (
                    $status >= 500
                    && $attempt < $maxAttempts
                ) {
                    sleep(min(4, 2 * $attempt));

                    continue;
                }

                throw new \RuntimeException(
                    'SYSCOM request failed: '
                    . $lastError
                );
            }

            return $response->json();
        }

        throw new \RuntimeException(
            'SYSCOM request failed tras reintentos: '
            . $lastError
        );
    }

    /**
     * Deshabilita OAuth temporalmente.
     *
     * El bloqueo entre procesos dura pocos minutos para evitar
     * saturar la API cuando las credenciales son inválidas.
     */
    protected function disableOAuthTemporarily(
        string $reason
    ): void {
        $reason = trim($reason);

        if ($reason === '') {
            $reason = 'Autenticación SYSCOM deshabilitada temporalmente.';
        }

        $this->oauthDisabled = true;
        $this->oauthDisabledReason = $reason;

        $minutes = max(
            1,
            min(
                30,
                (int) config(
                    'syscom.oauth_failure_cache_minutes',
                    5
                )
            )
        );

        Cache::put(
            $this->oauthFailureCacheKey,
            $reason,
            now()->addMinutes($minutes)
        );
    }

    /**
     * Genera una clave específica para las credenciales actuales.
     */
    protected function oauthTokenCacheKey(): string
    {
        $identity = hash(
            'sha256',
            $this->oauthUrl
            . '|'
            . $this->clientId
        );

        return 'syscom.oauth.token.'
            . substr($identity, 0, 24);
    }
}
