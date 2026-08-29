<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

class MeliPriceSimulationService
{
    private const DIMENSION_ATTRIBUTE_SETS = [
        ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WEIGHT'],
        ['PACKAGE_HEIGHT', 'PACKAGE_WIDTH', 'PACKAGE_LENGTH', 'PACKAGE_WEIGHT'],
    ];

    public function __construct(
        private readonly MeliAccountApiClient $api,
        private readonly MeliSellerTaxSimulationService $taxes,
    ) {}

    /** @return array<string, mixed> */
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
            ->focusedCatalog()
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
        $meliPercentageFee = $this->nullableNumber(data_get($listing, 'sale_fee_details.meli_percentage_fee'));
        $fixedFee = $this->nullableNumber(data_get($listing, 'sale_fee_details.fixed_fee'));
        $financingAddOnFee = $this->nullableNumber(data_get($listing, 'sale_fee_details.financing_add_on_fee'));
        $saleFeeGross = $this->nullableNumber(data_get($listing, 'sale_fee_details.gross_amount'));
        $listingFee = $this->nullableNumber($listing['listing_fee_amount'] ?? null);
        $listingFeeFixed = $this->nullableNumber(data_get($listing, 'listing_fee_details.fixed_fee'));
        $listingFeeGross = $this->nullableNumber(data_get($listing, 'listing_fee_details.gross_amount'));

        $shippingAvailable = false;
        $shippingCost = null;
        $shippingPromotedAmount = null;
        $shippingDiscountRate = null;
        $shippingDiscountType = null;
        $shippingDiscountAmount = null;
        $shippingBillableWeight = null;
        $shippingCurrencyId = null;
        $shippingError = null;

        $sellerId = trim((string) $account->meli_user_id);
        if ($sellerId === '') {
            $shippingError = 'La cuenta no tiene identificador de vendedor para cotizar el envío.';
        } else {
            $shippingParameters = $this->withoutEmpty([
                'dimensions' => $this->dimensions($item, $rawItem),
                'verbose' => true,
                'item_price' => $proposedPrice,
                'listing_type_id' => $listingTypeId,
                'mode' => $shippingMode,
                'condition' => $this->nullableString($rawItem['condition'] ?? null),
                'logistic_type' => $logisticType,
                'free_shipping' => $freeShipping ? 'true' : 'false',
                'item_id' => (string) $item->meli_item_id,
            ]);
            try {
                $shippingResponse = $this->api->request(
                    $account,
                    'get',
                    '/users/'.rawurlencode($sellerId).'/shipping_options/free',
                    $shippingParameters,
                );
                $shippingData = $shippingResponse->json();
                $quotedCost = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.list_cost'));
                $shippingCurrencyId = $this->nullableString(data_get($shippingData, 'coverage.all_country.currency_id')) ?? $currencyId;
                $shippingPromotedAmount = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.discount.promoted_amount'));
                $shippingDiscountRate = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.discount.rate'));
                $shippingDiscountType = $this->nullableString(data_get($shippingData, 'coverage.all_country.discount.type'));
                $shippingDiscountAmount = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.discount.save'));
                $shippingBillableWeight = $this->nullableNumber(data_get($shippingData, 'coverage.all_country.billable_weight'));

                if ($quotedCost === null) {
                    $shippingError = 'Mercado Libre no devolvió un costo de envío para esta simulación.';
                } elseif (strcasecmp($shippingCurrencyId, $currencyId) !== 0) {
                    $shippingError = 'La moneda de la cotización de envío no coincide con la moneda de la publicación.';
                } else {
                    $shippingAvailable = true;
                    $shippingCost = round($quotedCost, 2);
                }
            } catch (MeliApiRequestException $exception) {
                $shippingError = 'Costo de envío no disponible para esta simulación.';
                Log::warning('No fue posible cotizar el envío de una simulación de precio.', [
                    'meli_account_id' => (int) $account->id,
                    'meli_item_id' => (string) $item->meli_item_id,
                    'http_status' => $exception->httpStatus(),
                    'message' => $shippingError,
                ]);
            }
        }

        $saleFee = round($saleFee, 2);
        $listingFee = $listingFee !== null ? round($listingFee, 2) : null;
        $shippingPromotedAmount = $shippingPromotedAmount !== null ? round($shippingPromotedAmount, 2) : null;
        $shippingDiscountAmount = $shippingDiscountAmount !== null ? round($shippingDiscountAmount, 2) : null;
        $platformChargesTotal = round($saleFee + ($listingFee ?? 0), 2);
        $meliChargesTotal = round($platformChargesTotal + ($shippingCost ?? 0), 2);
        $taxes = $this->taxes->simulate($account, $proposedPrice);
        $taxesTotal = $taxes['available'] === true && is_numeric($taxes['amount'] ?? null)
            ? round((float) $taxes['amount'], 2)
            : null;
        $totalCharges = round($platformChargesTotal + ($shippingCost ?? 0) + ($taxesTotal ?? 0), 2);
        $estimatedReceivable = round($proposedPrice - $totalCharges, 2);
        $otherListingPriceDetails = $this->additionalListingPriceDetails($listing);
        $charges = [
            'sale_fee' => [
                'amount' => $saleFee,
                'percentage' => $percentageFee,
                'meli_percentage' => $meliPercentageFee,
                'fixed_fee' => $fixedFee !== null ? round($fixedFee, 2) : null,
                'financing_add_on_fee' => $financingAddOnFee,
                'gross_amount' => $saleFeeGross !== null ? round($saleFeeGross, 2) : null,
            ],
            'listing_fee' => [
                'available' => $listingFee !== null,
                'amount' => $listingFee,
                'fixed_fee' => $listingFeeFixed !== null ? round($listingFeeFixed, 2) : null,
                'gross_amount' => $listingFeeGross !== null ? round($listingFeeGross, 2) : null,
            ],
            'shipping' => [
                'available' => $shippingAvailable,
                'cost' => $shippingCost,
                'seller_cost' => $shippingCost,
                'source' => 'meli_shipping_options_free',
                'error' => $shippingError,
                'promoted_amount' => $shippingPromotedAmount,
                'original_cost' => $shippingPromotedAmount,
                'discount_rate' => $shippingDiscountRate,
                'discount_amount' => $shippingDiscountAmount,
                'discount_type' => $shippingDiscountType,
                'billable_weight' => $shippingBillableWeight,
                'currency_id' => $shippingCurrencyId,
                'free_shipping' => $freeShipping,
                'mode' => $shippingMode,
                'logistic_type' => $logisticType,
            ],
            'taxes' => $taxes,
            'other' => $otherListingPriceDetails,
        ];

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
            'sale_fee_meli_percentage' => $meliPercentageFee,
            'sale_fee_fixed' => $fixedFee !== null ? round($fixedFee, 2) : null,
            'sale_fee_financing_add_on' => $financingAddOnFee,
            'sale_fee_gross' => $saleFeeGross !== null ? round($saleFeeGross, 2) : null,
            'listing_fee' => $listingFee,
            'listing_fee_fixed' => $listingFeeFixed !== null ? round($listingFeeFixed, 2) : null,
            'listing_fee_gross' => $listingFeeGross !== null ? round($listingFeeGross, 2) : null,
            'free_shipping' => $freeShipping,
            'shipping_mode' => $shippingMode,
            'logistic_type' => $logisticType,
            'shipping_cost' => $shippingCost,
            'shipping_available' => $shippingAvailable,
            'shipping_error' => $shippingError,
            'shipping_original_cost' => $shippingPromotedAmount,
            'shipping_discount_rate' => $shippingDiscountRate,
            'shipping_discount_amount' => $shippingDiscountAmount,
            'shipping_discount_type' => $shippingDiscountType,
            'shipping_billable_weight' => $shippingBillableWeight,
            'shipping_currency_id' => $shippingCurrencyId,
            'charges' => $charges,
            'platform_charges_total' => $platformChargesTotal,
            'meli_charges_total' => $meliChargesTotal,
            'confirmed_charges_total' => $meliChargesTotal,
            'taxes_total' => $taxesTotal,
            'total_charges' => $totalCharges,
            'estimated_receivable' => $estimatedReceivable,
            'estimated_receivable_percentage' => round(($estimatedReceivable / $proposedPrice) * 100, 2),
            'estimated_receivable_is_final' => false,
            'estimated_receivable_label' => ! $shippingAvailable
                ? 'Recibes antes de envío'
                : ($taxesTotal !== null ? 'Recibes estimado' : 'Recibes estimado sin retenciones fiscales'),
            'estimated_receivable_message' => ! $shippingAvailable
                ? 'No fue posible estimar el costo de envío. Este monto todavía no descuenta el envío.'
                : ($taxesTotal !== null
                    ? (string) ($taxes['message'] ?? 'Incluye el envío y las retenciones fiscales estimadas; el monto final puede variar al procesarse la venta.')
                    : (string) ($taxes['message'] ?? 'Incluye el envío; las retenciones fiscales no están incluidas en este estimado.')),
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
                $this->attributeMeasurement($attributes, $heightId, 'length'),
                $this->attributeMeasurement($attributes, $widthId, 'length'),
                $this->attributeMeasurement($attributes, $lengthId, 'length'),
                $this->attributeMeasurement($attributes, $weightId, 'weight'),
            ];

            if (! in_array(null, $values, true)) {
                return implode('x', array_slice($values, 0, 3)).','.$values[3];
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $attributes */
    private function attributeMeasurement(array $attributes, string $attributeId, string $kind): ?int
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp((string) ($attribute['id'] ?? ''), $attributeId) !== 0) {
                continue;
            }

            foreach ([
                [data_get($attribute, 'value_struct.number'), data_get($attribute, 'value_struct.unit')],
                [data_get($attribute, 'values.0.struct.number'), data_get($attribute, 'values.0.struct.unit')],
            ] as [$number, $unit]) {
                $normalized = $this->normalizeMeasurement($number, $unit, $kind);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            $valueName = trim((string) ($attribute['value_name'] ?? ''));
            if (preg_match('/(-?\d+(?:[.,]\d+)?)\s*([\p{L}µ]+)/u', $valueName, $matches) === 1) {
                $normalized = $this->normalizeMeasurement(str_replace(',', '.', $matches[1]), $matches[2], $kind);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function normalizeMeasurement(mixed $number, mixed $unit, string $kind): ?int
    {
        if (! is_numeric($number) || (float) $number <= 0 || ! is_string($unit)) {
            return null;
        }

        $unit = mb_strtolower(trim($unit), 'UTF-8');
        $value = (float) $number;
        $normalized = match ($kind) {
            'length' => match ($unit) {
                'mm', 'milímetro', 'milímetros', 'millimeter', 'millimeters' => $value / 10,
                'cm', 'centímetro', 'centímetros', 'centimeter', 'centimeters' => $value,
                'm', 'metro', 'metros', 'meter', 'meters' => $value * 100,
                default => null,
            },
            'weight' => match ($unit) {
                'mg', 'miligramo', 'miligramos', 'milligram', 'milligrams' => $value / 1000,
                'g', 'gr', 'gramo', 'gramos', 'gram', 'grams' => $value,
                'kg', 'kilogramo', 'kilogramos', 'kilogram', 'kilograms' => $value * 1000,
                default => null,
            },
            default => null,
        };

        if ($normalized === null || ! is_finite($normalized) || $normalized <= 0) {
            return null;
        }

        return $kind === 'weight'
            ? (int) round($normalized, 0, PHP_ROUND_HALF_UP)
            : (int) ceil($normalized);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
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

    /**
     * Preserve future fee-like numeric fields without assuming their unit or adding
     * them to the seller total until Mercado Libre documents their semantics.
     *
     * @param  array<string, mixed>  $listing
     * @return list<array{key: string, label: string, value: float, included_in_total: bool}>
     */
    private function additionalListingPriceDetails(array $listing): array
    {
        $knownPaths = [
            'listing_fee_amount',
            'listing_fee_details.fixed_fee',
            'listing_fee_details.gross_amount',
            'sale_fee_amount',
            'sale_fee_details.financing_add_on_fee',
            'sale_fee_details.fixed_fee',
            'sale_fee_details.gross_amount',
            'sale_fee_details.meli_percentage_fee',
            'sale_fee_details.percentage_fee',
        ];
        $additional = [];

        $walk = function (array $values, string $prefix = '') use (&$walk, &$additional, $knownPaths): void {
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $walk($value, $path);

                    continue;
                }

                if (in_array($path, $knownPaths, true)
                    || ! is_numeric($value)
                    || ! preg_match('/(?:fee|charge|cost|amount)/i', (string) $key)) {
                    continue;
                }

                $additional[] = [
                    'key' => $path,
                    'label' => Str::headline((string) $key),
                    'value' => round((float) $value, 2),
                    'included_in_total' => false,
                ];
            }
        };
        $walk($listing);

        return $additional;
    }
}
