<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Pos\PosReceiptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosShiftAndThermalPrintTest extends TestCase
{
    private User $cashier;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->string('meli_id')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Run inventory and POS migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000001_create_pos_sales_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000002_create_pos_shifts_tables.php'))->up();

        $this->cashier = User::forceCreate([
            'name' => 'Ulises Barbero',
            'email' => 'ulises@barbersupply.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::forceCreate([
            'code' => 'MOSTRADOR',
            'name' => 'Mostrador Principal',
            'is_active' => true,
        ]);
    }

    public function test_cashier_can_open_shift_and_cannot_open_duplicate(): void
    {
        $response = $this->actingAs($this->cashier)->postJson(route('pos.shifts.open'), [
            'inventory_location_id' => $this->location->id,
            'opening_cash' => 500.00,
            'notes' => 'Fondo inicial para cambio en billetes chicos',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('shift.opening_cash', '500.00')
            ->assertJsonPath('shift.status', 'OPEN');

        $this->assertDatabaseHas('pos_shifts', [
            'cashier_id' => $this->cashier->id,
            'inventory_location_id' => $this->location->id,
            'status' => 'OPEN',
            'opening_cash' => 500.00,
        ]);

        // Attempting to open another shift with the same cashier should fail
        $duplicateResponse = $this->actingAs($this->cashier)->postJson(route('pos.shifts.open'), [
            'inventory_location_id' => $this->location->id,
            'opening_cash' => 300.00,
        ]);

        $duplicateResponse->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_cashier_can_register_cash_movements(): void
    {
        $shift = PosShift::create([
            'inventory_location_id' => $this->location->id,
            'cashier_id' => $this->cashier->id,
            'status' => PosShift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash' => 500.00,
        ]);

        // 1. Ingreso extra de efectivo (IN)
        $inResponse = $this->actingAs($this->cashier)->postJson(route('pos.shifts.movement', $shift), [
            'type' => 'IN',
            'amount' => 200.00,
            'reason' => 'Cambio de monedas traído de banco',
        ]);

        $inResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('movement.amount', '200.00');

        // 2. Retiro extraordinario de efectivo (OUT)
        $outResponse = $this->actingAs($this->cashier)->postJson(route('pos.shifts.movement', $shift), [
            'type' => 'OUT',
            'amount' => 150.00,
            'reason' => 'Pago de taxi paquetería',
        ]);

        $outResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('movement.amount', '150.00');

        $this->assertDatabaseCount('pos_cash_movements', 2);

        // Validar resumen
        $summaryResponse = $this->actingAs($this->cashier)->getJson(route('pos.shifts.summary', $shift));
        $summaryResponse->assertOk()
            ->assertJsonPath('summary.cash_in', 200)
            ->assertJsonPath('summary.cash_out', 150)
            ->assertJsonPath('summary.expected_cash', 550); // 500 + 200 - 150
    }

    public function test_sales_are_automatically_associated_with_active_shift(): void
    {
        $shift = PosShift::create([
            'inventory_location_id' => $this->location->id,
            'cashier_id' => $this->cashier->id,
            'status' => PosShift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash' => 1000.00,
        ]);

        $product = InventoryProduct::create([
            'sku' => 'WAHL-CLIP-01',
            'name' => 'Wahl Magic Clip Cordless',
            'product_type' => 'SIMPLE',
            'price_public' => 1800.00,
            'cost' => 1200.00,
            'is_active' => true,
        ]);

        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'reference_type' => 'initial',
            'occurred_at' => now(),
        ]);

        // Realizar venta en mostrador
        $saleResponse = $this->actingAs($this->cashier)->postJson(route('pos.sales.store'), [
            'inventory_location_id' => $this->location->id,
            'payment_method' => 'cash',
            'amount_tendered' => 2000.00,
            'items' => [
                [
                    'inventory_product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1800.00,
                ],
            ],
        ]);

        $saleResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sale.pos_shift_id', $shift->id)
            ->assertJsonPath('sale.change_due', '200.00')
            ->assertJsonStructure(['receipt' => ['text', 'escpos_base64', 'width', 'paper_type']]);

        // Consultar resumen de turno
        $summaryResponse = $this->actingAs($this->cashier)->getJson(route('pos.shifts.summary', $shift));
        $summaryResponse->assertOk()
            ->assertJsonPath('summary.sales_count', 1)
            ->assertJsonPath('summary.sales_cash', 1800)
            ->assertJsonPath('summary.expected_cash', 2800); // 1000 + 1800
    }

    public function test_cashier_can_close_shift_and_generate_corte_de_caja_with_discrepancy(): void
    {
        $shift = PosShift::create([
            'inventory_location_id' => $this->location->id,
            'cashier_id' => $this->cashier->id,
            'status' => PosShift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash' => 500.00,
        ]);

        // Simular venta en efectivo
        PosSale::create([
            'sale_number' => 'POS-TEST-001',
            'user_id' => $this->cashier->id,
            'inventory_location_id' => $this->location->id,
            'pos_shift_id' => $shift->id,
            'customer_name' => 'Cliente 1',
            'payment_method' => PosSale::PAYMENT_CASH,
            'subtotal' => 1200.00,
            'total' => 1200.00,
            'status' => PosSale::STATUS_COMPLETED,
        ]);

        // Simular venta con tarjeta
        PosSale::create([
            'sale_number' => 'POS-TEST-002',
            'user_id' => $this->cashier->id,
            'inventory_location_id' => $this->location->id,
            'pos_shift_id' => $shift->id,
            'customer_name' => 'Cliente 2',
            'payment_method' => PosSale::PAYMENT_CARD,
            'subtotal' => 850.00,
            'total' => 850.00,
            'status' => PosSale::STATUS_COMPLETED,
        ]);

        // Esperado en efectivo: 500 (fondo) + 1200 (ventas efectivo) = 1700
        // Cajero cuenta 1750 (hay un sobrante de 50)
        $closeResponse = $this->actingAs($this->cashier)->postJson(route('pos.shifts.close', $shift), [
            'closing_cash_counted' => 1750.00,
            'notes' => 'Sobrante de $50 pesos por propina de cliente dejada en mostrador',
            'paper_type' => '80mm',
        ]);

        $closeResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('shift.status', 'CLOSED')
            ->assertJsonPath('shift.closing_cash_expected', '1700.00')
            ->assertJsonPath('shift.closing_cash_counted', '1750.00')
            ->assertJsonPath('shift.difference', '50.00')
            ->assertJsonPath('shift.total_sales_amount', '2050.00')
            ->assertJsonPath('shift.total_sales_count', 2)
            ->assertJsonStructure(['receipt' => ['text', 'escpos_base64', 'width']]);

        // Comprobar que el texto del ticket contiene el desglose correcto
        $receiptText = $closeResponse->json('receipt.text');
        $this->assertStringContainsString('CORTE DE CAJA', $receiptText);
        $this->assertStringContainsString('ARQUEO DE EFECTIVO', $receiptText);
        $this->assertStringContainsString('1,700.00', $receiptText);
        $this->assertStringContainsString('1,750.00', $receiptText);
        $this->assertStringContainsString('SOBRANTE', $receiptText);
    }

    public function test_receipt_service_formats_58mm_and_80mm_for_sales_and_shifts(): void
    {
        $service = app(PosReceiptService::class);

        $shift = PosShift::create([
            'inventory_location_id' => $this->location->id,
            'cashier_id' => $this->cashier->id,
            'status' => PosShift::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
            'opening_cash' => 500.00,
            'closing_cash_expected' => 1500.00,
            'closing_cash_counted' => 1500.00,
            'difference' => 0.00,
            'total_sales_cash' => 1000.00,
            'total_sales_card' => 500.00,
            'total_sales_amount' => 1500.00,
            'total_sales_count' => 5,
        ]);

        // 80mm format test
        $cut80 = $service->formatShiftCutReceipt($shift, '80mm');
        $this->assertEquals(48, $cut80['width']);
        $this->assertNotEmpty($cut80['escpos_base64']);
        $this->assertStringContainsString('CUADRADA', $cut80['text']);

        // 58mm format test
        $cut58 = $service->formatShiftCutReceipt($shift, '58mm');
        $this->assertEquals(32, $cut58['width']);
        $this->assertNotEmpty($cut58['escpos_base64']);

        // Drawer kick command
        $drawerCommand = $service->getDrawerKickCommand();
        $this->assertNotEmpty($drawerCommand);
        $this->assertEquals("\x1b\x70\x00\x19\xfa", base64_decode($drawerCommand));
    }

    public function test_drawer_open_endpoint_returns_escpos_command(): void
    {
        $response = $this->actingAs($this->cashier)->postJson(route('pos.drawer.open'));
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['escpos_base64', 'message']);
    }
}
