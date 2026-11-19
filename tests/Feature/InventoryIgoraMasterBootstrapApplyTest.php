<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Inventory\Igora\IgoraMasterBootstrapApplyService;
use App\Services\Inventory\Igora\IgoraMasterBootstrapPreviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class InventoryIgoraMasterBootstrapApplyTest extends TestCase
{
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        $this->createSchema();
        Http::fake();
        Queue::fake();

        $this->operator = User::create([
            'name' => 'Operador Inventario',
            'email' => 'operador-igora@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->operator->forceFill(['role' => User::ROLE_ADMIN])->save();
    }

    public function test_default_execution_is_dry_run_without_writes_or_http_calls(): void
    {
        $this->seedConfirmedLocations();

        // Confirmar separación estricta entre comando read-only (PASO 30) y comando controlado (PASO 33)
        $commands = Artisan::all();
        $this->assertArrayHasKey('inventory:igora-master-preview', $commands);
        $this->assertArrayHasKey('inventory:igora-master-bootstrap', $commands);
        $this->assertFalse($commands['inventory:igora-master-preview']->getDefinition()->hasOption('apply'));
        $this->assertTrue($commands['inventory:igora-master-bootstrap']->getDefinition()->hasOption('apply'));

        // Ejecución predeterminada en modo tabla (dry-run)
        $exitTable = Artisan::call('inventory:igora-master-bootstrap');
        $this->assertSame(0, $exitTable);
        $tableOutput = Artisan::output();
        $this->assertStringContainsString('DRY_RUN_READY', $tableOutput);
        $this->assertStringContainsString('is_active = false', $tableOutput);

        // Ejecución predeterminada en JSON (dry-run)
        $exitJson = Artisan::call('inventory:igora-master-bootstrap', ['--json' => true]);
        $this->assertSame(0, $exitJson);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('DRY_RUN', $report['mode']);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_DRY_RUN_READY, $report['outcome']);
        $this->assertFalse($report['writes_executed']);
        $this->assertSame(0, $report['products_created']);
        $this->assertSame(0, $report['movements_created']);
        $this->assertSame(0, $report['total_units_written']);
        $this->assertFalse($report['product_initial_is_active']);
        $this->assertFalse($report['apply_gate']['gate_passed']);

        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_kit_components', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_apply_requires_proposal_id_explicit_confirmation_and_valid_count_reconfirmation(): void
    {
        $this->seedConfirmedLocations();

        // 1) Sin --proposal, sin --confirm y sin --reconfirmed-counts-at
        $exit1 = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit1);
        $r1 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_BLOCKED, $r1['outcome']);
        $this->assertContains('INVALID_OR_MISSING_PROPOSAL_ID', $r1['blocking_reasons']);
        $this->assertContains('EXPLICIT_CONFIRMATION_REQUIRED', $r1['blocking_reasons']);
        $this->assertContains('COUNTS_RECONFIRMATION_REQUIRED', $r1['blocking_reasons']);

        // 2) Proposal ID incorrecto
        $exit2 = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => 'PROPOSAL-INCORRECTO',
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:00:00',
            '--json' => true,
        ]);
        $this->assertSame(1, $exit2);
        $r2 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('INVALID_OR_MISSING_PROPOSAL_ID', $r2['blocking_reasons']);

        // 3) Fecha de reconfirmación anterior al conteo físico (2026-11-18 < 2026-11-19)
        $exit3 = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-18 23:59:59',
            '--json' => true,
        ]);
        $this->assertSame(1, $exit3);
        $r3 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('COUNTS_RECONFIRMATION_BEFORE_COUNT_DATE', $r3['blocking_reasons']);

        // 4) Usuario responsable inexistente
        $exit4 = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:00:00',
            '--user-id' => 99999,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit4);
        $r4 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('ACTOR_USER_NOT_FOUND', $r4['blocking_reasons']);

        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_authorized_apply_creates_exactly_six_inactive_simple_products_and_89_initial_units_without_touching_meli(): void
    {
        $this->seedConfirmedLocations();

        $exit = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--user-id' => $this->operator->id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('APPLY', $report['mode']);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_APPLIED, $report['outcome']);
        $this->assertTrue($report['writes_executed']);
        $this->assertSame(6, $report['products_created']);
        $this->assertSame(6, $report['movements_created']);
        $this->assertSame(89, $report['total_units_written']);
        $this->assertFalse($report['external_http_calls']);
        $this->assertSame(0, $report['channel_links_created']);
        $this->assertSame(0, $report['bom_components_created']);
        $this->assertSame(0, $report['reservations_created']);
        $this->assertSame(0, $report['sync_jobs_dispatched']);

        $this->assertDatabaseCount('inventory_products', 6);
        $this->assertDatabaseCount('inventory_movements', 6);
        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_kit_components', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);

        $this->assertSame(89, (int) InventoryMovement::query()->sum('quantity'));

        $expectedBySku = [
            '7702045538977' => ['tone' => '4-13', 'qty' => 11, 'loc' => 342],
            '7702045552393' => ['tone' => '4-46', 'qty' => 12, 'loc' => 342],
            '7702045538946' => ['tone' => '4-63', 'qty' => 14, 'loc' => 351],
            '7702045119718' => ['tone' => '7-24', 'qty' => 10, 'loc' => 343],
            '7702045548860' => ['tone' => '7-50', 'qty' => 28, 'loc' => 343],
            '7702045548877' => ['tone' => '8-50', 'qty' => 14, 'loc' => 361],
        ];

        foreach ($expectedBySku as $skuKey => $spec) {
            $sku = (string) $skuKey;
            /** @var InventoryProduct $product */
            $product = InventoryProduct::query()->where('sku', $sku)->firstOrFail();
            $this->assertSame($sku, $product->barcode);
            $this->assertNull($product->barcode_secondary);
            $this->assertSame(InventoryProduct::SIMPLE, $product->product_type);
            $this->assertSame('Schwarzkopf', $product->brand);
            $this->assertSame($spec['loc'], (int) $product->primary_location_id);
            $this->assertFalse($product->is_active, "Producto {$sku} debe nacer inactivo comercialmente");
            $this->assertNull($product->cost);
            $this->assertNull($product->supplier);
            $this->assertNull($product->price_mercado_libre);
            $this->assertNull($product->price_amazon);
            $this->assertNull($product->price_stylist);
            $this->assertNull($product->price_public);

            /** @var InventoryMovement $movement */
            $movement = InventoryMovement::query()->where('inventory_product_id', $product->id)->firstOrFail();
            $this->assertSame(InventoryMovement::INITIAL, $movement->type);
            $this->assertSame($spec['qty'], (int) $movement->quantity);
            $this->assertSame($spec['loc'], (int) $movement->inventory_location_id);
            $this->assertSame('igora_master_bootstrap', $movement->reference_type);
            $this->assertSame(IgoraMasterBootstrapPreviewService::PROPOSAL_ID, $movement->reference);
            $this->assertSame('2026-11-19 00:00:00', $movement->occurred_at->format('Y-m-d H:i:s'));
            $this->assertSame($this->operator->id, (int) $movement->created_by);
            $this->assertSame(
                IgoraMasterBootstrapPreviewService::PROPOSAL_ID.':INITIAL:'.$sku,
                $movement->external_key
            );
            $this->assertSame('PASO_33', $movement->metadata['step'] ?? null);
            $this->assertSame($spec['tone'], $movement->metadata['tone'] ?? null);
            $this->assertFalse($movement->metadata['initial_is_active'] ?? true);
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_repeated_apply_is_idempotent_and_never_doubles_89_units(): void
    {
        $this->seedConfirmedLocations();

        $args = [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ];

        // Primera ejecución: aplica
        $this->assertSame(0, Artisan::call('inventory:igora-master-bootstrap', $args));
        $first = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_APPLIED, $first['outcome']);
        $this->assertSame(89, (int) InventoryMovement::query()->sum('quantity'));

        // Segunda ejecución: idempotente, NO vuelve a sumar las 89 unidades
        $this->assertSame(0, Artisan::call('inventory:igora-master-bootstrap', $args));
        $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_ALREADY_APPLIED, $second['outcome']);
        $this->assertFalse($second['writes_executed']);
        $this->assertSame(0, $second['products_created']);
        $this->assertSame(0, $second['movements_created']);
        $this->assertSame(0, $second['total_units_written']);

        // Dry-run posterior reconoce estado ya aplicado
        $this->assertSame(0, Artisan::call('inventory:igora-master-bootstrap', ['--json' => true]));
        $dryAfter = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_DRY_RUN_ALREADY_APPLIED, $dryAfter['outcome']);

        $this->assertDatabaseCount('inventory_products', 6);
        $this->assertDatabaseCount('inventory_movements', 6);
        $this->assertSame(89, (int) InventoryMovement::query()->sum('quantity'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rejects_apply_on_duplicate_sku_barcode_or_identity_match(): void
    {
        $this->seedConfirmedLocations();

        // Producto existente por barcode secundario para 4-63
        InventoryProduct::create([
            'sku' => 'INT-DUP-463',
            'barcode' => '9999999999001',
            'barcode_secondary' => '7702045538946',
            'name' => 'Producto duplicado por barcode secundario',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 351,
            'is_active' => true,
        ]);

        $exit = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_BLOCKED, $report['outcome']);
        $this->assertFalse($report['writes_executed']);
        $this->assertDatabaseCount('inventory_products', 1);
        $this->assertDatabaseCount('inventory_movements', 0);

        // Reemplazar por coincidencia de identidad (nombre/línea/tono) sin coincidencia de SKU/barcode
        InventoryProduct::query()->delete();
        InventoryProduct::create([
            'sku' => 'IGORA-INT-724',
            'name' => 'Tinte Schwarzkopf Igora Royal 7-24 60ml',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 343,
            'is_active' => true,
        ]);

        $exitId = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitId);
        $reportId = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_BLOCKED, $reportId['outcome']);
        $this->assertDatabaseCount('inventory_products', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_rejects_apply_on_invalid_or_inactive_locations(): void
    {
        $this->insertLocation(342, 'D3-7', 'Estante D3-7', true);
        $this->insertLocation(343, 'D3-8', 'Estante D3-8', true);
        $this->insertLocation(351, 'D4-7', 'Estante D4-7', false); // Inactiva
        $this->insertLocation(361, 'WRONG-CODE', 'Estante equivocado', true); // Código no coincide

        $exit = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_BLOCKED, $report['outcome']);
        $this->assertContains('INVALID_OR_INACTIVE_LOCATIONS', $report['blocking_reasons']);
        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_rejects_apply_on_post_count_reservations_movements_meli_orders_or_pos_sales(): void
    {
        $this->seedConfirmedLocations();

        $helperProduct = InventoryProduct::create([
            'sku' => 'AUX-COMPLETAMENTE-OTRO',
            'name' => 'Producto auxiliar ajeno',
            'brand' => 'OTRA',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 342,
            'is_active' => true,
        ]);

        // Caso A: Reserva activa relacionada con el SKU 7702045538977 (4-13)
        $res = InventoryReservation::create([
            'inventory_product_id' => $helperProduct->id,
            'inventory_location_id' => 342,
            'quantity' => 1,
            'status' => InventoryReservation::ACTIVE,
            'reference' => 'APARTADO-7702045538977',
            'external_key' => 'res:7702045538977:1',
        ]);

        $exitRes = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);
        $this->assertSame(1, $exitRes);
        $rRes = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('POST_COUNT_RESERVATION_CONFLICT', $rRes['blocking_reasons']);
        $this->assertDatabaseCount('inventory_products', 1);
        $res->delete();

        // Caso B: Movimiento posterior al conteo (2026-11-19 10:00:00) relacionado con MLM2603567103
        $mov = InventoryMovement::create([
            'inventory_product_id' => $helperProduct->id,
            'inventory_location_id' => 342,
            'type' => InventoryMovement::ADJUSTMENT_IN,
            'quantity' => 1,
            'reference' => 'DEVOLUCION-MLM2603567103',
            'occurred_at' => '2026-11-19 10:00:00',
        ]);

        $exitMov = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);
        $this->assertSame(1, $exitMov);
        $rMov = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('POST_COUNT_MOVEMENT_CONFLICT', $rMov['blocking_reasons']);
        $this->assertDatabaseCount('inventory_products', 1);
        $mov->delete();

        // Caso C: Pedido de Mercado Libre posterior al conteo para MLM2603516345
        $orderId = DB::table('meli_orders')->insertGetId([
            'order_id' => 20000123456789,
            'topic' => 'orders_v2',
            'status' => 'paid',
            'created_at' => '2026-11-19 11:15:00',
            'updated_at' => '2026-11-19 11:15:00',
        ]);
        DB::table('meli_order_items')->insert([
            'meli_order_id' => $orderId,
            'item_id' => 'MLM2603516345',
            'sku' => '7702045538977',
            'quantity' => 1,
            'created_at' => '2026-11-19 11:15:00',
            'updated_at' => '2026-11-19 11:15:00',
        ]);

        $exitOrder = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);
        $this->assertSame(1, $exitOrder);
        $rOrder = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('POST_COUNT_MELI_ORDER_CONFLICT', $rOrder['blocking_reasons']);
        $this->assertDatabaseCount('inventory_products', 1);
        DB::table('meli_order_items')->delete();
        DB::table('meli_orders')->delete();

        // Caso D: Venta POS posterior al conteo para 7702045548860 (7-50)
        $saleId = DB::table('pos_sales')->insertGetId([
            'sale_number' => 'POS-20261119-001',
            'user_id' => $this->operator->id,
            'inventory_location_id' => 343,
            'status' => 'completed',
            'created_at' => '2026-11-19 11:45:00',
            'updated_at' => '2026-11-19 11:45:00',
        ]);
        DB::table('pos_sale_items')->insert([
            'pos_sale_id' => $saleId,
            'inventory_product_id' => $helperProduct->id,
            'product_type' => InventoryProduct::SIMPLE,
            'sku' => '7702045548860',
            'barcode' => '7702045548860',
            'product_name' => 'Tinte Igora Royal 7-50',
            'quantity' => 1,
            'unit_price' => 150,
            'discount' => 0,
            'subtotal' => 150,
            'created_at' => '2026-11-19 11:45:00',
            'updated_at' => '2026-11-19 11:45:00',
        ]);

        $exitPos = Artisan::call('inventory:igora-master-bootstrap', [
            '--apply' => true,
            '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            '--confirm' => true,
            '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
            '--json' => true,
        ]);
        $this->assertSame(1, $exitPos);
        $rPos = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('POST_COUNT_POS_SALE_CONFLICT', $rPos['blocking_reasons']);
        $this->assertDatabaseCount('inventory_products', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_full_transaction_rollback_on_mid_execution_failure_leaves_zero_writes(): void
    {
        $this->seedConfirmedLocations();

        /** @var IgoraMasterBootstrapApplyService $service */
        $service = app(IgoraMasterBootstrapApplyService::class);
        $persistedBeforeFailure = 0;

        try {
            $service->execute([
                'apply' => true,
                'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                'confirmed' => true,
                'reconfirmed_counts_at' => '2026-11-19 12:30:00',
                'actor_user_id' => $this->operator->id,
                'after_item_persisted' => function (int $index) use (&$persistedBeforeFailure): void {
                    $persistedBeforeFailure++;
                    if ($index === 2) {
                        throw new RuntimeException('Fallo simulado tras persistir el 3er producto');
                    }
                },
            ]);
            $this->fail('Se esperaba excepción para validar rollback atómico.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo simulado tras persistir el 3er producto', $e->getMessage());
        }

        // Confirmar que efectivamente alcanzó a persistir 3 ítems dentro de la transacción y luego hizo rollback total
        $this->assertSame(3, $persistedBeforeFailure);
        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_channel_links', 0);

        // El lock fue liberado en finally: una ejecución limpia posterior sí funciona
        $clean = $service->execute([
            'apply' => true,
            'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            'confirmed' => true,
            'reconfirmed_counts_at' => '2026-11-19 12:35:00',
            'actor_user_id' => $this->operator->id,
        ]);
        $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_APPLIED, $clean['outcome']);
        $this->assertDatabaseCount('inventory_products', 6);
        $this->assertDatabaseCount('inventory_movements', 6);
        $this->assertSame(89, (int) InventoryMovement::query()->sum('quantity'));
    }

    public function test_blocks_concurrent_execution_when_cache_lock_is_held(): void
    {
        $this->seedConfirmedLocations();

        $externalLock = Cache::lock(IgoraMasterBootstrapApplyService::LOCK_KEY, 60);
        $this->assertTrue($externalLock->get());

        try {
            $exit = Artisan::call('inventory:igora-master-bootstrap', [
                '--apply' => true,
                '--proposal' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                '--confirm' => true,
                '--reconfirmed-counts-at' => '2026-11-19 12:30:00',
                '--json' => true,
            ]);

            $this->assertSame(1, $exit);
            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(IgoraMasterBootstrapApplyService::OUTCOME_LOCK_CONFLICT, $report['outcome']);
            $this->assertContains('CONCURRENT_EXECUTION_LOCK_ACTIVE', $report['blocking_reasons']);
            $this->assertFalse($report['writes_executed']);
            $this->assertDatabaseCount('inventory_products', 0);
            $this->assertDatabaseCount('inventory_movements', 0);
        } finally {
            $externalLock->release();
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function seedConfirmedLocations(): void
    {
        $this->insertLocation(342, 'D3-7', 'Estante D3-7', true);
        $this->insertLocation(343, 'D3-8', 'Estante D3-8', true);
        $this->insertLocation(351, 'D4-7', 'Estante D4-7', true);
        $this->insertLocation(361, 'D5-8', 'Estante D5-8', true);
    }

    private function insertLocation(int $id, string $code, string $name, bool $isActive): void
    {
        DB::table('inventory_locations')->insert([
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'is_active' => $isActive,
            'sort_order' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32)->default('operations');
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_25_000001_create_inventory_channel_links_table.php'))->up();
        (require database_path('migrations/2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        if (! Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled')) {
            Schema::table('inventory_channel_links', function (Blueprint $table): void {
                $table->boolean('order_reservation_enabled')->default(false);
            });
        }
        (require database_path('migrations/2026_09_30_000001_create_pos_sales_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_10_05_160000_add_secondary_location_and_amazon_locations.php'))->up();
        (require database_path('migrations/2026_10_07_183419_add_barcode_secondary_to_inventory_products_table.php'))->up();
        (require database_path('migrations/2026_02_26_204503_create_meli_orders_table.php'))->up();
        (require database_path('migrations/2026_02_26_204504_create_meli_order_items_table.php'))->up();
    }
}
