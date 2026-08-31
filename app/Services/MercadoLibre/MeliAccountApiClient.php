<?php

namespace App\Services\MercadoLibre;

use App\Models\MeliAccount;
use App\Services\MeliOAuthService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

class MeliAccountApiClient
{
    private const API_BASE_URL = 'https://api.mercadolibre.com';

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly MeliOAuthService $oauth) {}

    public function ensureFreshAccessToken(MeliAccount $account, bool $force = false): void
    {
        $usable = filled($account->access_token)
            && ($account->expires_at === null || $account->expires_at->greaterThan(now()->addMinutes(5)));

        if (! $force && $usable) {
            return;
        }

        if (! filled($account->refresh_token)) {
            if (filled($account->access_token)) {
                return;
            }

            throw new RuntimeException('La cuenta no tiene access_token ni refresh_token.');
        }

        $clientId = (string) config('services.meli.client_id', config('services.meli.app_id', ''));
        $clientSecret = (string) config('services.meli.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Faltan MELI_CLIENT_ID/MELI_APP_ID o MELI_CLIENT_SECRET.');
        }

        $data = $this->oauth->refreshAccessToken(
            $clientId,
            $clientSecret,
            (string) $account->refresh_token,
        );

        $account->forceFill([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 21600))->subMinutes(2),
        ])->save();

        $account->refresh();
        $account->user?->syncMeliColumnsFromDefaultAccount();
    }

    /** @param array<string, mixed> $payload */
    public function request(
        MeliAccount $account,
        string $method,
        string $path,
        array $payload = [],
        bool $refreshAfterUnauthorized = true,
        array $headers = [],
        int $maxAttempts = self::MAX_ATTEMPTS,
    ): Response
    {
        $lastResponse = null;
        $lastException = null;
        $refreshedAfterUnauthorized = false;

        $maxAttempts = max(1, min(self::MAX_ATTEMPTS, $maxAttempts));
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $client = Http::withToken((string) $account->access_token)
                    ->acceptJson()
                    ->withHeaders($headers)
                    ->timeout(60);

                $url = self::API_BASE_URL.'/'.ltrim($path, '/');
                $response = match (strtolower($method)) {
                    'post' => $client->post($url, $payload),
                    'put' => $client->put($url, $payload),
                    'delete' => $client->delete($url, $payload),
                    default => $client->get($url, $payload),
                };
                $lastResponse = $response;
                $lastException = null;
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                if ($attempt < $maxAttempts) {
                    Sleep::sleep($this->backoffSeconds($attempt));

                    continue;
                }

                break;
            }

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 401 && $refreshAfterUnauthorized && ! $refreshedAfterUnauthorized) {
                $this->ensureFreshAccessToken($account, true);
                $refreshedAfterUnauthorized = true;

                continue;
            }

            if (($response->status() === 429 || $response->serverError()) && $attempt < $maxAttempts) {
                Sleep::sleep($this->retryDelaySeconds($response, $attempt));

                continue;
            }

            break;
        }

        $status = $lastResponse?->status() ?? 0;
        $message = $lastResponse === null
            ? 'No fue posible conectar con Mercado Libre.'
            : $this->responseMessage($lastResponse);

        throw new MeliApiRequestException(
            "Mercado Libre HTTP {$status}: {$message}",
            $status,
            $lastException,
        );
    }

    /** @param array<string, mixed> $query */
    public function getReadOnly(MeliAccount $account, string $path, array $query = [], int $maxAttempts = self::MAX_ATTEMPTS): Response
    {
        return $this->request(
            $account,
            'get',
            $path,
            $query,
            refreshAfterUnauthorized: false,
            maxAttempts: $maxAttempts,
        );
    }

    public function postMultipartOnce(MeliAccount $account, string $path, string $field, mixed $contents, string $filename): Response
    {
        try {
            $response = Http::withToken((string) $account->access_token)
                ->acceptJson()
                ->timeout(60)
                ->attach($field, $contents, $filename)
                ->post(self::API_BASE_URL.'/'.ltrim($path, '/'));
        } catch (ConnectionException $exception) {
            throw new MeliApiRequestException(
                'Mercado Libre HTTP 0: No fue posible confirmar la carga del archivo.',
                0,
                $exception
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw new MeliApiRequestException(
            "Mercado Libre HTTP {$response->status()}: ".$this->responseMessage($response),
            $response->status(),
        );
    }

    private function retryDelaySeconds(Response $response, int $attempt): int
    {
        if ($response->status() === 429) {
            $retryAfter = trim((string) $response->header('Retry-After'));

            if (ctype_digit($retryAfter)) {
                return min(60, max(0, (int) $retryAfter));
            }

            $retryAt = $retryAfter !== '' ? strtotime($retryAfter) : false;
            if ($retryAt !== false) {
                return min(60, max(0, $retryAt - time()));
            }
        }

        return $this->backoffSeconds($attempt);
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(8, 2 ** max(0, $attempt - 1));
    }

    private function responseMessage(Response $response): string
    {
        $data = $response->json();
        $message = is_array($data)
            ? ($data['message'] ?? $data['error_description'] ?? $data['error'] ?? null)
            : null;

        return $this->sanitizeMessage((string) ($message ?? $response->body() ?: 'Sin respuesta'));
    }

    public function sanitizeMessage(string $message): string
    {
        $sanitized = preg_replace([
            '/Bearer\s+[A-Za-z0-9._~-]+/i',
            '/\b(access_token|refresh_token|client_secret|authorization)\b\s*[=:]\s*[^\s,;]+/i',
            '/\bAPP_USR-[A-Za-z0-9_-]+\b/',
        ], [
            'Bearer [REDACTED]',
            '$1=[REDACTED]',
            '[REDACTED]',
        ], $message) ?? 'Error sanitizado';

        return Str::limit($sanitized, 1000, '...');
    }
}
