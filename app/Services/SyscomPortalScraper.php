<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scraper del portal privado de SYSCOM (www.syscom.mx) para obtener el desglose de
 * existencia POR SUCURSAL, que la API pública (developers.syscom.mx) no expone para
 * todas las cuentas (devuelve `existencia.detalle: []` en respuestas /productos/{id}).
 *
 * Endpoint observado:
 *   GET https://www.syscom.mx/api/productos/{id}/existencias
 *
 * Auth: opcional. GET /api/productos/{id}/existencias responde sin login en muchas cuentas;
 * si Cloudflare bloquea el servidor, cargá cookies del navegador (SYSCOM_PORTAL_COOKIES).
 *
 * Respuesta típica:
 *   {
 *     "193139": {
 *       "existencia": {
 *         "nuevo": "500+",
 *         "detalle": {
 *           "nuevo": { "hermosillo": "27", "puebla": "185", ... },
 *           "a": {...}, "b": {...}, "c": {...}, "d": {...}, "e": {...}
 *         }
 *       }
 *     }
 *   }
 */
class SyscomPortalScraper
{
    public function __construct() {}

    public function isEnabled(): bool
    {
        return (bool) config('syscom.portal_scrape_enabled', false);
    }

    /**
     * Cantidad disponible en una sucursal específica (suma de "nuevo" en esa sucursal).
     *
     * @return int|null  null = scrape falló o no hay datos confiables; el caller debe usar fallback.
     *                   0   = scrape OK pero la sucursal no tiene stock.
     *                   N>0 = stock real en esa sucursal.
     */
    public function branchStockNuevo(int|string $productoId, string $branchSlug = 'hermosillo'): ?int
    {
        $payload = $this->fetchExistencias($productoId);
        if ($payload === null) {
            return null;
        }

        $needle = $this->normalizeBranchKey($branchSlug);
        if ($needle === '') {
            return null;
        }

        $key = (string) $productoId;
        $nuevoMap = data_get($payload, "{$key}.existencia.detalle.nuevo");
        if (! is_array($nuevoMap)) {
            return null;
        }

        foreach ($nuevoMap as $branchKey => $value) {
            $k = $this->normalizeBranchKey((string) $branchKey);
            if ($k === '') {
                continue;
            }
            if ($k === $needle || str_contains($k, $needle)) {
                $n = is_numeric($value) ? (int) $value : (int) preg_replace('/\D+/', '', (string) $value);

                return max(0, $n);
            }
        }

        return null;
    }

    /**
     * Detalle por sucursal completo (todas las sucursales con stock "nuevo"), normalizado.
     *
     * @return array<string,int>|null  Mapa branch_slug => qty, o null si no hay datos.
     */
    public function branchBreakdown(int|string $productoId): ?array
    {
        $payload = $this->fetchExistencias($productoId);
        if ($payload === null) {
            return null;
        }

        $key = (string) $productoId;
        $nuevoMap = data_get($payload, "{$key}.existencia.detalle.nuevo");
        if (! is_array($nuevoMap)) {
            return null;
        }

        $out = [];
        foreach ($nuevoMap as $branchKey => $value) {
            $k = $this->normalizeBranchKey((string) $branchKey);
            if ($k === '') {
                continue;
            }
            $n = is_numeric($value) ? (int) $value : (int) preg_replace('/\D+/', '', (string) $value);
            $out[$k] = max(0, $n);
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetchExistencias(int|string $productoId): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $productoId = (int) $productoId;
        if ($productoId <= 0) {
            return null;
        }

        $cacheMinutes = max(0, (int) config('syscom.portal_cache_minutes', 5));
        $cacheKey = 'syscom.portal.existencias.'.$productoId;
        if ($cacheMinutes > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $base = rtrim((string) config('syscom.portal_base_url', 'https://www.syscom.mx'), '/');
        $url = $base.'/api/productos/'.$productoId.'/existencias';

        try {
            $resp = Http::withHeaders($this->headers())
                ->withOptions([
                    'allow_redirects' => true,
                ])
                ->timeout((int) config('syscom.portal_timeout_s', 20))
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('SYSCOM portal scrape: error de red', [
                'producto_id' => $productoId,
                'e' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $resp->successful()) {
            $body = (string) $resp->body();
            $snippet = mb_substr($body, 0, 220);
            Log::warning('SYSCOM portal scrape: HTTP no exitoso', [
                'producto_id' => $productoId,
                'status' => $resp->status(),
                'body_snippet' => $snippet,
            ]);

            return null;
        }

        $json = $resp->json();
        if (! is_array($json)) {
            Log::warning('SYSCOM portal scrape: respuesta no JSON', [
                'producto_id' => $productoId,
            ]);

            return null;
        }

        if ($cacheMinutes > 0) {
            Cache::put($cacheKey, $json, now()->addMinutes($cacheMinutes));
        }

        return $json;
    }

    /**
     * @return array<string,string>
     */
    protected function headers(): array
    {
        $ua = (string) config(
            'syscom.portal_user_agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'
        );

        $headers = [
            'Accept' => '*/*',
            'Accept-Language' => 'es-ES,es;q=0.9',
            'User-Agent' => $ua,
            'Referer' => rtrim((string) config('syscom.portal_base_url', 'https://www.syscom.mx'), '/').'/products',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
        ];

        $cookies = $this->cookies();
        if ($cookies !== '') {
            $headers['Cookie'] = $cookies;
        }

        return $headers;
    }

    protected function cookies(): string
    {
        return trim((string) config('syscom.portal_cookies', ''));
    }

    protected function normalizeBranchKey(string $key): string
    {
        $k = mb_strtolower(trim($key));
        if ($k === '') {
            return '';
        }
        $k = preg_replace('/\s+/', '_', $k) ?? $k;
        $k = strtr($k, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return $k;
    }
}
