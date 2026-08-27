<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use UnexpectedValueException;

class MeliPriceSimulationService
{
    private const DIMENSION_ATTRIBUTE_SETS = [
        ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WEIGHT'],
        ['PACKAGE_HEIGHT', 'PACKAGE_WIDTH', 'PACKAGE_LENGTH', 'PACKAGE_WEIGHT'],
    ];

    public function __construct(private readonly MeliAccountApiClient $api) {}

    /** @return array<string, bool|float|int|string|null> */
    public function simulate(MeliAccount $account, MeliPriceManagerItem $item, float $price): array
    {
        if (! is_finite($price) || $price <= 0) {
            throw new InvalidArgumentException('El precio propuesto debe ser mayor que cero.');
        }

        if (! $account->exists || ! $item->exists) {
            throw new InvalidArgumentException('La cuenta y la publicación deben existir antes de simular.');
        }

        if ((int) $item->meli_account_id !== (int) $account->id) {
            throw new AuthorizationException('La publicación no pertenece a la cuenta seleccionada.');
        }

        $isManaged = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->whereKey($item->getKey())
            ->where('meli_account_id', $account->id)
            ->exists();

        if (! $isManaged) {
            throw new AuthorizationException('La publicación está excluida del catálogo administrable.');
        }

        $categoryId = trim((string) $item->category_id);
        $listingTypeId = trim((string) $item->listing_type_id);
        $currencyId = trim((string) $item->currency_id);

        if ($categoryId === '' || $listingTypeId === '' || $currencyId === '') {
            throw new InvalidArgumentException('La publicación no tiene categoría, tipo de publicación o moneda suficiente para simular.');
        }

        $rawItem = is_array($item->raw_item) ? $item->raw_item : [];
        $shipping = is_array($rawItem['shipping'] ?? null) ? $rawItem['shipping'] : [];
        $shippingMode = $this->nullableString($shipping['mode'] ?? null);
        $logisticType = $this->nullableString($shipping['logistic_type'] ?? null);
        $freeShipping = filter_var($shipping['free_shipping'] ?? false, FILTER_VALIDATE_BOOL);
        $proposedPrice = round($price, 2);

        $this->api->ensureFreshAccessToken($account);
        $listingResponse = $this->api->request($account, 'get', '/sites/MLM/listing_prices', $this->withoutEmpty([
            'price' => $proposedPrice,
            'category_id' => $categoryId,
            'currency_id' => $currencyId,
            'listing_type_id' => $listingTypeId,
            'logistic_type' => $logisticType,
            'shipping_mode' => $shippingMode,
        ]));
        $listing = $this->listingPriceEntry($listingResponse->json(), $listingTypeId);
        $saleFee = $this->requiredNumber($listing['sale_fee_amount'] ?? null, 'Mercado Libre no devolvió un cargo por venta válido.');
        $percentageFee = $this->nullableNumber(data_get($listing, 'sale_fee_details.percentage_fee'));
        $fixedFee = $this->nullableNumber(data_get($listing, 'sale_fee_details.fixed_fee')) ?? 0.0;

        $shippingCost = 0.0;
        $shippingOriginalCost = null;
        $shippingDiscountRate = null;

        if ($freeShipping) {
            $sellerId = trim((string) $account->meli_user_id);
            if ($sellerId === '') {
                throw new InvalidArgumentException('La cuenta no tiene meli_user_id para consultar el envío.');
            }

            $shippingParameters = $this->withoutEmpty([
                'dimensions' => $this->dimensions($item, $rawItem),
                'verbose' => true,
                'item_price' => $proposedPrice,
                'listing_type_id' => $listingTypeId,
                'mode' => $shippingMode,
                'condition' => $this->nullableString($rawItem['condition'] ?? null),
                'logistic_type' => $logisticType,
                'free_shipping' => true,
                'item_id' => (string) $item->meli_item_id,
            ]);
            $shippingResponse = $this->api->request(
                $account,
                'get',
                '/users/'.rawurlencode($sellerId).'/shipping_options/free',
                $shippingParameters,
            );
            $shippingData = $shippingResponse->json();
            $shippingCost = $this->requiredNumber(
                data_get($shippingData, 'coverage.all_country.list_cost'),
                'Mercado Libre no devolvió un costo de envío válido.',
            );
            $shippingOriginalCost = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.discount.promoted_amount'));
            $shippingDiscountRate = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.discount.rate'));
        }

        $saleFee = round($saleFee, 2);
        $shippingCost = round($shippingCost, 2);
        $totalCharges = round($saleFee + $shippingCost, 2);
        $estimatedReceivable = round($proposedPrice - $totalCharges, 2);

        return [
            'item_id' => (int) $item->id,
            'meli_item_id' => (string) $item->meli_item_id,
            'title' => (string) $item->title,
            'currency_id' => $currencyId,
            'current_price' => round((float) $item->current_price, 2),
            'proposed_price' => $proposedPrice,
            'listing_type_id' => $listingTypeId,
            'listing_type_name' => $this->nullableString($listing['listing_type_name'] ?? null),
            'sale_fee' => $saleFee,
            'sale_fee_percentage' => $percentageFee,
            'sale_fee_fixed' => round($fixedFee, 2),
            'free_shipping' => $freeShipping,
            'shipping_mode' => $shippingMode,
            'logistic_type' => $logisticType,
            'shipping_cost' => $shippingCost,
            'shipping_original_cost' => $shippingOriginalCost !== null ? round($shippingOriginalCost, 2) : null,
            'shipping_discount_rate' => $shippingDiscountRate,
            'total_charges' => $totalCharges,
            'estimated_receivable' => $estimatedReceivable,
            'estimated_receivable_percentage' => round(($estimatedReceivable / $proposedPrice) * 100, 2),
            'calculated_at' => now()->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function listingPriceEntry(mixed $payload, string $listingTypeId): array
    {
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Mercado Libre devolvió una respuesta de cargos inválida.');
        }

        if (array_key_exists('sale_fee_amount', $payload)) {
            return $payload;
        }

        $entries = is_array($payload['listing_prices'] ?? null) ? $payload['listing_prices'] : $payload;
        foreach ($entries as $entry) {
            if (is_array($entry) && (string) ($entry['listing_type_id'] ?? '') === $listingTypeId) {
                return $entry;
            }
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && array_key_exists('sale_fee_amount', $entry)) {
                return $entry;
            }
        }

        throw new UnexpectedValueException('Mercado Libre no devolvió cargos para el tipo de publicación solicitado.');
    }

    /** @param array<string, mixed> $rawItem */
    private function dimensions(MeliPriceManagerItem $item, array $rawItem): ?string
    {
        $attributes = array_values(array_filter([
            ...((array) ($rawItem['attributes'] ?? [])),
            ...((array) ($item->raw_attributes ?? [])),
        ], 'is_array'));

        foreach (self::DIMENSION_ATTRIBUTE_SETS as [$heightId, $widthId, $lengthId, $weightId]) {
            $values = [
                $this->attributeNumber($attributes, $heightId),
                $this->attributeNumber($attributes, $widthId),
                $this->attributeNumber($attributes, $lengthId),
                $this->attributeNumber($attributes, $weightId),
            ];

            if (! in_array(null, $values, true)) {
                return implode('x', array_slice($values, 0, 3)).','.$values[3];
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $attributes */
    private function attributeNumber(array $attributes, string $attributeId): ?int
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp((string) ($attribute['id'] ?? ''), $attributeId) !== 0) {
                continue;
            }

            $value = data_get($attribute, 'value_struct.number')
                ?? data_get($attribute, 'values.0.struct.number')
                ?? ($attribute['value_name'] ?? null);

            if (! is_numeric($value)) {
                preg_match('/-?\d+(?:[.,]\d+)?/', (string) $value, $matches);
                $value = isset($matches[0]) ? str_replace(',', '.', $matches[0]) : null;
            }

            if (! is_numeric($value) || (float) $value <= 0) {
                continue;
            }

            return (int) ceil((float) $value);
        }

        return null;
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function requiredNumber(mixed $value, string $message): float
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException($message);
        }

        return (float) $value;
    }

    private function nullableNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
