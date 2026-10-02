<?php

namespace Tests\Feature;

use App\Models\InventoryKitComponent;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\User;
use App\Services\InventoryKitService;
use App\Support\InventoryActorPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryActorPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_displays_user_name_when_created_by_user(): void
    {
        $user = User::factory()->create(['name' => 'Gerardo Valdez']);
        $product = InventoryProduct::create([
            'name' => 'Producto Prueba',
            'sku' => 'SKU-PRUEBA',
            'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'name' => 'Almacén 1',
            'code' => 'A1',
            'is_active' => true,
        ]);

        $movement = InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'occurred_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->assertSame('Gerardo Valdez', InventoryActorPresenter::labelForMovement($movement));
    }

    public function test_displays_meli_account_principal_and_secundaria(): void
    {
        $user = User::factory()->create();

        $principalAccount = MeliAccount::create([
            'user_id' => $user->id,
            'meli_user_id' => '11111',
            'nickname' => 'BEAUTY_MAIN',
            'is_default' => true,
        ]);

        $secundariaAccount = MeliAccount::create([
            'user_id' => $user->id,
            'meli_user_id' => '22222',
            'nickname' => 'BEAUTY_SEC',
            'is_default' => false,
        ]);

        $orderPrincipal = MeliOrder::create([
            'meli_account_id' => $principalAccount->id,
            'order_id' => '2000018746705782',
            'status' => 'paid',
        ]);

        $orderSecundaria = MeliOrder::create([
            'meli_account_id' => $secundariaAccount->id,
            'order_id' => '2000018725664078',
            'status' => 'paid',
        ]);

        $product = InventoryProduct::create([
            'name' => 'Shampoo',
            'sku' => 'SH-01',
            'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'name' => 'Almacén 1',
            'code' => 'A1',
            'is_active' => true,
        ]);

        $reservation1 = InventoryReservation::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'quantity' => 1,
            'status' => InventoryReservation::ACTIVE,
            'source_type' => 'meli_order',
            'source_id' => $orderPrincipal->id,
            'reference' => 'ML orden 2000018746705782 linea 1',
            'metadata' => [
                'channel' => 'mercado_libre',
                'account_key' => (string) $principalAccount->id,
            ],
        ]);

        $movement2 = InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -1,
            'occurred_at' => now(),
            'reference' => 'ML orden 2000018725664078 línea 2',
        ]);

        $this->assertStringContainsString('Principal', InventoryActorPresenter::labelForReservation($reservation1));
        $this->assertStringContainsString('Secundaria', InventoryActorPresenter::labelForMovement($movement2));
    }

    public function test_displays_amazon_and_shopify(): void
    {
        $product = InventoryProduct::create([
            'name' => 'Tratamiento',
            'sku' => 'TRAT-01',
            'is_active' => true,
        ]);
        $location = InventoryLocation::create([
            'name' => 'Almacén 1',
            'code' => 'A1',
            'is_active' => true,
        ]);

        $amazonMovement = InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -1,
            'occurred_at' => now(),
            'reference' => 'Amazon order 111-2222222-3333333 item 1',
            'metadata' => ['channel' => 'amazon'],
        ]);

        $shopifyReservation = InventoryReservation::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'quantity' => 1,
            'status' => InventoryReservation::ACTIVE,
            'reference' => 'Shopify order #1050 line 1',
            'metadata' => ['channel' => 'shopify'],
        ]);

        $this->assertSame('Amazon', InventoryActorPresenter::labelForMovement($amazonMovement));
        $this->assertSame('Shopify', InventoryActorPresenter::labelForReservation($shopifyReservation));
    }

    public function test_fulfilling_kit_component_fulfills_parent_kit_and_all_components(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $location = InventoryLocation::create([
            'name' => 'G1-2',
            'code' => 'G1-2',
            'is_active' => true,
        ]);

        $comp1 = InventoryProduct::create(['name' => 'Champú', 'sku' => 'CH-1', 'is_active' => true]);
        $comp2 = InventoryProduct::create(['name' => 'Acondicionador', 'sku' => 'AC-1', 'is_active' => true]);

        InventoryMovement::create([
            'inventory_product_id' => $comp1->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'occurred_at' => now(),
        ]);
        InventoryMovement::create([
            'inventory_product_id' => $comp2->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'occurred_at' => now(),
        ]);

        $kit = InventoryProduct::create([
            'name' => 'Kit Joico',
            'sku' => 'KIT-JOICO',
            'product_type' => InventoryProduct::KIT,
            'is_active' => true,
        ]);

        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $comp1->id, 'quantity' => 1]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $comp2->id, 'quantity' => 1]);

        $kitService = app(InventoryKitService::class);
        $kitReservation = $kitService->reserve([
            'kit_product_id' => $kit->id,
            'quantity' => 1,
            'reference' => 'ML orden 2000018725664078',
        ]);

        $children = InventoryReservation::query()
            ->where('source_type', 'inventory_kit_reservation')
            ->where('source_id', $kitReservation->id)
            ->get();

        $this->assertCount(2, $children);

        // Al cumplir desde uno de los hijos en el controlador de reservas, debe cumplir todo el kit
        $response = $this->actingAs($admin)
            ->post("/almacen/reservas/{$children->first()->id}/cumplir");

        $response->assertSessionHas('success');

        $this->assertSame(
            InventoryReservation::FULFILLED,
            $kitReservation->fresh()->status
        );
        foreach ($children as $child) {
            $this->assertSame(
                InventoryReservation::FULFILLED,
                $child->fresh()->status
            );
        }
    }
}
