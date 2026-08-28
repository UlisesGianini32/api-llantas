<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliPriceManagerItem;
use Illuminate\Support\Facades\Schema;

class MeliHistoricalTaxObservationService
{
    private const MAXIMUM_ORDERS = 60;

    private const MONEY_TOLERANCE_CENTS = 1;

    private const INCOMPATIBLE_TAX_STATUSES = [
        'cancelled',
        'canceled',
        'rejected',
        'refunded',
        'reversed',
        'voided',
    ];

    public function __construct(private readonly MeliHistoricalTaxDataService $billing) {}

    /** @return list<array<string, mixed>> */
    public function forAccount(MeliAccount $account): array
    {
        if (! $account->exists
            || ! Schema::hasTable('meli_orders')
            || ! Schema::hasTable('meli_price_manager_items')) {
            return [];
        }

        $orders = MeliOrder::query()
            ->where('meli_account_id', $account->id)
            ->where('status', 'paid')
            ->whereNotNull('raw')
            ->latest('created_at')
            ->limit(self::MAXIMUM_ORDERS)
            ->get(['id', 'meli_account_id', 'order_id', 'raw', 'created_at']);

        $candidates = [];
        foreach ($orders as $order) {
            $candidate = $this->localCandidate($order);
            if ($candidate !== null) {
                $candidates[(string) $order->order_id] = $candidate;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $managedItemIds = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->where('meli_account_id', $account->id)
            ->whereIn('meli_item_id', array_values(array_unique(array_column($candidates, 'item_id'))))
            ->pluck('meli_item_id')
            ->mapWithKeys(fn (mixed $itemId): array => [(string) $itemId => true])
            ->all();
        $candidates = array_filter(
            $candidates,
            static fn (array $candidate): bool => isset($managedItemIds[$candidate['item_id']]),
        );

        if ($candidates === []) {
            return [];
        }

        $billing = $this->billing->forOrders($account, array_keys($candidates));
        if (($billing['available'] ?? false) !== true) {
            return [];
        }

        $observations = [];
        foreach ((array) ($billing['orders'] ?? []) as $billingOrder) {
            if (! is_array($billingOrder)) {
                continue;
            }

            $orderId = (string) ($billingOrder['order_id'] ?? '');
            $candidate = $candidates[$orderId] ?? null;
            $observation = $candidate !== null
                ? $this->observation($account, $candidate, $billingOrder)
                : null;
            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        return $observations;
    }

    /** @return array<string, mixed>|null */
    private function localCandidate(MeliOrder $order): ?array
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        $items = is_array($raw['order_items'] ?? null) ? $raw['order_items'] : [];
        if (count($items) !== 1 || ! is_array($items[0])) {
            return null;
        }

        $item = $items[0];
        $itemId = trim((string) (data_get($item, 'item.id') ?? ($item['item_id'] ?? '')));
        $quantity = $item['quantity'] ?? null;
        if ($itemId === '' || ! is_numeric($quantity) || (int) $quantity <= 0) {
            return null;
        }

        $grossSaleAmount = $this->grossSaleAmount($raw, $item, (int) $quantity);
        if ($grossSaleAmount === null || $grossSaleAmount <= 0) {
            return null;
        }

        return [
            'order_id' => (string) $order->order_id,
            'item_id' => $itemId,
            'gross_sale_amount' => round($grossSaleAmount, 2),
            'observed_at' => $order->created_at?->toISOString(),
        ];
    }

    /** @param array<string, mixed> $raw
     * @param array<string, mixed> $item
     */
    private function grossSaleAmount(array $raw, array $item, int $quantity): ?float
    {
        $grossPrice = is_numeric($item['gross_price'] ?? null)
            ? round((float) $item['gross_price'], 2)
            : null;
        $unitTotal = is_numeric($item['unit_price'] ?? null)
            ? round((float) $item['unit_price'] * $quantity, 2)
            : null;

        if (($grossPrice !== null && $grossPrice <= 0)
            || ($unitTotal !== null && $unitTotal <= 0)) {
            return null;
        }

        // Mercado Libre documents gross_price as the total for every unit, not a unit price.
        // A difference from the paid line total can represent a discount; until its tax-base
        // semantics are proven for this account, exclude that order from rule inference.
        if ($grossPrice !== null && $unitTotal !== null && ! $this->sameMoney($grossPrice, $unitTotal)) {
            return null;
        }

        $amount = $grossPrice ?? $unitTotal;
        if ($amount === null) {
            return null;
        }

        if (is_numeric($raw['total_amount'] ?? null)
            && ! $this->sameMoney($amount, (float) $raw['total_amount'])) {
            return null;
        }

        return $amount;
    }

    /** @param array<string, mixed> $candidate
     * @param array<string, mixed> $billingOrder
     * @return array<string, mixed>|null
     */
    private function observation(MeliAccount $account, array $candidate, array $billingOrder): ?array
    {
        $billingItemIds = array_values(array_unique(array_map('strval', (array) ($billingOrder['item_ids'] ?? []))));
        $payments = array_values(array_filter(
            (array) ($billingOrder['payments'] ?? []),
            static fn (mixed $payment): bool => is_array($payment) && ($payment['status'] ?? null) === 'approved',
        ));
        if (count($billingItemIds) !== 1
            || $billingItemIds[0] !== $candidate['item_id']
            || count($payments) !== 1) {
            return null;
        }

        $taxAmounts = [];
        foreach ((array) ($payments[0]['taxes'] ?? []) as $tax) {
            if (! is_array($tax)
                || ($tax['mov_detail'] ?? null) !== 'tax_withholding'
                || $this->hasIncompatibleTaxStatus($tax)
                || ! is_numeric($tax['original_amount'] ?? null)
                || ! is_numeric($tax['refunded_amount'] ?? null)
                || (float) $tax['refunded_amount'] !== 0.0) {
                return null;
            }

            $type = strtolower(trim((string) ($tax['mov_financial_entity'] ?? '')));
            if (! in_array($type, ['iva', 'isr'], true) || isset($taxAmounts[$type])) {
                return null;
            }

            $amount = round((float) $tax['original_amount'], 2);
            if ($amount <= 0) {
                return null;
            }
            $taxAmounts[$type] = $amount;
        }

        if (! isset($taxAmounts['iva'], $taxAmounts['isr'])) {
            return null;
        }

        return [
            'meli_account_id' => (int) $account->id,
            'order_id' => $candidate['order_id'],
            'item_id' => $candidate['item_id'],
            'gross_sale_amount' => $candidate['gross_sale_amount'],
            'vat_amount' => $taxAmounts['iva'],
            'income_tax_amount' => $taxAmounts['isr'],
            'payment_status' => 'approved',
            'refunded' => false,
            'attribution_scope' => 'single_item',
            'observed_at' => $payments[0]['date_approved'] ?? $candidate['observed_at'],
        ];
    }

    /** @param array<string, mixed> $tax */
    private function hasIncompatibleTaxStatus(array $tax): bool
    {
        if (! array_key_exists('tax_status', $tax)) {
            return false;
        }

        $status = strtolower(trim((string) $tax['tax_status']));

        return $status !== '' && in_array($status, self::INCOMPATIBLE_TAX_STATUSES, true);
    }

    private function sameMoney(float $first, float $second): bool
    {
        return abs((int) round($first * 100) - (int) round($second * 100)) <= self::MONEY_TOLERANCE_CENTS;
    }
}
