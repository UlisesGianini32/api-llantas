<?php

namespace App\Services;

class InventoryMeliOrderReservationPolicy
{
    public const RESERVABLE = 'RESERVABLE';
    public const RELEASE = 'RELEASE';
    public const FULFILL = 'FULFILL';
    public const IGNORE = 'IGNORE';

    /**
     * Clasifica la acción de inventario según el estado de la orden y del paquete.
     */
    public function classify(?string $status, ?string $shippingStatus = null): string
    {
        $normalizedStatus = strtolower(trim((string) $status));
        $normalizedShipping = strtolower(trim((string) $shippingStatus));

        // 1. Cancelaciones siempre liberan la reserva
        if ($normalizedStatus === 'cancelled') {
            return self::RELEASE;
        }

        // 2. Si el paquete ya fue despachado o entregado por la paquetería, se cumple la reserva
        $fulfilledShippingStatuses = ['shipped', 'delivered', 'in_transit'];
        if (in_array($normalizedShipping, $fulfilledShippingStatuses, true)) {
            return self::FULFILL;
        }

        // 3. Si la orden está pagada (en preparación/empaque en bodega), se mantiene la reserva
        return match ($normalizedStatus) {
            'paid' => self::RESERVABLE,
            default => self::IGNORE,
        };
    }
}
