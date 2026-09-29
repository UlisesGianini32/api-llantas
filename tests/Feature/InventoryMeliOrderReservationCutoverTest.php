<?php

namespace Tests\Feature;

use App\Models\MeliOrder;
use App\Services\InventoryMeliOrderReservationCutover;
use Tests\TestCase;

class InventoryMeliOrderReservationCutoverTest extends TestCase
{
    public function test_missing_cutover_fails_closed(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            null
        );

        $this->assertFalse(
            app(InventoryMeliOrderReservationCutover::class)
                ->allows($this->order('2026-09-29T12:00:00-07:00'))
        );
    }

    public function test_invalid_cutover_fails_closed(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2026-09-29 12:00:00'
        );

        $this->assertFalse(
            app(InventoryMeliOrderReservationCutover::class)
                ->allows($this->order('2026-09-29T12:00:00-07:00'))
        );
    }

    public function test_missing_or_invalid_remote_creation_date_fails_closed(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2026-09-29T12:00:00-07:00'
        );

        $service = app(InventoryMeliOrderReservationCutover::class);

        $this->assertFalse($service->allows($this->order(null)));
        $this->assertFalse($service->allows($this->order('not-a-date')));
    }

    public function test_order_before_cutover_is_rejected(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2026-09-29T12:00:00-07:00'
        );

        $this->assertFalse(
            app(InventoryMeliOrderReservationCutover::class)
                ->allows($this->order('2026-09-29T11:59:59-07:00'))
        );
    }

    public function test_equal_instant_with_different_timezone_is_allowed(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2026-09-29T12:00:00-07:00'
        );

        $this->assertTrue(
            app(InventoryMeliOrderReservationCutover::class)
                ->allows($this->order('2026-09-29T15:00:00-04:00'))
        );
    }

    public function test_order_after_cutover_is_allowed(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2026-09-29T12:00:00-07:00'
        );

        $this->assertTrue(
            app(InventoryMeliOrderReservationCutover::class)
                ->allows($this->order('2026-09-29T12:00:01-07:00'))
        );
    }

    private function order(?string $dateCreated): MeliOrder
    {
        return new MeliOrder([
            'raw' => $dateCreated === null
                ? []
                : ['date_created' => $dateCreated],
        ]);
    }
}
