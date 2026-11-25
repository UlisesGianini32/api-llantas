<?php

namespace App\Services;

use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use Illuminate\Support\Collection;

/**
 * Computes a deterministic SHA-256 fingerprint of ONLY the material fields of a
 * Mercado Libre order that can affect inventory reservations (account, remote order ID,
 * order status, shipping status, cutover creation timestamp, and sorted line identities/quantities).
 *
 * Non-material sync churn (processed_at, updated_at, raw buyer/payment metadata,
 * shipping_raw, delivery_classified_at, titles, unit_price, etc.) is intentionally excluded.
 */
class InventoryMeliOrderReconciliationFingerprint
{
    public function forOrder(MeliOrder $order): string
    {
        $items = $order->relationLoaded('items')
            ? $order->items
            : $order->items()->get();

        return $this->fromParts($order, $items);
    }

    /**
     * @param  Collection<int, MeliOrderItem>  $items
     */
    public function fromParts(MeliOrder $order, Collection $items): string
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        $dateCreated = is_string($raw['date_created'] ?? null)
            ? trim((string) $raw['date_created'])
            : '';

        $normalizedItems = $items
            ->map(static function (MeliOrderItem $item): array {
                return [
                    'item_id' => trim((string) ($item->item_id ?? '')),
                    'variation_id' => $item->variation_id !== null && trim((string) $item->variation_id) !== ''
                        ? trim((string) $item->variation_id)
                        : null,
                    'remote_line_key' => $item->remote_line_key !== null && trim((string) $item->remote_line_key) !== ''
                        ? trim((string) $item->remote_line_key)
                        : null,
                    'quantity' => (int) ($item->quantity ?? 0),
                ];
            })
            ->sortBy(static fn (array $row): string => implode('|', [
                (string) ($row['remote_line_key'] ?? ''),
                $row['item_id'],
                (string) ($row['variation_id'] ?? ''),
                (string) $row['quantity'],
            ]))
            ->values()
            ->all();

        $payload = [
            'meli_account_id' => $order->meli_account_id !== null ? (int) $order->meli_account_id : null,
            'order_id' => trim((string) ($order->order_id ?? '')),
            'status' => strtolower(trim((string) ($order->status ?? ''))),
            'shipping_status' => strtolower(trim((string) ($order->shipping_status ?? ''))),
            'date_created' => $dateCreated,
            'items' => $normalizedItems,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
