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

    /** Token OAuth obtenido en este mismo proceso (evita repetir /oauth/token). */
    protected ?string $runtimeOAuthToken = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.syscom.base_url', 'https://developers.syscom.mx/api/v1'), '/');
        $this->oauthUrl = (string) config('services.syscom.oauth_url', 'https://developers.syscom.mx/oauth/token');
        $this->clientId = (string) config('services.syscom.client_id', '');
        $this->clientSecret = (string) config('services.syscom.client_secret', '');
    }

    public function getAccessToken(): string
    {
        $token = trim((string) config('services.syscom.access_token', ''));
        if ($token !== '') {
            return $token;
        }

        if ($this->runtimeOAuthToken !== null && $this->runtimeOAuthToken !== '') {
            return $this->runtimeOAuthToken;
        }

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('Faltan credenciales de SYSCOM (SYSCOM_CLIENT_ID/SYSCOM_CLIENT_SECRET).');
        }

        $maxAttempts = max(1, (int) config('syscom.oauth_max_attempts', 6));
        $lastError = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $resp = Http::asForm()
                ->timeout(45)
                ->post($this->oauthUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);

            if ($resp->status() === 429) {
                $wait = min(120, (int) (5 * (2 ** ($attempt - 1))));
                Log::warning('SYSCOM oauth 429, reintento', ['attempt' => $attempt, 'wait_s' => $wait]);
                sleep($wait);
                $lastError = '429 too many requests (oauth)';

                continue;
            }

            if (! $resp->successful()) {
                $lastError = (string) $resp->status().' '.$resp->body();
                $code = $resp->status();
                if ($attempt < $maxAttempts && $code >= 500) {
                    sleep(min(30, 2 * $attempt));
                    continue;
                }
                throw new \RuntimeException('No se pudo autenticar con SYSCOM: '.$lastError);
            }

            $value = trim((string) ($resp->json('access_token') ?? ''));
            if ($value === '') {
                throw new \RuntimeException('SYSCOM no devolvio access_token en oauth/token.');
            }
            $this->runtimeOAuthToken = $value;

            return $value;
        }

        throw new \RuntimeException('No se pudo autenticar con SYSCOM (429 o varios fallos). '.$lastError);
    }

    public function getBranches(string $token): array
    {
        $response = $this->request($token, '/carrito/sucursales');

        return is_array($response) ? $response : [];
    }

    /**
     * Categorías nivel 1 (GET /categorias).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(string $token): array
    {
        $response = $this->request($token, '/categorias');

        return is_array($response) ? $response : [];
    }

    /**
     * Detalle de categoría con subcategorías (GET /categorias/{id}).
     *
     * @return array<string, mixed>
     */
    public function getCategory(string $token, int|string $id): array
    {
        $response = $this->request($token, '/categorias/'.trim((string) $id));

        return is_array($response) ? $response : [];
    }

    /**
     * Tipo de cambio SYSCOM (USD/MXN normal y especial preferencial).
     * Endpoint: /tipocambio
     *
     * @return array<string, mixed>
     */
    public function getTipoCambio(string $token): array
    {
        $response = $this->request($token, '/tipocambio');
        return is_array($response) ? $response : [];
    }

    /**
     * Tipo de cambio MXN/USD que aplica SYSCOM, cacheado.
     *
     * Lee `tc_kind` de config/syscom (preferencial, normal, un_dia, etc.).
     * Si la API falla y no hay valor cacheado, usa `tc_fallback`.
     */
    public function getTipoCambioMxn(): float
    {
        $kind = (string) config('syscom.tc_kind', 'preferencial');
        $minutes = max(1, (int) config('syscom.tc_cache_minutes', 60));
        $fallback = (float) config('syscom.tc_fallback', 17.5);

        $cacheKey = "syscom.tc.{$kind}";

        $cached = Cache::get($cacheKey);
        if (is_numeric($cached) && (float) $cached > 0) {
            return (float) $cached;
        }

        try {
            $token = $this->getAccessToken();
            $tc = $this->getTipoCambio($token);
        } catch (\Throwable $e) {
            Log::warning('SYSCOM tipo_cambio fallo, usando fallback', [
                'error' => $e->getMessage(),
                'fallback' => $fallback,
            ]);

            return $fallback;
        }

        $value = $tc[$kind] ?? null;
        if (! is_numeric($value) || (float) $value <= 0) {
            $value = $tc['preferencial'] ?? $tc['normal'] ?? null;
        }
        if (! is_numeric($value) || (float) $value <= 0) {
            return $fallback;
        }

        $value = (float) $value;
        Cache::put($cacheKey, $value, now()->addMinutes($minutes));

        return $value;
    }

    public function resolveBranchCodeByName(string $token, string $branchName): ?string
    {
        $needle = mb_strtolower(trim($branchName));
        if ($needle === '') {
            return null;
        }

        foreach ($this->getBranches($token) as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $name = mb_strtolower(trim((string) ($branch['nombre_sucursal'] ?? '')));
            $code = trim((string) ($branch['codigo'] ?? ''));

            if ($code === '' || $name === '') {
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
     * La API exige al menos uno: busqueda, marca o categoria (422 si faltan los tres).
     *
     * @param  array{busqueda?:string,marca?:string,categoria?:string}  $filter
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

        $busq = trim((string) ($filter['busqueda'] ?? ''));
        $marca = trim((string) ($filter['marca'] ?? ''));
        $categoria = trim((string) ($filter['categoria'] ?? ''));

        if ($busq === '' && $marca === '' && $categoria === '') {
            $marca = trim((string) config('syscom.default_productos_marca', ''));
            $categoria = trim((string) config('syscom.default_productos_categoria', ''));
            $busq = trim((string) config('syscom.default_productos_busqueda', 'a'));
        }

        if ($busq === '' && $marca === '' && $categoria === '') {
            $busq = 'a';
        }

        if ($busq !== '') {
            $query['busqueda'] = $busq;
        } elseif ($marca !== '') {
            $query['marca'] = $marca;
        } else {
            $query['categoria'] = $categoria;
        }

        $response = $this->request($token, '/productos', $query);

        return is_array($response) ? $response : [];
    }

    /**
     * Detalle de producto. El query `sucursal` (código) alinea existencias a la misma
     * sucursal que usás en búsqueda; la API a veces devuelve `existencia` en distinto
     * formato que sin filtro.
     */
    public function getProduct(string $token, int|string $productId, ?string $sucursal = null): array
    {
        $sucursal = $sucursal !== null ? trim($sucursal) : '';
        // Los códigos de sucursal de SYSCOM pueden ser slugs ("hermosillo", "culiacan"), no solo
        // números. Si hay código y el config lo pide, filtramos el detalle por esa sucursal.
        $useQueryFirst = (bool) config('syscom.get_product_with_sucursal_query', false) && $sucursal !== '';

        if ($useQueryFirst) {
            $response = $this->fetchProduct($token, $productId, $sucursal);
            $response['__branch_scoped_existencia'] = true;

            return $response;
        }

        $response = $this->fetchProduct($token, $productId, null);
        if ($sucursal === '') {
            return $response;
        }

        $existencia = is_array($response['existencia'] ?? null) ? $response['existencia'] : [];
        $branchName = (string) config('syscom.sucursal_nombre', 'hermosillo');
        $stock = SyscomHermosilloStock::fromProductDetail(
            $existencia,
            $sucursal,
            $branchName,
            (int) ($response['total_existencia'] ?? 0)
        );

        if ($stock > 0 || $existencia !== []) {
            return $response;
        }

        $scoped = $this->fetchProduct($token, $productId, $sucursal);
        if (! is_array($scoped)) {
            return $response;
        }

        $scopedExist = is_array($scoped['existencia'] ?? null) ? $scoped['existencia'] : [];
        if ($scopedExist === []) {
            return $response;
        }

        $scoped['__branch_scoped_existencia'] = true;

        return $scoped;
    }

    /**
     * Detalle crudo del producto, opcionalmente filtrado por sucursal (para diagnóstico).
     *
     * @return array<string, mixed>
     */
    public function getProductRaw(string $token, int|string $productId, ?string $sucursal = null): array
    {
        return $this->fetchProduct($token, $productId, $sucursal);
    }

    /**
     * Stock de un producto en una sucursal vía el buscador filtrado (sucursal=X&stock=1),
     * que es como SYSCOM realmente separa por sucursal (el detalle solo da nacional).
     *
     * @return array{found: bool, total_existencia: int, existencia: array<string, mixed>, item: array<string, mixed>}|null
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
        if ($busqueda === '' || $branchCode === '') {
            return null;
        }

        $productId = (int) $productId;
        for ($page = 1; $page <= max(1, $maxPages); $page++) {
            $res = $this->searchProducts($token, $branchCode, $page, true, ['busqueda' => $busqueda]);
            $items = is_array($res['productos'] ?? null) ? $res['productos'] : [];
            if ($items === []) {
                break;
            }

            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                if ((int) ($it['producto_id'] ?? 0) === $productId) {
                    return [
                        'found' => true,
                        'total_existencia' => max(0, (int) ($it['total_existencia'] ?? 0)),
                        'existencia' => is_array($it['existencia'] ?? null) ? $it['existencia'] : [],
                        'item' => $it,
                    ];
                }
            }

            $pages = (int) ($res['paginas'] ?? 1);
            if ($page >= $pages) {
                break;
            }
        }

        return ['found' => false, 'total_existencia' => 0, 'existencia' => [], 'item' => []];
    }

    /**
     * Stock confiable de un producto en una sucursal (vía buscador filtrado).
     * 0 = no hay stock en esa sucursal (no aparece en sucursal=X&stock=1).
     * El `total_existencia` que devuelve la API en el item es NACIONAL (suma de todas
     * las sucursales). El desglose real por sucursal está en `existencia`; ahí se busca
     * la cantidad de Hermosillo. Si la API no detalla cantidad por sucursal pero el
     * producto sí aparece en sucursal=X&stock=1, devolvemos al menos 1.
     */
    public function getBranchStock(string $token, string $branchCode, int|string $productId, string $busqueda): int
    {
        $hit = $this->findProductInBranchSearch($token, $branchCode, $productId, $busqueda);
        if ($hit === null || ! $hit['found']) {
            return 0;
        }

        $branchName = (string) config('syscom.sucursal_nombre', 'hermosillo');
        $existencia = is_array($hit['existencia'] ?? null) ? $hit['existencia'] : [];

        $qty = SyscomHermosilloStock::forBranch($existencia, $branchCode, $branchName);
        if ($qty > 0) {
            return $qty;
        }

        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProduct(string $token, int|string $productId, ?string $sucursal): array
    {
        $query = [];
        if ($sucursal !== null && trim($sucursal) !== '') {
            $query['sucursal'] = trim($sucursal);
        }
        $response = $this->request($token, '/productos/'.trim((string) $productId), $query);

        return is_array($response) ? $response : [];
    }

    protected function request(string $token, string $path, array $query = []): mixed
    {
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $max = max(1, (int) config('syscom.request_max_attempts', 5));
        $last = '';

        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $resp = Http::withToken($token)
                ->acceptJson()
                ->timeout(45)
                ->get($url, $query);

            if ($resp->status() === 429) {
                $wait = min(90, (int) (3 * (2 ** ($attempt - 1))));
                Log::warning('SYSCOM GET 429, esperando', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'wait_s' => $wait,
                ]);
                sleep($wait);
                $last = (string) $resp->body();

                continue;
            }

            if (! $resp->successful()) {
                $last = $resp->status().' '.$resp->body();
                Log::warning('SYSCOM request failed', [
                    'url' => $url,
                    'query' => $query,
                    'status' => $resp->status(),
                ]);
                if ($resp->status() >= 500 && $attempt < $max) {
                    sleep(2 * $attempt);
                    continue;
                }
                throw new \RuntimeException('SYSCOM request failed: '.$last);
            }

            return $resp->json();
        }

        throw new \RuntimeException('SYSCOM request failed tras reintentos: '.$last);
    }
}
