<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliAccount;
use App\Models\MeliClaim;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Http\Client\Response;

class MeliClaimResolutionService
{
    private const BASE = '/post-purchase/v1/claims';

    public function __construct(private readonly MeliAccountApiClient $api) {}

    public function ensureFreshToken(MeliAccount $account): void
    {
        $this->api->ensureFreshAccessToken($account);
    }

    public function preflight(MeliAccount $account, MeliClaim $claim): array
    {
        $response = $this->api->getReadOnly(
            $account,
            self::BASE.'/'.rawurlencode($claim->claim_id),
            [],
            1,
        );

        return is_array($response->json()) ? $response->json() : [];
    }

    public function persistPreflight(MeliClaim $claim, array $rawClaim): void
    {
        $sanitized = $rawClaim;
        foreach (['buyer', 'complainant', 'shipping_address', 'receiver_address'] as $key) {
            unset($sanitized[$key]);
        }
        foreach ((array) ($sanitized['players'] ?? []) as $index => $player) {
            if (! is_array($player)) continue;
            unset($player['user_id'], $player['id'], $player['email'], $player['nickname']);
            $sanitized['players'][$index] = $player;
        }

        $claim->forceFill(array_filter([
            'raw_claim' => $sanitized,
            'available_actions' => $this->sellerActions($rawClaim),
            'status' => filled($rawClaim['status'] ?? null) ? (string) $rawClaim['status'] : null,
            'stage' => filled($rawClaim['stage'] ?? null) ? (string) $rawClaim['stage'] : null,
            'last_updated' => filled($rawClaim['last_updated'] ?? null) ? $rawClaim['last_updated'] : null,
            'last_synced_at' => now(),
            'sync_error' => null,
        ], fn (mixed $value): bool => $value !== null))->save();
    }

    /** @return list<array{percentage:float|int,amount:float|null,currency_id:string|null}> */
    public function partialRefundOffers(MeliAccount $account, MeliClaim $claim): array
    {
        $response = $this->api->getReadOnly(
            $account,
            self::BASE.'/'.rawurlencode($claim->claim_id).'/partial-refund/available-offers',
            [],
            1,
        );
        $payload = $response->json();
        $globalCurrency = is_array($payload) ? ($payload['currency_id'] ?? null) : null;
        $items = is_array($payload) && array_is_list($payload)
            ? $payload
            : (array) data_get($payload, 'available_offers', data_get($payload, 'data', data_get($payload, 'offers', [])));

        return collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(function (array $offer) use ($globalCurrency): ?array {
            $percentage = $offer['percentage'] ?? null;
            if (! is_numeric($percentage) || (float) $percentage >= 100) return null;

            $amount = is_array($offer['amount'] ?? null) ? data_get($offer, 'amount.value') : ($offer['amount'] ?? null);
            $currency = $offer['currency_id'] ?? data_get($offer, 'amount.currency_id') ?? data_get($offer, 'amount.currency') ?? $globalCurrency;

            return [
                'percentage' => (float) $percentage,
                'amount' => is_numeric($amount) ? (float) $amount : null,
                'currency_id' => filled($currency) ? (string) $currency : null,
            ];
        })->filter()->values()->all();
    }

    public function execute(MeliAccount $account, MeliClaim $claim, string $action, ?float $percentage = null): Response
    {
        $suffix = match ($action) {
            'refund' => '/expected-resolutions/refund',
            'allow_return' => '/expected-resolutions/allow-return',
            'partial_refund' => '/expected-resolutions/partial-refund',
            default => throw new \InvalidArgumentException('Resolución económica no soportada.'),
        };
        $payload = $action === 'partial_refund' ? ['percentage' => $percentage] : [];

        return $this->api->request(
            $account,
            'post',
            self::BASE.'/'.rawurlencode($claim->claim_id).$suffix,
            $payload,
            refreshAfterUnauthorized: false,
            maxAttempts: 1,
        );
    }

    private function sellerActions(array $rawClaim): array
    {
        return collect((array) ($rawClaim['players'] ?? []))
            ->filter(fn (mixed $player): bool => is_array($player)
                && (($player['role'] ?? null) === 'respondent' || ($player['type'] ?? null) === 'seller'))
            ->flatMap(fn (array $player): array => (array) ($player['available_actions'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))->values()->all();
    }
}
