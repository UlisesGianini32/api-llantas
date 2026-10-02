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

        // Casos de cumplimiento automático por estado de envío
        $this->assertSame(InventoryMeliOrderReservationPolicy::FULFILL, $policy->classify('paid', 'shipped'));
        $this->assertSame(InventoryMeliOrderReservationPolicy::FULFILL, $policy->classify('paid', 'delivered'));
        $this->assertSame(InventoryMeliOrderReservationPolicy::FULFILL, $policy->classify('paid', 'in_transit'));
        $this->assertSame(InventoryMeliOrderReservationPolicy::RESERVABLE, $policy->classify('paid', 'ready_to_ship'));
        $this->assertSame(InventoryMeliOrderReservationPolicy::RESERVABLE, $policy->classify('paid', 'pending'));
        // Si se canceló, siempre se libera aunque tenga shipping_status
        $this->assertSame(InventoryMeliOrderReservationPolicy::RELEASE, $policy->classify('cancelled', 'shipped'));
    }
}
