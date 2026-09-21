<?php

namespace App\Services\MercadoLibre\Orders;

final class MeliOrderDeliveryClassifier
{
    public const AGREED_WITH_BUYER = 'agreed_with_buyer';

    public const CUSTOM_SHIPPING = 'custom_shipping';

    public const MERCADO_ENVIOS = 'mercado_envios';

    public const PENDING_SHIPMENT = 'pending_shipment';

    public const UNKNOWN = 'unknown';

    /** @var list<string> */
    private const LOGISTIC_MODES = [
        'me1',
        'me2',
        'xd_drop_off',
        'drop_off',
        'self_service',
        'cross_docking',
        'fulfillment',
        'full',
    ];

    /** @var list<string> */
    private const OPERATIONAL_ORDER_STATUSES = ['paid', 'partially_paid'];

    /**
     * @param  list<?string>  $itemShippingModes
     * @return array{delivery_type:string,shipping_mode:?string,reason:string,needs_review:bool}
     */
    public function classify(array $order, ?string $shippingId, array $itemShippingModes): array
    {
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $shippingId = $this->value($shippingId);

        if ($shippingId !== null) {
            return $this->result(self::MERCADO_ENVIOS, $this->singleMode($itemShippingModes), 'shipment_present');
        }

        if (! in_array($status, self::OPERATIONAL_ORDER_STATUSES, true)) {
            return $this->result(self::UNKNOWN, $this->singleMode($itemShippingModes), 'order_status_not_paid');
        }

        $normalizedModes = array_map(fn (?string $mode): ?string => $this->value($mode), $itemShippingModes);
        if ($normalizedModes === [] || in_array(null, $normalizedModes, true)) {
            return $this->result(self::UNKNOWN, null, 'item_shipping_mode_unavailable', true);
        }

        $modes = array_values(array_unique($normalizedModes));
        if (count($modes) !== 1) {
            return $this->result(self::UNKNOWN, null, 'mixed_item_shipping_modes', true);
        }

        $mode = $modes[0];
        if ($mode === 'custom') {
            return $this->result(self::CUSTOM_SHIPPING, $mode, 'item_shipping_mode_custom');
        }

        $packId = $this->value($order['pack_id'] ?? null);
        if ($packId !== null) {
            return $this->result(self::PENDING_SHIPMENT, $mode, 'pack_without_shipment');
        }

        if ($mode === 'not_specified') {
            return $this->result(self::AGREED_WITH_BUYER, $mode, 'paid_without_pack_or_shipment_and_not_specified');
        }

        if (in_array($mode, self::LOGISTIC_MODES, true)) {
            return $this->result(self::PENDING_SHIPMENT, $mode, 'logistic_mode_waiting_for_shipment');
        }

        return $this->result(self::UNKNOWN, $mode, 'unsupported_item_shipping_mode', true);
    }

    /** @param list<?string> $modes */
    private function singleMode(array $modes): ?string
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn (?string $mode): ?string => $this->value($mode), $modes)
        )));

        return count($normalized) === 1 ? $normalized[0] : null;
    }

    /** @return array{delivery_type:string,shipping_mode:?string,reason:string,needs_review:bool} */
    private function result(string $type, ?string $mode, string $reason, bool $needsReview = false): array
    {
        return [
            'delivery_type' => $type,
            'shipping_mode' => $mode,
            'reason' => $reason,
            'needs_review' => $needsReview,
        ];
    }

    private function value(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
