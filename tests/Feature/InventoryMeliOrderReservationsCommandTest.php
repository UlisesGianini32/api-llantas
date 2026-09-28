<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InventoryMeliOrderReservationsCommandTest extends TestCase
{
    public function test_apply_requires_a_single_remote_order_id(): void
    {
        $this->assertSame(1, Artisan::call('inventory:meli-order-reservations', ['--apply' => true]));
        $this->assertStringContainsString('--apply requiere --order', Artisan::output());
    }
}
