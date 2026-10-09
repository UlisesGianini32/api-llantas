<?php

namespace Tests\Feature;

use App\Models\InventoryProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryProductReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_export(): void
    {
        $response = $this->get(route('inventory.products.export'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_export_inventory_csv(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        InventoryProduct::create([
            'sku' => 'TEST-SKU-01',
            'name' => 'Aceite de Batana 40ml',
            'brand' => 'JOICO',
            'cost' => 120.00,
            'price_public' => 250.00,
        ]);

        $response = $this->get(route('inventory.products.export', ['brand' => 'JOICO']));
        $response->assertStatus(200);
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function test_authenticated_users_can_view_printable_pdf_report(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        InventoryProduct::create([
            'sku' => 'TEST-SKU-02',
            'name' => 'Shampoo K-Pak 300ml',
            'brand' => 'JOICO',
            'cost' => 180.00,
            'price_public' => 380.00,
        ]);

        $response = $this->get(route('inventory.products.report_pdf', ['brand' => 'JOICO']));
        $response->assertStatus(200);
        $response->assertSee('Reporte de Inventario Físico');
        $response->assertSee('JOICO');
    }
}
