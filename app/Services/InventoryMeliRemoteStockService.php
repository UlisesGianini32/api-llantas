<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\MeliAccount;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use Throwable;

class InventoryMeliRemoteStockService
{
    public const OK = 'OK';

    public const REMOTE_VARIATION_NOT_FOUND = 'REMOTE_VARIATION_NOT_FOUND';

    public const MALFORMED_RESPONSE = 'MALFORMED_RESPONSE';

    public const PREFLIGHT_FAILED = 'PREFLIGHT_FAILED';

    public const INVALID_ACCOUNT = 'INVALID_ACCOUNT';

    public const INVALID_EXTERNAL_ID = 'INVALID_EXTERNAL_ID';

    public function __construct(private readonly MeliAccountApiClient $api) {}

    /** @return array{status:string,quantity:?int,http_status:?int,error:?string} */
    public function read(InventoryChannelLink $link, ?MeliAccount $account = null): array
    {
        $account ??= ctype_digit((string) $link->account_key)
            ? MeliAccount::query()->find((int) $link->account_key)
            : null;
        if (! $account) {
            return $this->failure(self::INVALID_ACCOUNT);
        }
        if (blank($link->external_listing_id)) {
            return $this->failure(self::INVALID_EXTERNAL_ID);
        }

        try {
            $this->api->ensureFreshAccessToken($account);
            $response = $this->api->request(
                $account,
                'get',
                '/items/'.rawurlencode((string) $link->external_listing_id),
            );
            $item = $response->json();
            if (! is_array($item)) {
                return $this->failure(self::MALFORMED_RESPONSE, $response->status());
            }

            if (filled($link->external_variant_id)) {
                foreach ((array) ($item['variations'] ?? []) as $variation) {
                    if (! is_array($variation) || (string) ($variation['id'] ?? '') !== (string) $link->external_variant_id) {
                        continue;
                    }
                    if (! is_numeric($variation['available_quantity'] ?? null)) {
                        return $this->failure(self::MALFORMED_RESPONSE, $response->status());
                    }

                    return [
                        'status' => self::OK,
                        'quantity' => (int) $variation['available_quantity'],
                        'http_status' => $response->status(),
                        'error' => null,
                    ];
                }

                return $this->failure(self::REMOTE_VARIATION_NOT_FOUND, $response->status());
            }

            if (! is_numeric($item['available_quantity'] ?? null)) {
                return $this->failure(self::MALFORMED_RESPONSE, $response->status());
            }

            return [
                'status' => self::OK,
                'quantity' => (int) $item['available_quantity'],
                'http_status' => $response->status(),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $status = $exception instanceof MeliApiRequestException ? $exception->httpStatus() : null;

            return $this->failure(self::PREFLIGHT_FAILED, $status, $this->safeError($exception->getMessage()));
        }
    }

    /** @return array{status:string,quantity:?int,http_status:?int,error:?string} */
    private function failure(string $status, ?int $httpStatus = null, ?string $error = null): array
    {
        return [
            'status' => $status,
            'quantity' => null,
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }

    private function safeError(string $message): string
    {
        $sanitized = preg_replace([
            '/\b(?:authorization|access_token|refresh_token|client_secret)\b\s*[:=]\s*(?:Bearer\s+)?(?:\[[^\]]*\]|[^\s,;]+)/i',
            '/\bBearer\s+(?:\[[^\]]*\]|[^\s,;]+)/i',
            '/\b(?:authorization|access_token|refresh_token|client_secret|bearer)\b/i',
        ], ['[REDACTED]', '[REDACTED]', ''], $message) ?? 'Error de prelectura.';

        return trim((string) preg_replace('/\s{2,}/', ' ', $sanitized));
    }
}
