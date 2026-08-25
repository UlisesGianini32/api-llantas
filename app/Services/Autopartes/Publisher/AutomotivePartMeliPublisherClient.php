<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\MeliAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AutomotivePartMeliPublisherClient
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartMeliPublisherTokenProvider $tokens,
        private AutomotivePartMeliPublisherSanitizer $sanitizer,
    ) {}

    public function request(string $method, string $path, MeliAccount $account, array $payload = [], ?array $file = null): array
    {
        $method = strtoupper($method);
        $this->assertAllowed($method, $path);
        $safe = $method === 'GET' || ($method === 'POST' && $path === '/items/validate');
        $attempts = 0;

        do {
            $attempts++;
            try {
                $pending = Http::withToken($this->tokens->token($account))
                    ->acceptJson()
                    ->timeout($this->configuration->timeout());
                if ($file !== null) {
                    $pending = $pending->attach('file', $file['contents'], $file['name'], ['Content-Type' => $file['mime']]);
                }
                $options = $file !== null ? ['multipart' => []] : ($method === 'GET' ? [] : ['json' => $payload]);
                $response = $pending->send($method, $this->configuration->baseUrl().$path, $options);
            } catch (ConnectionException $exception) {
                throw new AutomotivePartMeliPublisherException(
                    'No se pudo confirmar la respuesta de Mercado Libre.',
                    'connection_error',
                    true,
                    $method === 'POST' && $path === '/items',
                    null,
                    null,
                    null,
                    [],
                    $exception,
                );
            }

            if ($safe && $response->status() === 429 && $attempts < 2) {
                $retryAfter = min(2, max(0, (int) $response->header('Retry-After', 0)));
                if ($retryAfter > 0) sleep($retryAfter);
                continue;
            }
            break;
        } while (true);

        return $this->result($response);
    }

    public function uploadPicture(MeliAccount $account, string $contents, string $filename, string $mime): array
    {
        return $this->request('POST', '/pictures/items/upload', $account, [], compact('contents', 'mime') + ['name' => $filename]);
    }

    public function validateItem(MeliAccount $account, array $payload): array { return $this->request('POST', '/items/validate', $account, $payload); }
    public function createItem(MeliAccount $account, array $payload): array { return $this->request('POST', '/items', $account, $payload); }
    public function createDescription(MeliAccount $account, string $itemId, array $payload): array { return $this->request('POST', "/items/{$itemId}/description", $account, $payload); }
    public function getItem(MeliAccount $account, string $itemId): array { return $this->request('GET', "/items/{$itemId}", $account); }
    public function getDescription(MeliAccount $account, string $itemId): array { return $this->request('GET', "/items/{$itemId}/description", $account); }

    private function assertAllowed(string $method, string $path): void
    {
        $exact = $method === 'POST' && in_array($path, ['/pictures/items/upload', '/items/validate', '/items'], true);
        $item = preg_match('#^/items/MLM[0-9]+(?:/description)?$#', $path) === 1;
        $itemAllowed = $item && (($method === 'GET') || ($method === 'POST' && str_ends_with($path, '/description')));
        if (! $exact && ! $itemAllowed) {
            throw new AutomotivePartMeliPublisherException("Operación Mercado Libre no permitida: {$method} {$path}.", 'endpoint_not_allowed');
        }
    }

    private function result(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : ($response->body() === '' ? [] : ['body' => $response->body()]);
        $result = ['status' => $response->status(), 'json' => $this->sanitizer->array($json),
            'request_id' => $response->header('X-Request-Id') ?? $response->header('x-request-id')];
        if ($response->successful()) return $result;

        $status = $response->status();
        $code = match ($status) { 400 => 'meli_validation_error', 401 => 'meli_unauthorized', 403 => 'meli_forbidden',
            429 => 'meli_rate_limited', default => $status >= 500 ? 'meli_server_error' : 'meli_http_error' };
        $message = (string) ($json['message'] ?? $json['error'] ?? "Mercado Libre respondió HTTP {$status}.");
        throw new AutomotivePartMeliPublisherException($this->sanitizer->message($message) ?? 'Error remoto.', $code,
            $status === 429 || $status >= 500, false, $status, $result['request_id'],
            $status === 429 ? max(0, (int) $response->header('Retry-After', 0)) : null, $result['json']);
    }
}
