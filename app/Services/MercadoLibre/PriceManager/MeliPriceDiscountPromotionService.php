<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class MeliPriceDiscountPromotionService
{
    private const PROMOTION_TYPE = 'PRICE_DISCOUNT';

    private const RESTORE_CONFIRMATION_ATTEMPTS = 5;

    private const RESTORE_CONFIRMATION_DELAY_MS = 500;

    public function __construct(
        private readonly MeliAccountApiClient $api,
        private readonly MeliBeautyPromotionWindow $window,
    ) {}

    /**
     * @return array{
     *     standard_base: float,
     *     sale_amount: float|null,
     *     sale_regular_amount: float|null,
     *     sale_metadata: array<string, mixed>,
     *     promotion_original: float|null,
     *     allowed_min: float|null,
     *     allowed_max: float|null,
     *     suggested: float|null,
     *     promotion_status: string|null,
     *     promotion_price: float|null
     * }
     */
    public function snapshot(MeliAccount $account, MeliPriceManagerItem $item): array
    {
        $itemId = rawurlencode((string) $item->meli_item_id);
        $pricesPayload = $this->api->request(
            $account,
            'get',
            "/items/{$itemId}/prices",
            ['display_version' => 'true'],
        )->json();
        $salePayload = $this->api->request(
            $account,
            'get',
            "/items/{$itemId}/sale_price",
            ['context' => 'channel_marketplace'],
        )->json();
        $promotionsPayload = $this->api->request(
            $account,
            'get',
            "/seller-promotions/items/{$itemId}",
            ['app_version' => 'v2'],
        )->json();

        $prices = $this->prices($pricesPayload);
        $standard = $this->selectMarketplacePrice($prices, 'standard');
        if ($standard === null || ! is_numeric($standard['amount'] ?? null)) {
            throw new MeliPriceUpdateException(
                'No fue posible determinar de forma inequívoca el precio standard de marketplace.',
                'ambiguous_standard_price',
                409,
            );
        }

        $sale = is_array($salePayload) ? $salePayload : [];
        $saleAmount = $this->nullablePrice($sale['amount'] ?? null);
        $saleRegularAmount = $this->nullablePrice($sale['regular_amount'] ?? null);
        $priceDiscount = $this->selectPriceDiscount($promotionsPayload);
        $promotionPrice = $this->winningPromotionPrice($prices, $saleAmount, (float) $standard['amount'], $saleRegularAmount);

        return [
            'standard_base' => round((float) $standard['amount'], 2),
            'sale_amount' => $saleAmount,
            'sale_regular_amount' => $saleRegularAmount,
            'sale_metadata' => is_array($sale['metadata'] ?? null) ? $sale['metadata'] : [],
            'promotion_original' => $this->nullablePrice($priceDiscount['original_price'] ?? null),
            'allowed_min' => $this->nullablePrice($priceDiscount['min_discounted_price'] ?? null),
            'allowed_max' => $this->nullablePrice($priceDiscount['max_discounted_price'] ?? null),
            'suggested' => $this->nullablePrice($priceDiscount['suggested_discounted_price'] ?? null),
            'promotion_status' => $priceDiscount === null
                ? null
                : strtolower(trim((string) ($priceDiscount['status'] ?? ''))),
            'promotion_price' => $promotionPrice,
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return list<string>
     */
    public function eligibilityReasons(array $snapshot, float $basePrice, float $targetPrice): array
    {
        $reasons = [];
        if (! in_array($snapshot['promotion_status'] ?? null, ['candidate', 'started', 'active'], true)) {
            $reasons[] = 'price_discount_not_candidate';
        }

        $promotionOriginal = $snapshot['promotion_original'] ?? null;
        if (! is_numeric($promotionOriginal)) {
            $reasons[] = 'promotion_base_unavailable';
        } elseif (! $this->samePrice((float) $promotionOriginal, $basePrice)) {
            $reasons[] = 'promotion_base_mismatch';
        }

        $minimum = $snapshot['allowed_min'] ?? null;
        $maximum = $snapshot['allowed_max'] ?? null;
        if (! is_numeric($minimum) || ! is_numeric($maximum) || (float) $minimum > (float) $maximum) {
            $reasons[] = 'promotion_range_unavailable';
        } elseif ($targetPrice < (float) $minimum || $targetPrice > (float) $maximum) {
            $reasons[] = 'target_outside_promotion_range';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<string, mixed> */
    public function create(
        MeliAccount $account,
        MeliPriceManagerItem $item,
        MeliBeautyScheduledDiscount $rule,
        float $basePrice,
        float $targetPrice,
    ): array {
        $current = $this->snapshot($account, $item);
        if ($this->isConfirmedPromotion($current, $basePrice, $targetPrice)) {
            return $current;
        }

        [$start, $finish] = $this->promotionWindow($rule);
        $itemId = rawurlencode((string) $item->meli_item_id);
        $this->api->request(
            $account,
            'post',
            "/seller-promotions/items/{$itemId}?app_version=v2",
            [
                'deal_price' => round($targetPrice, 2),
                'start_date' => $start,
                'finish_date' => $finish,
                'promotion_type' => self::PROMOTION_TYPE,
            ],
            refreshAfterUnauthorized: false,
            maxAttempts: 1,
        );

        $confirmed = $this->snapshot($account, $item);
        if (! $this->isConfirmedPromotion($confirmed, $basePrice, $targetPrice)) {
            throw new MeliPriceUpdateException(
                'Mercado Libre recibió PRICE_DISCOUNT, pero no confirmó el precio promocional y su precio tachado.',
                'promotion_not_confirmed',
                502,
            );
        }

        return $confirmed;
    }

    /** @return array<string, mixed> */
    public function remove(MeliAccount $account, MeliPriceManagerItem $item): array
    {
        $current = $this->snapshot($account, $item);
        if ($this->isRestoredSnapshot($current)) {
            return $current;
        }

        $itemId = rawurlencode((string) $item->meli_item_id);
        $this->api->request(
            $account,
            'delete',
            "/seller-promotions/items/{$itemId}?promotion_type=".self::PROMOTION_TYPE.'&app_version=v2',
            refreshAfterUnauthorized: false,
            maxAttempts: 1,
        );

        return $this->confirmRestored($account, $item);
    }

    /** @return array<string, mixed> */
    public function confirmRestored(MeliAccount $account, MeliPriceManagerItem $item): array
    {
        for ($attempt = 1; $attempt <= self::RESTORE_CONFIRMATION_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                Sleep::for(self::RESTORE_CONFIRMATION_DELAY_MS)->milliseconds();
            }

            $confirmed = $this->snapshot($account, $item);
            if ($this->isRestoredSnapshot($confirmed)) {
                return $confirmed;
            }
        }

        Log::warning('PRICE_DISCOUNT restore not confirmed.', [
            'meli_item_id' => (string) $item->meli_item_id,
            'standard_base' => $confirmed['standard_base'],
            'sale_amount' => $confirmed['sale_amount'],
            'sale_regular_amount' => $confirmed['sale_regular_amount'],
            'promotion_status' => $confirmed['promotion_status'],
            'promotion_price' => $confirmed['promotion_price'],
        ]);

        throw new MeliPriceUpdateException(
            'Mercado Libre no confirmó la eliminación del precio promocional ganador.',
            'promotion_restore_not_confirmed',
            502,
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function isRestoredSnapshot(array $snapshot): bool
    {
        return is_numeric($snapshot['standard_base'])
            && $this->sameNullablePrice($snapshot['sale_amount'], $snapshot['standard_base'])
            && $snapshot['sale_regular_amount'] === null
            && $snapshot['promotion_price'] === null;
    }

    /** @param array<string, mixed> $snapshot */
    public function isConfirmedPromotion(array $snapshot, float $basePrice, float $targetPrice): bool
    {
        return in_array($snapshot['promotion_status'], ['candidate', 'started', 'active'], true)
            && $this->sameNullablePrice($snapshot['standard_base'], $basePrice)
            && $this->sameNullablePrice($snapshot['promotion_price'], $targetPrice)
            && $this->sameNullablePrice($snapshot['sale_amount'], $targetPrice)
            && $this->sameNullablePrice($snapshot['sale_regular_amount'], $basePrice);
    }

    /** @return array{0: string, 1: string} */
    private function promotionWindow(MeliBeautyScheduledDiscount $rule): array
    {
        $occurrence = $this->window->currentOccurrence($rule);
        if ($occurrence === null) {
            throw new MeliPriceUpdateException(
                'La promoción no se encuentra dentro de una ventana programada válida.',
                'scheduled_window_inactive',
                409,
            );
        }

        return [
            $occurrence['start']->startOfDay()->format('Y-m-d\TH:i:s'),
            $occurrence['end']->startOfDay()->format('Y-m-d\TH:i:s'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function prices(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $prices = array_is_list($payload) ? $payload : ($payload['prices'] ?? []);

        return is_array($prices)
            ? array_values(array_filter($prices, 'is_array'))
            : [];
    }

    /** @param list<array<string, mixed>> $prices
     * @return array<string, mixed>|null
     */
    private function selectMarketplacePrice(array $prices, string $type): ?array
    {
        $typed = array_values(array_filter(
            $prices,
            static fn (array $price): bool => strtolower(trim((string) ($price['type'] ?? ''))) === $type,
        ));
        $marketplace = array_values(array_filter(
            $typed,
            static fn (array $price): bool => in_array(
                'channel_marketplace',
                (array) data_get($price, 'conditions.context_restrictions', []),
                true,
            ),
        ));
        $general = array_values(array_filter(
            $typed,
            static fn (array $price): bool => (array) data_get($price, 'conditions.context_restrictions', []) === [],
        ));

        return count($marketplace) === 1
            ? $marketplace[0]
            : (count($marketplace) === 0 && count($general) === 1 ? $general[0] : null);
    }

    /** @return array<string, mixed>|null */
    private function selectPriceDiscount(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $promotions = array_is_list($payload)
            ? $payload
            : ($payload['results'] ?? $payload['promotions'] ?? []);
        if (! is_array($promotions)) {
            return null;
        }

        $matches = array_values(array_filter(
            $promotions,
            static fn (mixed $promotion): bool => is_array($promotion)
                && strtoupper(trim((string) ($promotion['type'] ?? $promotion['promotion_type'] ?? ''))) === self::PROMOTION_TYPE,
        ));
        foreach (['started', 'active', 'candidate'] as $preferredStatus) {
            $statusMatches = array_values(array_filter(
                $matches,
                static fn (array $promotion): bool => strtolower(trim((string) ($promotion['status'] ?? ''))) === $preferredStatus,
            ));
            if (count($statusMatches) === 1) {
                return $statusMatches[0];
            }
            if (count($statusMatches) > 1) {
                throw new MeliPriceUpdateException(
                    'Mercado Libre devolvió más de un PRICE_DISCOUNT equivalente para la publicación.',
                    'ambiguous_price_discount',
                    409,
                );
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param list<array<string, mixed>> $prices */
    private function winningPromotionPrice(array $prices, ?float $saleAmount, float $standard, ?float $regularAmount): ?float
    {
        if ($saleAmount === null || ($this->samePrice($saleAmount, $standard) && $regularAmount === null)) {
            return null;
        }

        $matches = array_values(array_filter(
            $prices,
            fn (array $price): bool => strtolower(trim((string) ($price['type'] ?? ''))) === 'promotion'
                && is_numeric($price['amount'] ?? null)
                && $this->samePrice((float) $price['amount'], $saleAmount),
        ));

        return count($matches) === 1 ? round((float) $matches[0]['amount'], 2) : null;
    }

    private function nullablePrice(mixed $price): ?float
    {
        return is_numeric($price) ? round((float) $price, 2) : null;
    }

    private function sameNullablePrice(mixed $first, mixed $second): bool
    {
        return is_numeric($first) && is_numeric($second) && $this->samePrice((float) $first, (float) $second);
    }

    private function samePrice(float $first, float $second): bool
    {
        return (int) round($first * 100) === (int) round($second * 100);
    }
}
