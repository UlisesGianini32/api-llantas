<?php

namespace Tests\Unit;

use App\Services\InventoryMeliOrderReservationPolicy;
use PHPUnit\Framework\TestCase;

class InventoryMeliOrderReservationPolicyTest extends TestCase
{
    public function test_only_known_paid_and_cancelled_states_mutate_inventory(): void
    {
        $policy = new InventoryMeliOrderReservationPolicy;
        $this->assertSame(InventoryMeliOrderReservationPolicy::RESERVABLE, $policy->classify('paid'));
        $this->assertSame(InventoryMeliOrderReservationPolicy::RELEASE, $policy->classify('cancelled'));
        foreach (['pending', 'payment_in_process', 'confirmed', 'refunded', 'partially_refunded', null, 'unknown'] as $status) {
            $this->assertSame(InventoryMeliOrderReservationPolicy::IGNORE, $policy->classify($status));
        }
    }
}
