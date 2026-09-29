<?php

namespace App\Services;

use App\Models\MeliOrder;
use Carbon\CarbonImmutable;
use Throwable;

class InventoryMeliOrderReservationCutover
{
    public function configuredValue(): ?string
    {
        $value = trim((string) config(
            'inventory.meli_order_reservations.automatic_after',
            ''
        ));

        return $value !== '' ? $value : null;
    }

    public function configuredAt(): ?CarbonImmutable
    {
        $value = $this->configuredValue();

        if ($value === null || ! $this->hasExplicitTimezone($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function orderCreatedAt(MeliOrder $order): ?CarbonImmutable
    {
        $raw = $order->raw;

        if (! is_array($raw)) {
            return null;
        }

        $value = data_get($raw, 'date_created');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! $this->hasExplicitTimezone($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function allows(MeliOrder $order): bool
    {
        $cutover = $this->configuredAt();
        $createdAt = $this->orderCreatedAt($order);

        if (! $cutover || ! $createdAt) {
            return false;
        }

        return $createdAt->greaterThanOrEqualTo($cutover);
    }

    private function hasExplicitTimezone(string $value): bool
    {
        return preg_match(
            '/(?:Z|[+\-]\d{2}:\d{2})$/i',
            trim($value)
        ) === 1;
    }
}
