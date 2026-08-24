<?php

namespace App\Services\Autopartes\Meli;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoLibreCatalogMetadataClient
{
    private int $requests = 0;

    private int $cacheHits = 0;

    public function __construct(
        private AutomotivePartMeliConfiguration $configuration,
        private AutomotivePartMeliTokenProvider $tokens,
        private AutomotivePartMeliRequestBudget $budget,
        private AutomotivePartMeliErrorSanitizer $sanitizer,
    ) {}

    public function siteCategories(bool $refresh = false): array
    {
        return $this->get($this->endpoint('site_categories', ['site_id' => $this->configuration->siteId()]), [], $refresh);
    }

    public function category(string $categoryId, bool $refresh = false): array
    {
        return $this->get($this->endpoint('category', ['category_id' => $this->categoryId($categoryId)]), [], $refresh);
    }

    public function categoryAttributes(string $categoryId, bool $refresh = false): array
    {
        return $this->get($this->endpoint('category_attributes', ['category_id' => $this->categoryId($categoryId)]), [], $refresh);
    }

    public function discover(string $query, int $limit, bool $refresh = false): array
    {
        return $this->get($this->endpoint('domain_discovery', ['site_id' => $this->configuration->siteId()]), [
            'q' => $query,
            'limit' => max(1, min(8, $limit)),
        ], $refresh);
    }

    public function domain(string $domainId, bool $refresh = false): array
    {
        $domainId = strtoupper(trim($domainId));
        if (! preg_match('/^[A-Z]{3}-[A-Z0-9_]+$/', $domainId)) {
            throw new AutomotivePartMeliException('El domain_id no tiene un formato permitido.', 'invalid_domain_id');
        }

        return $this->get($this->endpoint('domain', ['domain_id' => $domainId]), [], $refresh);
    }

    public function compatibilityRestrictions(string $mainDomainId, string $secondaryDomainId, bool $refresh = false): array
    {
        return $this->get($this->endpoint('compatibility_restrictions'), [
            'main_domain_id' => $mainDomainId,
            'secondary_domain_id' => $secondaryDomainId,
        ], $refresh);
    }

    public function request(string $method, string $path, array $query = [], bool $refresh = false): array
    {
        if (strtoupper($method) !== 'GET') {
            throw new AutomotivePartMeliException('El cliente de metadatos permite exclusivamente solicitudes GET.', 'method_not_allowed');
        }

        return $this->get($path, $query, $refresh);
    }

    public function stats(): array
    {
        return ['requests' => $this->requests, 'cache_hits' => $this->cacheHits, 'daily_remaining' => $this->budget->remaining()];
    }

    private function get(string $path, array $query, bool $refresh): array
    {
        $this->configuration->assertReady();
        $this->assertAllowedPath($path);
        ksort($query);
        $cacheKey = 'autopartes:meli:metadata:'.hash('sha256', $path.'?'.http_build_query($query));

        if (! $refresh && Cache::has($cacheKey)) {
            $this->cacheHits++;

            return array_merge((array) Cache::get($cacheKey), ['from_cache' => true]);
        }

        $response = $this->sendWithRetries($path, $query);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new AutomotivePartMeliException('Mercado Libre devolvió metadatos JSON inválidos.', 'invalid_json');
        }

        $result = [
            'payload' => $payload,
            'request_id' => $this->requestId($response),
            'from_cache' => false,
        ];
        Cache::put($cacheKey, $result, max(1, (int) config('autopartes_meli.cache_ttl', 86400)));

        return $result;
    }

    private function sendWithRetries(string $path, array $query): Response
    {
        $token = $this->tokens->token();
        $lastException = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->budget->consume();
            $this->requests++;

            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->withUserAgent('AutopartesMeliMapping/1.0')
                    ->timeout(max(1, (int) config('autopartes_meli.timeout', 20)))
                    ->get(rtrim((string) config('autopartes_meli.base_url'), '/').$path, $query);
            } catch (ConnectionException $exception) {
                $lastException = new AutomotivePartMeliException(
                    $this->sanitizer->sanitize('Error de conexión con Mercado Libre: '.$exception->getMessage()),
                    'connection_error',
                    true,
                    previous: $exception,
                );

                if ($attempt < 3) {
                    $this->delay($attempt, null);

                    continue;
                }

                throw $lastException;
            }

            if ($response->successful()) {
                Log::info('Automotive part Mercado Libre metadata request completed.', [
                    'path' => $path,
                    'status' => $response->status(),
                    'request_id' => $this->requestId($response),
                ]);

                return $response;
            }

            $lastException = $this->responseException($response);
            if (! $lastException->transient || $attempt === 3) {
                Log::warning('Automotive part Mercado Libre metadata request failed.', [
                    'path' => $path,
                    'status' => $response->status(),
                    'request_id' => $this->requestId($response),
                    'error_code' => $lastException->errorCode,
                    'transient' => $lastException->transient,
                ]);
                throw $lastException;
            }

            $this->delay($attempt, $lastException->retryAfter);
        }

        throw $lastException ?? new AutomotivePartMeliException('Error desconocido consultando metadatos.', 'unknown_error');
    }

    private function assertAllowedPath(string $path): void
    {
        $path = '/'.ltrim($path, '/');
        $blocked = preg_match('#/(items?|orders?|messages?|questions?|stock|prices?)(/|$)#i', $path) === 1;
        $allowed = preg_match('#^/sites/[A-Z]{3}/categories$#', $path) === 1
            || preg_match('#^/sites/[A-Z]{3}/domain_discovery/search$#', $path) === 1
            || preg_match('#^/categories/[A-Z]{3}\d+$#', $path) === 1
            || preg_match('#^/categories/[A-Z]{3}\d+/attributes$#', $path) === 1
            || preg_match('#^/catalog_domains/[A-Z]{3}-[A-Z0-9_]+$#', $path) === 1
            || $path === '/catalog_compatibilities/restrictions/values';

        if ($blocked || ! $allowed) {
            throw new AutomotivePartMeliException('El path solicitado no pertenece a los metadatos permitidos.', 'path_not_allowed');
        }
    }

    private function responseException(Response $response): AutomotivePartMeliException
    {
        $status = $response->status();
        $code = $response->json('error') ?: $response->json('code') ?: 'http_'.$status;
        $message = $response->json('message');
        $message = is_string($message) ? $message : "Mercado Libre respondió HTTP {$status}.";
        $transient = $status === 408 || $status === 429 || $status >= 500;

        return new AutomotivePartMeliException(
            $this->sanitizer->sanitize($message),
            is_string($code) ? $code : 'http_'.$status,
            $transient,
            $transient ? $this->retryAfter($response->header('Retry-After')) : null,
        );
    }

    private function delay(int $attempt, ?int $retryAfter): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $milliseconds = ($retryAfter ?? min(30, 2 ** $attempt)) * 1000 + random_int(0, 500);
        usleep($milliseconds * 1000);
    }

    private function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (ctype_digit(trim($value))) {
            return max(1, min(3600, (int) $value));
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(1, min(3600, $timestamp - time()));
    }

    private function requestId(Response $response): ?string
    {
        return $response->header('x-request-id') ?: $response->header('x-correlation-id');
    }

    private function categoryId(string $categoryId): string
    {
        $categoryId = strtoupper(trim($categoryId));
        if (! preg_match('/^MLM\d+$/', $categoryId)) {
            throw new AutomotivePartMeliException('El category_id debe usar el formato MLM seguido de dígitos.', 'invalid_category_id');
        }

        return $categoryId;
    }

    private function endpoint(string $name, array $parameters = []): string
    {
        $path = config("autopartes_meli.paths.{$name}");
        if (! is_string($path) || ! str_starts_with($path, '/')) {
            throw new AutomotivePartMeliException("El path configurado {$name} no es valido.", 'invalid_metadata_path');
        }

        foreach ($parameters as $key => $value) {
            $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new AutomotivePartMeliException("El path configurado {$name} esta incompleto.", 'invalid_metadata_path');
        }

        return $path;
    }
}
