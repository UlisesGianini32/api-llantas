<?php

namespace App\Services;

use App\Models\MeliOrder;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Evaluates chronological freshness, payload integrity, and entity identity of remote
 * Mercado Libre order and shipment payloads independently under `meli_orders` row locks.
 *
 * Guarantees (H8 + H14):
 * 1. Strict ISO-8601 UTC timestamp validation (requires explicit timezone, calendar `checkdate()`,
 *    valid H:i:s bounds, and zero DateTime warnings — impossible dates like 2026-02-30 or
 *    trailing garbage are strictly treated as invalid/`null`).
 * 2. Differentiates `complete`, `partial`, and `invalid` order payloads:
 *    - `invalid` (order_id/account mismatch, malformed `order_items`, blank item_id, non-positive
 *      or missing quantity, blank status when present) -> REJECTED before any DB mutation.
 *    - `partial` (`order_items` key omitted) -> NEVER deletes existing `meli_order_items`,
 *      preserves omitted material fields (like `status`), and never advances `raw.last_updated`
 *      past the last complete-items version (tracks partial status timestamp separately in
 *      `_partial_status_last_updated` so a subsequent complete payload at the same timestamp is
 *      still accepted to materialize lines).
 *    - Conservative `order_items: []`: rejected when the order already has materialized lines.
 * 3. Independent order vs shipment versioning, plus strict `shipping_id` identity validation:
 *    a different `shipping_id` is rejected unless authorized by an accepted newer order payload.
 */
class MeliOrderRemoteFreshnessGuard
{
    public const PAYLOAD_COMPLETE = 'complete';

    public const PAYLOAD_PARTIAL = 'partial';

    public const PAYLOAD_EMPTY_ITEMS = 'empty_items';

    public const PAYLOAD_INVALID = 'invalid';

    /**
     * Validates payload structure and entity identity even for new orders or before persistence.
     *
     * @return array{
     *     kind: string,
     *     valid: bool,
     *     reason: ?string,
     *     normalized_items: list<array{item_id: string, variation_id: ?string, quantity: int}>|null
     * }
     */
    public function classifyOrderPayload(array $orderData): array
    {
        $rawId = $orderData['id'] ?? null;
        if ($rawId === null || ! is_numeric($rawId) || (int) $rawId <= 0) {
            return [
                'kind' => self::PAYLOAD_INVALID,
                'valid' => false,
                'reason' => 'invalid_order_id',
                'normalized_items' => null,
            ];
        }

        if (array_key_exists('status', $orderData) && $orderData['status'] !== null) {
            if (! is_string($orderData['status']) || trim($orderData['status']) === '') {
                return [
                    'kind' => self::PAYLOAD_INVALID,
                    'valid' => false,
                    'reason' => 'invalid_order_status',
                    'normalized_items' => null,
                ];
            }
        }

        if (! array_key_exists('order_items', $orderData)) {
            return [
                'kind' => self::PAYLOAD_PARTIAL,
                'valid' => true,
                'reason' => null,
                'normalized_items' => null,
            ];
        }

        $rawItems = $orderData['order_items'];
        if (! is_array($rawItems)) {
            return [
                'kind' => self::PAYLOAD_INVALID,
                'valid' => false,
                'reason' => 'malformed_order_items_not_array',
                'normalized_items' => null,
            ];
        }

        if ($rawItems === []) {
            return [
                'kind' => self::PAYLOAD_EMPTY_ITEMS,
                'valid' => true,
                'reason' => null,
                'normalized_items' => [],
            ];
        }

        $normalized = [];
        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                return [
                    'kind' => self::PAYLOAD_INVALID,
                    'valid' => false,
                    'reason' => 'malformed_order_item_element',
                    'normalized_items' => null,
                ];
            }

            $itemInfo = $item['item'] ?? null;
            if (! is_array($itemInfo)) {
                return [
                    'kind' => self::PAYLOAD_INVALID,
                    'valid' => false,
                    'reason' => 'missing_order_item_metadata',
                    'normalized_items' => null,
                ];
            }

            $itemId = is_scalar($itemInfo['id'] ?? null) ? trim((string) $itemInfo['id']) : '';
            if ($itemId === '') {
                return [
                    'kind' => self::PAYLOAD_INVALID,
                    'valid' => false,
                    'reason' => 'missing_order_item_id',
                    'normalized_items' => null,
                ];
            }

            $qtyRaw = $item['quantity'] ?? null;
            if ($qtyRaw === null || is_bool($qtyRaw) || ! is_numeric($qtyRaw) || (int) $qtyRaw <= 0 || (string) (int) $qtyRaw !== trim((string) $qtyRaw)) {
                return [
                    'kind' => self::PAYLOAD_INVALID,
                    'valid' => false,
                    'reason' => 'invalid_order_item_quantity',
                    'normalized_items' => null,
                ];
            }

            $varRaw = $itemInfo['variation_id'] ?? $item['variation_id'] ?? null;
            $variationId = (is_scalar($varRaw) && trim((string) $varRaw) !== '') ? trim((string) $varRaw) : null;

            $normalized[] = [
                'item_id' => $itemId,
                'variation_id' => $variationId,
                'quantity' => (int) $qtyRaw,
            ];
        }

        return [
            'kind' => self::PAYLOAD_COMPLETE,
            'valid' => true,
            'reason' => null,
            'normalized_items' => $this->sortItems($normalized),
        ];
    }

    /**
     * @return array{
     *     accept: bool,
     *     reason: string,
     *     payload_kind: string,
     *     replace_items: bool,
     *     advance_full_raw_timestamp: bool,
     *     stored_timestamp_utc: ?string,
     *     incoming_timestamp_utc: ?string
     * }
     */
    public function evaluateOrderUpdate(
        MeliOrder $existing,
        array $incomingOrderData,
        ?int $incomingAccountId = null,
    ): array {
        $classification = $this->classifyOrderPayload($incomingOrderData);

        if (! $classification['valid']) {
            return $this->orderDecision(false, (string) $classification['reason'], self::PAYLOAD_INVALID, false, false, null, null);
        }

        // Entity identity validation: remote order_id and meli_account_id must match existing row.
        $incomingRemoteOrderId = trim((string) ($incomingOrderData['id'] ?? ''));
        $storedRemoteOrderId = trim((string) ($existing->order_id ?? ''));
        if ($storedRemoteOrderId !== '' && $incomingRemoteOrderId !== $storedRemoteOrderId) {
            return $this->orderDecision(false, 'order_identity_mismatch', $classification['kind'], false, false, null, null);
        }

        if ($incomingAccountId !== null
            && $existing->meli_account_id !== null
            && (int) $existing->meli_account_id !== $incomingAccountId) {
            return $this->orderDecision(false, 'account_identity_mismatch', $classification['kind'], false, false, null, null);
        }

        $storedItems = $this->loadStoredItemsNormalized($existing);

        // Conservative contract for explicit `order_items: []`: never wipe existing materialized lines.
        if ($classification['kind'] === self::PAYLOAD_EMPTY_ITEMS && $storedItems !== []) {
            return $this->orderDecision(false, 'empty_order_items_would_wipe_existing_lines', self::PAYLOAD_EMPTY_ITEMS, false, false, null, null);
        }

        $storedRaw = is_array($existing->raw) ? $existing->raw : [];
        $storedFullTs = $this->extractOrderTimestampUtc($storedRaw);
        $storedPartialTs = $this->parseUtc($storedRaw['_partial_status_last_updated'] ?? null);
        $incomingTs = $this->extractOrderTimestampUtc($incomingOrderData);

        $isPartial = $classification['kind'] === self::PAYLOAD_PARTIAL;
        $isComplete = $classification['kind'] === self::PAYLOAD_COMPLETE;

        // Effective baseline timestamp:
        // - For a PARTIAL update, compare against max(storedFullTs, storedPartialTs) so stale partial updates never regress.
        // - For a COMPLETE update, compare against storedFullTs (NOT storedPartialTs), so a complete payload arriving at
        //   the same timestamp as an earlier partial status update IS allowed to materialize lines and advance full raw!
        //   However, a complete payload strictly OLDER than storedPartialTs MUST still be rejected to prevent regressing status!
        if ($isPartial) {
            $baselineTs = $this->maxTimestamp($storedFullTs, $storedPartialTs);
        } else {
            if ($storedPartialTs !== null && $incomingTs !== null && $incomingTs->lt($storedPartialTs)) {
                return $this->orderDecision(
                    false,
                    'stale_remote_order_older_than_partial_status',
                    $classification['kind'],
                    false,
                    false,
                    $storedPartialTs->toIso8601ZuluString('microsecond'),
                    $incomingTs->toIso8601ZuluString('microsecond'),
                );
            }
            $baselineTs = $storedFullTs;
        }

        $storedIso = $baselineTs?->toIso8601ZuluString('microsecond');
        $incomingIso = $incomingTs?->toIso8601ZuluString('microsecond');

        $materialDiff = $this->orderMaterialDiffers(
            $existing,
            $incomingOrderData,
            $classification['normalized_items'],
            $storedItems,
        );

        $replaceItems = $isComplete;
        $advanceFullRaw = ! $isPartial;

        if ($baselineTs !== null && $incomingTs !== null) {
            if ($incomingTs->lt($baselineTs)) {
                return $this->orderDecision(false, 'stale_remote_order', $classification['kind'], false, false, $storedIso, $incomingIso);
            }

            if ($incomingTs->gt($baselineTs)) {
                return $this->orderDecision(true, 'newer_remote_order', $classification['kind'], $replaceItems, $advanceFullRaw, $storedIso, $incomingIso);
            }

            // Exact same UTC timestamp.
            if ($materialDiff) {
                return $this->orderDecision(false, 'ambiguous_equal_timestamp_order_conflict', $classification['kind'], false, false, $storedIso, $incomingIso);
            }

            return $this->orderDecision(true, 'same_timestamp_no_material_conflict', $classification['kind'], $replaceItems, $advanceFullRaw, $storedIso, $incomingIso);
        }

        if ($baselineTs !== null && $incomingTs === null) {
            return $this->orderDecision(
                false,
                $materialDiff ? 'incoming_order_timestamp_missing_with_conflict' : 'incoming_order_timestamp_missing',
                $classification['kind'],
                false,
                false,
                $storedIso,
                null,
            );
        }

        if ($baselineTs === null && $incomingTs !== null) {
            return $this->orderDecision(true, 'upgraded_to_timestamped_remote_order', $classification['kind'], $replaceItems, $advanceFullRaw, null, $incomingIso);
        }

        // Neither stored nor incoming has a verifiable remote timestamp.
        if ($materialDiff) {
            return $this->orderDecision(false, 'ambiguous_missing_timestamp_order_conflict', $classification['kind'], false, false, null, null);
        }

        return $this->orderDecision(true, 'untimestamped_no_material_conflict', $classification['kind'], $replaceItems, $advanceFullRaw, null, null);
    }

    /**
     * Merges a partial order payload into stored `raw` WITHOUT advancing `last_updated`/`date_last_updated`
     * beyond the last complete-items timestamp, while recording `_partial_status_last_updated` in UTC.
     *
     * @return array<string, mixed>
     */
    public function mergePartialOrderRaw(array $storedRaw, array $partialOrderData): array
    {
        $merged = $storedRaw;

        foreach (['status', 'display_id', 'feedback', 'tags', 'shipping', 'mediations', 'cancel_detail'] as $key) {
            if (array_key_exists($key, $partialOrderData) && $partialOrderData[$key] !== null) {
                $merged[$key] = $partialOrderData[$key];
            }
        }

        $incomingTs = $this->extractOrderTimestampUtc($partialOrderData);
        if ($incomingTs !== null) {
            $existingPartial = $this->parseUtc($storedRaw['_partial_status_last_updated'] ?? null);
            $bestPartial = $this->maxTimestamp($existingPartial, $incomingTs);
            if ($bestPartial !== null) {
                $merged['_partial_status_last_updated'] = $bestPartial->toIso8601ZuluString('microsecond');
            }
        }

        // Preserve stored full-payload last_updated / date_last_updated intact so a full payload
        // with materialized `order_items` at the same timestamp can still update lines cleanly.
        if (array_key_exists('last_updated', $storedRaw)) {
            $merged['last_updated'] = $storedRaw['last_updated'];
        }
        if (array_key_exists('date_last_updated', $storedRaw)) {
            $merged['date_last_updated'] = $storedRaw['date_last_updated'];
        }

        return $merged;
    }

    /**
     * Evaluates shipment snapshot freshness and `shipping_id` identity independently from the order snapshot.
     *
     * @return array{
     *     accept: bool,
     *     reason: string,
     *     stored_timestamp_utc: ?string,
     *     incoming_timestamp_utc: ?string
     * }
     */
    public function evaluateShipmentUpdate(
        MeliOrder $existing,
        ?array $incomingShipment,
        ?string $incomingShippingStatus,
        ?string $incomingShippingId = null,
        bool $orderAuthorizesNewShippingId = false,
    ): array {
        $storedShippingId = trim((string) ($existing->shipping_id ?? ''));
        $resolvedIncomingShippingId = trim((string) ($incomingShippingId ?? ($incomingShipment['id'] ?? '')));

        // Validate shipment identity: never mix snapshots from two different ML shipment IDs.
        if ($storedShippingId !== '' && $resolvedIncomingShippingId !== '' && $storedShippingId !== $resolvedIncomingShippingId) {
            if (! $orderAuthorizesNewShippingId) {
                return $this->shipmentDecision(false, 'shipment_identity_mismatch', null, null);
            }

            // A newer accepted order legitimately reassigned shipping_id (A -> B):
            // accept B's coherent snapshot without cross-comparing timestamps of different shipment entities!
            $incomingTs = is_array($incomingShipment) ? $this->extractShipmentTimestampUtc($incomingShipment) : null;

            return $this->shipmentDecision(
                true,
                'shipping_id_replaced_by_newer_order',
                null,
                $incomingTs?->toIso8601ZuluString('microsecond'),
            );
        }

        $storedShippingRaw = is_array($existing->shipping_raw) ? $existing->shipping_raw : [];
        $storedOrderShipping = is_array(data_get($existing->raw, 'shipping')) ? (array) data_get($existing->raw, 'shipping') : [];
        $storedTs = $this->extractShipmentTimestampUtc($storedShippingRaw)
            ?? $this->extractShipmentTimestampUtc($storedOrderShipping);

        $incomingTs = is_array($incomingShipment)
            ? $this->extractShipmentTimestampUtc($incomingShipment)
            : null;

        $storedIso = $storedTs?->toIso8601ZuluString('microsecond');
        $incomingIso = $incomingTs?->toIso8601ZuluString('microsecond');

        $storedStatus = $this->normalizeStatus($existing->shipping_status);
        $incomingStatus = $this->normalizeStatus($incomingShippingStatus);

        if ($incomingStatus === null && $storedStatus !== null) {
            return $this->shipmentDecision(false, 'incoming_shipment_status_missing', $storedIso, $incomingIso);
        }

        $statusDiffers = $storedStatus !== null && $incomingStatus !== null && $storedStatus !== $incomingStatus;

        if ($storedTs !== null && $incomingTs !== null) {
            if ($incomingTs->lt($storedTs)) {
                return $this->shipmentDecision(false, 'stale_remote_shipment', $storedIso, $incomingIso);
            }

            if ($incomingTs->gt($storedTs)) {
                return $this->shipmentDecision(true, 'newer_remote_shipment', $storedIso, $incomingIso);
            }

            if ($statusDiffers) {
                return $this->shipmentDecision(false, 'ambiguous_equal_timestamp_shipment_conflict', $storedIso, $incomingIso);
            }

            return $this->shipmentDecision(true, 'same_timestamp_shipment_no_conflict', $storedIso, $incomingIso);
        }

        if ($storedTs !== null && $incomingTs === null) {
            return $this->shipmentDecision(
                false,
                $statusDiffers ? 'incoming_shipment_timestamp_missing_with_conflict' : 'incoming_shipment_timestamp_missing',
                $storedIso,
                null,
            );
        }

        if ($storedTs === null && $incomingTs !== null) {
            return $this->shipmentDecision(true, 'upgraded_to_timestamped_remote_shipment', null, $incomingIso);
        }

        if ($statusDiffers) {
            return $this->shipmentDecision(false, 'ambiguous_missing_timestamp_shipment_conflict', null, null);
        }

        return $this->shipmentDecision(true, 'untimestamped_shipment_no_conflict', null, null);
    }

    public function extractOrderTimestampUtc(array $orderData): ?CarbonImmutable
    {
        $candidates = [
            $orderData['last_updated'] ?? null,
            $orderData['date_last_updated'] ?? null,
        ];

        $best = null;
        foreach ($candidates as $raw) {
            $parsed = $this->parseUtc($raw);
            if ($parsed !== null && ($best === null || $parsed->gt($best))) {
                $best = $parsed;
            }
        }

        return $best;
    }

    public function extractShipmentTimestampUtc(array $shipmentData): ?CarbonImmutable
    {
        $candidates = [
            $shipmentData['last_updated'] ?? null,
            $shipmentData['date_last_updated'] ?? null,
        ];

        $history = $shipmentData['status_history'] ?? null;
        if (is_array($history)) {
            foreach ($history as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $candidates[] = $value;
                }
            }
        }

        $best = null;
        foreach ($candidates as $raw) {
            $parsed = $this->parseUtc($raw);
            if ($parsed !== null && ($best === null || $parsed->gt($best))) {
                $best = $parsed;
            }
        }

        return $best;
    }

    /**
     * Strict ISO-8601 UTC timestamp parser: rejects impossible calendar dates (e.g. 2026-02-30),
     * out-of-range times, missing timezone offsets, and trailing garbage.
     */
    public function parseUtc(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|([+-])(\d{2}):?(\d{2}))$/';
        if (preg_match($pattern, $trimmed, $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) $m[4];
        $minute = (int) $m[5];
        $second = (int) $m[6];

        if (! checkdate($month, $day, $year)) {
            return null;
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        if ($m[8] !== 'Z') {
            $tzHour = (int) $m[10];
            $tzMin = (int) $m[11];
            if ($tzHour > 14 || $tzMin > 59 || ($tzHour === 14 && $tzMin > 0)) {
                return null;
            }
        }

        try {
            $dt = new DateTimeImmutable($trimmed);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return CarbonImmutable::instance($dt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{item_id: string, variation_id: ?string, quantity: int}>|null  $incomingItems
     * @param  list<array{item_id: string, variation_id: ?string, quantity: int}>  $storedItems
     */
    private function orderMaterialDiffers(
        MeliOrder $existing,
        array $incomingOrderData,
        ?array $incomingItems,
        array $storedItems,
    ): bool {
        $storedStatus = $this->normalizeStatus($existing->status);
        $incomingStatus = $this->normalizeStatus(is_string($incomingOrderData['status'] ?? null) ? $incomingOrderData['status'] : null);

        if ($storedStatus !== null && $incomingStatus !== null && $storedStatus !== $incomingStatus) {
            return true;
        }

        if ($incomingItems === null) {
            return false;
        }

        // If stored had no items yet (partial row), incoming complete items is additive.
        if ($storedItems === [] && $incomingItems !== []) {
            return false;
        }

        return $storedItems !== $incomingItems;
    }

    /**
     * @return list<array{item_id: string, variation_id: ?string, quantity: int}>
     */
    private function loadStoredItemsNormalized(MeliOrder $existing): array
    {
        if (! $existing->relationLoaded('items')) {
            if (! Schema::hasTable('meli_order_items')) {
                return [];
            }
            $existing->load('items');
        }

        $stored = [];
        foreach ($existing->items as $item) {
            $varRaw = $item->variation_id ?? null;
            $stored[] = [
                'item_id' => trim((string) ($item->item_id ?? '')),
                'variation_id' => (is_scalar($varRaw) && trim((string) $varRaw) !== '') ? trim((string) $varRaw) : null,
                'quantity' => (int) ($item->quantity ?? 0),
            ];
        }

        return $this->sortItems($stored);
    }

    /**
     * @param  list<array{item_id: string, variation_id: ?string, quantity: int}>  $items
     * @return list<array{item_id: string, variation_id: ?string, quantity: int}>
     */
    private function sortItems(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            return [$a['item_id'], (string) ($a['variation_id'] ?? ''), $a['quantity']]
                <=> [$b['item_id'], (string) ($b['variation_id'] ?? ''), $b['quantity']];
        });

        return $items;
    }

    private function maxTimestamp(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a->gte($b) ? $a : $b;
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim($status));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array{
     *     accept: bool,
     *     reason: string,
     *     payload_kind: string,
     *     replace_items: bool,
     *     advance_full_raw_timestamp: bool,
     *     stored_timestamp_utc: ?string,
     *     incoming_timestamp_utc: ?string
     * }
     */
    private function orderDecision(
        bool $accept,
        string $reason,
        string $payloadKind,
        bool $replaceItems,
        bool $advanceFullRawTimestamp,
        ?string $storedIso,
        ?string $incomingIso,
    ): array {
        return [
            'accept' => $accept,
            'reason' => $reason,
            'payload_kind' => $payloadKind,
            'replace_items' => $replaceItems,
            'advance_full_raw_timestamp' => $advanceFullRawTimestamp,
            'stored_timestamp_utc' => $storedIso,
            'incoming_timestamp_utc' => $incomingIso,
        ];
    }

    /**
     * @return array{
     *     accept: bool,
     *     reason: string,
     *     stored_timestamp_utc: ?string,
     *     incoming_timestamp_utc: ?string
     * }
     */
    private function shipmentDecision(
        bool $accept,
        string $reason,
        ?string $storedIso,
        ?string $incomingIso,
    ): array {
        return [
            'accept' => $accept,
            'reason' => $reason,
            'stored_timestamp_utc' => $storedIso,
            'incoming_timestamp_utc' => $incomingIso,
        ];
    }
}
