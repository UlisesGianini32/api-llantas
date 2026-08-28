<?php

namespace App\Services\MercadoLibre\PriceManager;

class MeliTaxDetailsNormalizer
{
    /** @return array<string, mixed> */
    public function normalize(mixed $payload): array
    {
        $results = is_array($payload) && is_array($payload['results'] ?? null)
            ? $payload['results']
            : [];
        $orders = [];
        $taxDetailsCount = 0;

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $payments = [];
            foreach ($this->arrayList($result['payment_info'] ?? null) as $payment) {
                $taxes = [];
                foreach ($this->arrayList($payment['tax_details'] ?? null) as $tax) {
                    $normalizedTax = $this->normalizeTax($tax);
                    if ($normalizedTax !== []) {
                        $taxes[] = $normalizedTax;
                        $taxDetailsCount++;
                    }
                }

                if ($taxes === []) {
                    continue;
                }

                $payments[] = array_filter([
                    'payment_id' => $this->identifier($payment['payment_id'] ?? null),
                    'date_approved' => $this->string($payment['date_approved'] ?? null),
                    'status' => $this->string($payment['status'] ?? null),
                    'taxes' => $taxes,
                ], static fn (mixed $value): bool => $value !== null);
            }

            if ($payments === []) {
                continue;
            }

            $orders[] = [
                'order_id' => $this->identifier($result['order_id'] ?? null),
                'item_ids' => $this->itemIds($result['details'] ?? null),
                'payments' => $payments,
                'attribution_scope' => 'order_payment',
            ];
        }

        return [
            'available' => $taxDetailsCount > 0,
            'source' => $taxDetailsCount > 0 ? 'mercadolibre_billing' : null,
            'confidence' => $taxDetailsCount > 0 ? 'exact' : 'unknown',
            'orders' => $orders,
            'tax_details_count' => $taxDetailsCount,
            'message' => $taxDetailsCount > 0
                ? 'Datos fiscales observados en facturación de Mercado Libre; su atribución corresponde al pago de la orden.'
                : 'Mercado Libre no devolvió detalles fiscales para las órdenes consultadas.',
        ];
    }

    /** @param array<string, mixed> $tax
     * @return array<string, mixed>
     */
    private function normalizeTax(array $tax): array
    {
        $normalized = [];
        foreach (['from', 'to', 'mov_detail', 'mov_financial_entity', 'tax_status'] as $key) {
            $value = $this->string($tax[$key] ?? null);
            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }

        $taxId = $this->identifier($tax['tax_id'] ?? null);
        if ($taxId !== null) {
            $normalized['tax_id'] = $taxId;
        }

        foreach (['original_amount', 'refunded_amount'] as $key) {
            if (is_numeric($tax[$key] ?? null)) {
                $normalized[$key] = round((float) $tax[$key], 2);
            }
        }

        return $normalized;
    }

    /** @return list<string|int> */
    private function itemIds(mixed $details): array
    {
        $itemIds = [];
        foreach ($this->arrayList($details) as $detail) {
            $itemsInfo = $detail['items_info'] ?? null;
            $items = is_array($itemsInfo) && array_is_list($itemsInfo) ? $itemsInfo : [$itemsInfo];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemId = $this->identifier($item['item_id'] ?? null);
                if ($itemId !== null) {
                    $itemIds[(string) $itemId] = $itemId;
                }
            }
        }

        return array_values($itemIds);
    }

    /** @return list<array<string, mixed>> */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            return [$value];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function identifier(mixed $value): string|int|null
    {
        return is_int($value) || is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
