<?php

namespace App\Services;

class InventoryMeliOrderReservationPolicy
{
    public const RESERVABLE = 'RESERVABLE';
    public const RELEASE = 'RELEASE';
    public const IGNORE = 'IGNORE';

    /** Local sync stores Mercado Libre order.status directly: only these meanings are safe. */
    public function classify(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'paid' => self::RESERVABLE,
            'cancelled' => self::RELEASE,
            default => self::IGNORE,
        };
    }
}
