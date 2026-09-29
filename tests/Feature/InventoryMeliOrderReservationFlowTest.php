<?php

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Jobs\SyncInventoryMeliStockLinkJob;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitComponent;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\InventoryStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryMeliOrderReservationFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password');
            $table->string('role')->default('admin'); $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('meli_user_id');
            $table->string('nickname')->nullable(); $table->boolean('is_default')->default(false); $table->timestamps();
        });
        Schema::create('llantas', fn (Blueprint $table) => $table->id());
        foreach ([
            '2026_09_24_000001_create_inventory_products_table.php',
            '2026_09_24_000002_create_inventory_locations_table.php',
            '2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php',
            '2026_09_24_000004_create_inventory_movements_table.php',
            '2026_09_24_000005_create_inventory_reservations_table.php',
            '2026_09_24_000006_add_product_type_to_inventory_products_table.php',
            '2026_09_24_000007_create_inventory_kit_components_table.php',
            '2026_09_24_000008_create_inventory_kit_reservations_table.php',
            '2026_09_25_000001_create_inventory_channel_links_table.php',
            '2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php',
            '2026_09_25_000003_create_inventory_channel_stock_syncs_table.php',
            '2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php',
        ] as $file) (require database_path('migrations/'.$file))->up();
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id'); $table->string('status')->nullable(); $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id', 30); $table->string('sku')->nullable(); $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->nullable(); $table->timestamps();
            $table->unique(['meli_order_id', 'item_id']);
        });
        (require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (['inventory_channel_order_allocations', 'meli_order_items', 'meli_orders', 'inventory_channel_stock_syncs',
            'inventory_channel_links', 'inventory_kit_reservations', 'inventory_kit_components', 'inventory_reservations',
            'inventory_movements'] as $table) Schema::dropIfExists($table);
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropForeign(['primary_location_id']); $table->dropIndex(['product_type']);
        });
        foreach (['inventory_products', 'inventory_locations', 'llantas', 'meli_accounts', 'users'] as $table) Schema::dropIfExists($table);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_reserves_once_for_exact_order_line_and_ignores_stock_sync_ownership(): void
    {
        [$product, $location] = $this->stockedProduct('SKU-A', 8);
        $this->link($product, 1, 'MLM-A', null, true, true, false);
        $order = $this->order(1, 1001, 'paid', [['item_id' => 'MLM-A', 'key' => 'MLM-A:', 'qty' => 3]]);
        $service = app(InventoryMeliOrderReservationService::class);
        $service->apply($order); $service->apply($order->fresh()->load('items'));
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(3, app(InventoryStockService::class)->reservedStock($product));
        $this->assertSame(8, app(InventoryStockService::class)->physicalStock($product));
    }

    public function test_automatic_job_is_idempotent_and_releases_when_order_is_cancelled(): void
    {
        [$product] = $this->stockedProduct('SKU-AUTO', 5);
        $this->link($product, 1, 'MLM-AUTO', null, true);
        $order = $this->order(1, 1101, 'paid', [['item_id' => 'MLM-AUTO', 'key' => 'MLM-AUTO:', 'qty' => 2]]);

        $job = new ReconcileInventoryMeliOrderReservationsJob($order->id);
        $job->handle(app(InventoryMeliOrderReservationService::class));
        $job->handle(app(InventoryMeliOrderReservationService::class));
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));

        $order->update(['status' => 'cancelled']);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle(app(InventoryMeliOrderReservationService::class));
        $this->assertSame(0, InventoryReservation::active()->count());
        $this->assertGreaterThanOrEqual(1, InventoryReservation::query()->count());
    }

    public function test_automatic_job_reads_fresh_cancelled_state_before_first_execution(): void
    {
        [$product] = $this->stockedProduct('SKU-AUTO-FRESH', 3);
        $this->link($product, 1, 'MLM-AUTO-FRESH', null, true);
        $order = $this->order(1, 1102, 'paid', [['item_id' => 'MLM-AUTO-FRESH', 'key' => 'MLM-AUTO-FRESH:', 'qty' => 1]]);
        $order->update(['status' => 'cancelled']);

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle(app(InventoryMeliOrderReservationService::class));
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(0, InventoryChannelOrderAllocation::count());
    }

    public function test_automatic_job_preserves_ignore_opt_out_and_insufficient_outcomes(): void
    {
        [$ignoredProduct] = $this->stockedProduct('SKU-AUTO-IGNORED', 2);
        $this->link($ignoredProduct, 1, 'MLM-AUTO-IGNORED', null, true);
        $ignored = $this->order(1, 1103, 'pending', [['item_id' => 'MLM-AUTO-IGNORED', 'key' => 'MLM-AUTO-IGNORED:', 'qty' => 1]]);
        (new ReconcileInventoryMeliOrderReservationsJob($ignored->id))->handle(app(InventoryMeliOrderReservationService::class));

        [$optOutProduct] = $this->stockedProduct('SKU-AUTO-OPTOUT', 2);
        $this->link($optOutProduct, 1, 'MLM-AUTO-OPTOUT', null, false);
        $optOut = $this->order(1, 1104, 'paid', [['item_id' => 'MLM-AUTO-OPTOUT', 'key' => 'MLM-AUTO-OPTOUT:', 'qty' => 1]]);
        (new ReconcileInventoryMeliOrderReservationsJob($optOut->id))->handle(app(InventoryMeliOrderReservationService::class));

        [$lowStockProduct] = $this->stockedProduct('SKU-AUTO-LOW', 0);
        $this->link($lowStockProduct, 1, 'MLM-AUTO-LOW', null, true);
        $lowStock = $this->order(1, 1105, 'paid', [['item_id' => 'MLM-AUTO-LOW', 'key' => 'MLM-AUTO-LOW:', 'qty' => 1]]);
        (new ReconcileInventoryMeliOrderReservationsJob($lowStock->id))->handle(app(InventoryMeliOrderReservationService::class));

        $this->assertSame(0, InventoryReservation::active()->count());
    }

    public function test_ignored_status_is_a_safe_noop_without_prior_allocation(): void
    {
        [$product] = $this->stockedProduct('SKU-IGNORED', 5); $this->link($product, 1, 'MLM-IGNORED', null, true);
        $order = $this->order(1, 1020, 'pending', [['item_id' => 'MLM-IGNORED', 'key' => 'MLM-IGNORED:', 'qty' => 1]]);
        $service = app(InventoryMeliOrderReservationService::class);
        $this->assertSame('IGNORED_STATUS', $service->preview($order)[0]['action']);
        $service->apply($order);
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(0, InventoryChannelOrderAllocation::count());
    }

    public function test_ignored_status_does_not_change_existing_paid_reservation(): void
    {
        [$product] = $this->stockedProduct('SKU-IGNORED-AFTER-PAID', 5); $this->link($product, 1, 'MLM-IGNORED-AFTER-PAID', null, true);
        $order = $this->order(1, 1021, 'paid', [['item_id' => 'MLM-IGNORED-AFTER-PAID', 'key' => 'MLM-IGNORED-AFTER-PAID:', 'qty' => 1]]);
        $service = app(InventoryMeliOrderReservationService::class); $service->apply($order);
        $before = InventoryChannelOrderAllocation::sole();
        $order->update(['status' => 'confirmed']);
        $ignored = $service->apply($order->fresh()->load('items'))[0];
        $after = $before->fresh();
        $this->assertSame('IGNORED_STATUS', $ignored['action']);
        $this->assertSame(InventoryReservation::ACTIVE, InventoryReservation::findOrFail($after->reservation_id)->status);
        $this->assertSame(1, $after->quantity);
        $this->assertSame($before->reservation_id, $after->reservation_id);
        $this->assertSame($before->reservation_version, $after->reservation_version);
        $service->apply($order->fresh()->load('items'));
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(1, InventoryChannelOrderAllocation::count());
    }

    public function test_legacy_line_is_not_guessed_but_new_simple_identity_maps_to_null_variant(): void
    {
        [$product] = $this->stockedProduct('SKU-LEGACY', 6);
        $this->link($product, 1, 'MLM-L', null, true);
        $order = $this->order(1, 1002, 'paid', [['item_id' => 'MLM-L', 'key' => null, 'qty' => 1]]);
        $service = app(InventoryMeliOrderReservationService::class);
        $this->assertSame('LEGACY_LINE_IDENTITY_UNKNOWN', $service->preview($order)[0]['action']);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
        $this->assertSame(0, InventoryReservation::count());
        $order->items()->delete();
        $this->line($order, 'MLM-L', null, 'MLM-L:', 1);
        $this->assertSame('RESERVE', $service->preview($order->fresh()->load('items'))[0]['action']);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
        $this->assertSame(1, InventoryReservation::active()->count());
    }

    public function test_variations_are_matched_exactly_and_never_fall_back_to_simple_link(): void
    {
        [$simple] = $this->stockedProduct('SKU-SIMPLE', 5);
        [$variant] = $this->stockedProduct('SKU-VAR', 5);
        $this->link($simple, 1, 'MLM-V', null, true);
        $this->link($variant, 1, 'MLM-V', '77', true);
        $order = $this->order(1, 1003, 'paid', [['item_id' => 'MLM-V', 'variation' => '77', 'key' => 'MLM-V:77', 'qty' => 1]]);
        app(InventoryMeliOrderReservationService::class)->apply($order);
        $this->assertDatabaseHas('inventory_reservations', ['inventory_product_id' => $variant->id, 'quantity' => 1]);
        $order->items()->delete();
        $this->line($order, 'MLM-V', null, 'MLM-V:', 1);
        app(InventoryMeliOrderReservationService::class)->apply($order->fresh()->load('items'));
        $this->assertDatabaseHas('inventory_reservations', ['inventory_product_id' => $simple->id, 'quantity' => 1]);
    }

    public function test_two_variations_in_one_order_have_distinct_allocations(): void
    {
        [$first] = $this->stockedProduct('SKU-L1', 5); [$second] = $this->stockedProduct('SKU-L2', 5);
        $this->link($first, 1, 'MLM-LINES', 'a', true); $this->link($second, 1, 'MLM-LINES', 'b', true);
        $order = $this->order(1, 1004, 'paid', [
            ['item_id' => 'MLM-LINES', 'variation' => 'a', 'key' => 'MLM-LINES:a', 'qty' => 1],
            ['item_id' => 'MLM-LINES', 'variation' => 'b', 'key' => 'MLM-LINES:b', 'qty' => 2],
        ]);
        app(InventoryMeliOrderReservationService::class)->apply($order);
        $this->assertSame(2, InventoryChannelOrderAllocation::where('status', 'ACTIVE')->count());
        $this->assertSame(2, InventoryReservation::active()->count());
    }

    public function test_account_scope_does_not_cross_match_same_listing(): void
    {
        [$product] = $this->stockedProduct('SKU-ACCOUNT', 5); $this->link($product, 10, 'MLM-ACCOUNT', null, true);
        $order = $this->order(11, 1005, 'paid', [['item_id' => 'MLM-ACCOUNT', 'key' => 'MLM-ACCOUNT:', 'qty' => 1]]);
        $this->assertSame('UNMATCHED', app(InventoryMeliOrderReservationService::class)->preview($order)[0]['action']);
        $this->assertSame(0, InventoryReservation::count());
    }

    public function test_ambiguous_exact_links_do_not_reserve(): void
    {
        [$first] = $this->stockedProduct('SKU-AMBIG-1', 4); [$second] = $this->stockedProduct('SKU-AMBIG-2', 4);
        $this->link($first, 1, 'MLM-AMBIG', null, true);
        $this->link($second, 1, 'MLM-AMBIG', null, true);
        $order = $this->order(1, 1018, 'paid', [['item_id' => 'MLM-AMBIG', 'key' => 'MLM-AMBIG:', 'qty' => 1]]);
        $this->assertSame('AMBIGUOUS', app(InventoryMeliOrderReservationService::class)->preview($order)[0]['action']);
        app(InventoryMeliOrderReservationService::class)->apply($order);
        $this->assertSame(0, InventoryReservation::count());
    }

    public function test_opt_out_inactive_links_products_and_insufficient_stock_fail_safe(): void
    {
        [$p1] = $this->stockedProduct('SKU-OFF', 5); $this->link($p1, 1, 'MLM-OFF', null, false);
        [$p2] = $this->stockedProduct('SKU-INACTIVE', 5); $this->link($p2, 1, 'MLM-INACTIVE', null, true, false, false);
        [$p3] = $this->stockedProduct('SKU-PRODUCT', 5); $p3->update(['is_active' => false]); $this->link($p3, 1, 'MLM-PRODUCT', null, true);
        [$p4] = $this->stockedProduct('SKU-LOW', 1); $this->link($p4, 1, 'MLM-LOW', null, true);
        $service = app(InventoryMeliOrderReservationService::class);
        foreach ([
            ['MLM-OFF', 'MLM-OFF:', 2, 'SKIPPED_ORDER_RESERVATION_DISABLED'],
            ['MLM-INACTIVE', 'MLM-INACTIVE:', 1, 'SKIPPED_INACTIVE_LINK'],
            ['MLM-PRODUCT', 'MLM-PRODUCT:', 1, 'SKIPPED_INACTIVE_PRODUCT'],
            ['MLM-LOW', 'MLM-LOW:', 2, 'INSUFFICIENT_INVENTORY'],
        ] as [$mlm, $key, $qty, $expected]) {
            $order = $this->order(1, 2001 + array_search($mlm, ['MLM-OFF', 'MLM-INACTIVE', 'MLM-PRODUCT', 'MLM-LOW'], true), 'paid', [['item_id' => $mlm, 'key' => $key, 'qty' => $qty]]);
            $this->assertSame($expected, $service->preview($order)[0]['action']);
            $service->apply($order);
        }
        $this->assertSame(0, InventoryReservation::active()->count());
        $this->assertGreaterThanOrEqual(1, InventoryChannelOrderAllocation::where('diagnostic_code', 'INSUFFICIENT_INVENTORY')->count());
    }

    public function test_cancel_release_and_quantity_reconciliation_preserve_history(): void
    {
        [$product] = $this->stockedProduct('SKU-RECON', 10); $this->link($product, 1, 'MLM-R', null, true);
        $line = ['item_id' => 'MLM-R', 'key' => 'MLM-R:', 'qty' => 2];
        $order = $this->order(1, 1006, 'paid', [$line]); $service = app(InventoryMeliOrderReservationService::class);
        $service->apply($order);
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
        $this->assertSame(0, InventoryReservation::where('status', InventoryReservation::RELEASED)->count());
        $line['qty'] = 1; $order->items()->delete(); $this->line($order, 'MLM-R', null, 'MLM-R:', 1);
        $service->apply($order->fresh()->load('items'));
        $this->assertSame(1, InventoryReservation::active()->sum('quantity'));
        $this->assertSame(1, InventoryReservation::where('status', InventoryReservation::RELEASED)->count());
        $line['qty'] = 2; $order->items()->delete(); $this->line($order, 'MLM-R', null, 'MLM-R:', 2);
        $service->apply($order->fresh()->load('items'));
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
        $this->assertSame(2, InventoryReservation::where('status', InventoryReservation::RELEASED)->count());
        $order->update(['status' => 'cancelled']);
        $service->apply($order->fresh()->load('items')); $service->apply($order->fresh()->load('items'));
        $this->assertSame(0, InventoryReservation::active()->count());
        $this->assertSame(3, InventoryReservation::where('status', InventoryReservation::RELEASED)->count());
    }

    public function test_quantity_increase_without_stock_keeps_the_previous_reservation_active(): void
    {
        [$product] = $this->stockedProduct('SKU-QTY-LOW', 1); $this->link($product, 1, 'MLM-QTY-LOW', null, true);
        $order = $this->order(1, 1015, 'paid', [['item_id' => 'MLM-QTY-LOW', 'key' => 'MLM-QTY-LOW:', 'qty' => 1]]);
        $service = app(InventoryMeliOrderReservationService::class); $service->apply($order);
        $order->items()->delete(); $this->line($order, 'MLM-QTY-LOW', null, 'MLM-QTY-LOW:', 2);
        $result = $service->apply($order->fresh()->load('items'))[0];
        $this->assertSame('INSUFFICIENT_INVENTORY', $result['action']);
        $this->assertSame(1, InventoryReservation::active()->sum('quantity'));
        $this->assertSame(1, InventoryChannelOrderAllocation::sole()->quantity);
    }

    public function test_stale_allocation_reconciles_and_active_reservation_is_no_change(): void
    {
        [$product] = $this->stockedProduct('SKU-STALE', 8); $this->link($product, 1, 'MLM-STALE', null, true);
        $order = $this->order(1, 1007, 'paid', [['item_id' => 'MLM-STALE', 'key' => 'MLM-STALE:', 'qty' => 2]]);
        $service = app(InventoryMeliOrderReservationService::class); $service->apply($order);
        $allocation = InventoryChannelOrderAllocation::sole();
        $reservation = InventoryReservation::findOrFail($allocation->reservation_id);
        app(\App\Services\InventoryReservationService::class)->release($reservation);
        $this->assertSame('STALE_ALLOCATION', $service->preview($order)[0]['action']);
        $service->apply($order->fresh()->load('items'));
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
        $this->assertSame(2, $allocation->fresh()->reservation_version);
        InventoryReservation::findOrFail($allocation->fresh()->reservation_id)->delete();
        $this->assertSame('STALE_ALLOCATION', $service->preview($order->fresh()->load('items'))[0]['action']);
        $service->apply($order->fresh()->load('items'));
        $this->assertSame(3, $allocation->fresh()->reservation_version);
        $this->assertSame('NO_CHANGE', $service->preview($order->fresh()->load('items'))[0]['action']);
    }

    public function test_kit_reserves_components_and_shared_group_conflicts_use_ticket_11_policy(): void
    {
        [$component] = $this->stockedProduct('SKU-COMP', 10);
        $kit = InventoryProduct::create(['sku' => 'SKU-KIT', 'name' => 'Kit', 'product_type' => InventoryProduct::KIT, 'is_active' => true]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $component->id, 'quantity' => 2]);
        $this->link($kit, 1, 'MLM-KIT', null, true);
        $order = $this->order(1, 1008, 'paid', [['item_id' => 'MLM-KIT', 'key' => 'MLM-KIT:', 'qty' => 2]]);
        app(InventoryMeliOrderReservationService::class)->apply($order);
        $this->assertSame(4, InventoryReservation::active()->sum('quantity'));
        $this->assertSame(2, InventoryKitReservation::where('status', InventoryKitReservation::ACTIVE)->sum('quantity'));

        [$left] = $this->stockedProduct('SKU-GROUP1', 4); [$right] = $this->stockedProduct('SKU-GROUP2', 4);
        $this->link($left, 1, 'MLM-G1', null, true, true, true, 'SHARED');
        $this->link($right, 1, 'MLM-G2', null, true, true, true, 'SHARED');
        $groupOrder = $this->order(1, 1009, 'paid', [['item_id' => 'MLM-G1', 'key' => 'MLM-G1:', 'qty' => 1]]);
        $service = app(InventoryMeliOrderReservationService::class);
        $this->assertSame('REMOTE_USER_PRODUCT_CONFLICT', $service->preview($groupOrder)[0]['action']);
        (new ReconcileInventoryMeliOrderReservationsJob($groupOrder->id))->handle($service);
        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '1009')->sole();
        $this->assertSame('REMOTE_USER_PRODUCT_CONFLICT', $allocation->diagnostic_code);
        $this->assertNull($allocation->reservation_id);
    }

    public function test_variations_do_not_enter_shared_group_and_groups_are_account_scoped(): void
    {
        [$one] = $this->stockedProduct('SKU-SHARED-A', 4); [$two] = $this->stockedProduct('SKU-SHARED-B', 4);
        $this->link($one, 1, 'MLM-SHARED-V', '77', true, true, true, 'SAME-REMOTE');
        $this->link($two, 1, 'MLM-SHARED-SIBLING', null, true, true, true, 'SAME-REMOTE');
        $order = $this->order(1, 1012, 'paid', [['item_id' => 'MLM-SHARED-V', 'variation' => '77', 'key' => 'MLM-SHARED-V:77', 'qty' => 1]]);
        $this->assertSame('RESERVE', app(InventoryMeliOrderReservationService::class)->preview($order)[0]['action']);

        [$otherAccount] = $this->stockedProduct('SKU-SHARED-C', 4);
        $this->link($otherAccount, 2, 'MLM-OTHER-ACCOUNT', null, true, true, true, 'SAME-REMOTE');
        $this->link($one, 1, 'MLM-SHARED-SIMPLE', null, true, true, true, 'ACCOUNT-LOCAL');
        $this->link($otherAccount, 2, 'MLM-OTHER-SIMPLE', null, true, true, true, 'ACCOUNT-LOCAL');
        $localOrder = $this->order(1, 1013, 'paid', [['item_id' => 'MLM-SHARED-SIMPLE', 'key' => 'MLM-SHARED-SIMPLE:', 'qty' => 1]]);
        $this->assertSame('RESERVE', app(InventoryMeliOrderReservationService::class)->preview($localOrder)[0]['action']);
    }

    public function test_shared_stock_siblings_produce_one_allocation_for_the_exact_sold_listing(): void
    {
        [$product] = $this->stockedProduct('SKU-SHARED-ONE', 5);
        $this->link($product, 1, 'MLM-SIBLING-A', null, true, true, true, 'USER-PRODUCT-1');
        $this->link($product, 1, 'MLM-SIBLING-B', null, true, true, true, 'USER-PRODUCT-1');
        $order = $this->order(1, 1016, 'paid', [['item_id' => 'MLM-SIBLING-A', 'key' => 'MLM-SIBLING-A:', 'qty' => 1]]);
        app(InventoryMeliOrderReservationService::class)->apply($order);
        $this->assertSame(1, InventoryChannelOrderAllocation::where('status', 'ACTIVE')->count());
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame($product->id, InventoryChannelOrderAllocation::sole()->inventory_product_id);
    }

    public function test_apply_rejects_duplicate_remote_order_ids_without_account_and_scopes_when_given(): void
    {
        [$first] = $this->stockedProduct('SKU-APPLY-1', 3); [$second] = $this->stockedProduct('SKU-APPLY-2', 3);
        $this->link($first, 1, 'MLM-APPLY-1', null, true); $this->link($second, 2, 'MLM-APPLY-2', null, true);
        $this->order(1, 1014, 'paid', [['item_id' => 'MLM-APPLY-1', 'key' => 'MLM-APPLY-1:', 'qty' => 1]]);
        $this->order(2, 1014, 'paid', [['item_id' => 'MLM-APPLY-2', 'key' => 'MLM-APPLY-2:', 'qty' => 1]]);
        Http::fake();
        $this->assertSame(1, Artisan::call('inventory:meli-order-reservations', ['--apply' => true, '--order' => '1014']));
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(0, Artisan::call('inventory:meli-order-reservations', ['--apply' => true, '--order' => '1014', '--account' => '2']));
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertDatabaseHas('inventory_reservations', ['inventory_product_id' => $second->id, 'quantity' => 1]);
        $this->assertDatabaseMissing('inventory_reservations', ['inventory_product_id' => $first->id]);
        Http::assertNothingSent();
    }

    public function test_preview_is_read_only_and_apply_requires_one_local_order(): void
    {
        [$product] = $this->stockedProduct('SKU-CMD', 5); $this->link($product, 1, 'MLM-CMD', null, true);
        $this->order(1, 1010, 'paid', [['item_id' => 'MLM-CMD', 'key' => 'MLM-CMD:', 'qty' => 1]]);
        Http::fake();
        $this->assertSame(0, Artisan::call('inventory:meli-order-reservations', ['--order' => '1010']));
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(0, InventoryChannelOrderAllocation::count());
        Http::assertNothingSent();
        $this->assertSame(1, Artisan::call('inventory:meli-order-reservations', ['--apply' => true]));
        Http::assertNothingSent();
    }

    public function test_reservation_audit_is_safe_and_command_is_not_scheduled(): void
    {
        [$product] = $this->stockedProduct('SKU-AUDIT', 3); $this->link($product, 1, 'MLM-AUDIT', null, true);
        $order = $this->order(1, 1017, 'paid', [['item_id' => 'MLM-AUDIT', 'key' => 'MLM-AUDIT:', 'qty' => 1]]);
        app(InventoryMeliOrderReservationService::class)->apply($order);
        $reservation = InventoryReservation::sole();
        $this->assertSame('meli_order', $reservation->source_type);
        $this->assertStringContainsString('1017', $reservation->reference);
        $this->assertSame('MLM-AUDIT:', data_get($reservation->metadata, 'remote_line_key'));
        $audit = json_encode([$reservation->reference, $reservation->metadata]);
        $this->assertStringNotContainsString('access_token', $audit);
        $this->assertStringNotContainsString('refresh_token', $audit);
        $this->assertStringNotContainsString('inventory:meli-order-reservations', file_get_contents(base_path('routes/console.php')));
    }

    public function test_migration_down_rejects_multi_variation_identity_before_any_schema_change(): void
    {
        $order = $this->order(1, 1022, 'paid', [
            ['item_id' => 'MLM-DOWN', 'variation' => 'one', 'key' => 'MLM-DOWN:one', 'qty' => 1],
            ['item_id' => 'MLM-DOWN', 'variation' => 'two', 'key' => 'MLM-DOWN:two', 'qty' => 1],
        ]);
        $migration = require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php');
        try {
            $migration->down();
            $this->fail('Expected the rollback preflight to reject multi-variation lines.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('meli_order_id + item_id', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('inventory_channel_order_allocations'));
        $this->assertTrue(Schema::hasColumn('meli_order_items', 'remote_line_key'));
        $this->assertNotNull($order->fresh());
    }

    public function test_reconcile_dispatches_one_stock_job_per_remote_group_and_retry_can_converge_again(): void
    {
        Bus::fake();

        [$product] = $this->stockedProduct('SKU-DISPATCH', 10);

        $groupA = $this->link(
            $product,
            1,
            'MLM-DISPATCH-A',
            null,
            true,
            true,
            true,
            'GROUP-A'
        );

        $this->link(
            $product,
            1,
            'MLM-DISPATCH-A-SIBLING',
            null,
            true,
            true,
            true,
            'GROUP-A'
        );

        $groupB = $this->link(
            $product,
            1,
            'MLM-DISPATCH-B',
            null,
            true,
            true,
            true,
            'GROUP-B'
        );

        $kit = InventoryProduct::create([
            'sku' => 'SKU-DISPATCH-KIT',
            'name' => 'Dependent kit',
            'product_type' => InventoryProduct::KIT,
            'is_active' => true,
        ]);

        InventoryKitComponent::create([
            'kit_product_id' => $kit->id,
            'component_product_id' => $product->id,
            'quantity' => 1,
        ]);

        $kitLink = $this->link(
            $kit,
            1,
            'MLM-DISPATCH-KIT',
            null,
            true,
            true,
            true,
            'GROUP-KIT'
        );

        $order = $this->order(
            1,
            1201,
            'paid',
            [[
                'item_id' => 'MLM-DISPATCH-A',
                'key' => 'MLM-DISPATCH-A:',
                'qty' => 1,
            ]]
        );

        $job = new ReconcileInventoryMeliOrderReservationsJob($order->id);

        $job->handle(app(InventoryMeliOrderReservationService::class));

        $expected = [$groupA->id, $groupB->id, $kitLink->id];
        sort($expected);

        $actual = Bus::dispatched(SyncInventoryMeliStockLinkJob::class)
            ->map(fn (SyncInventoryMeliStockLinkJob $job): int => $job->linkId)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);
        $this->assertSame(1, InventoryReservation::active()->sum('quantity'));

        /*
         * The second reconciliation is NO_CHANGE for Inventory, but it should
         * deliberately enqueue the affected stock groups again. That gives a
         * failed previous remote sync another convergence opportunity.
         */
        $job->handle(app(InventoryMeliOrderReservationService::class));

        $this->assertCount(
            6,
            Bus::dispatched(SyncInventoryMeliStockLinkJob::class)
        );

        $this->assertSame(1, InventoryReservation::active()->sum('quantity'));
    }

    public function test_kit_sale_dispatches_kit_components_and_other_kits_using_the_components(): void
    {
        Bus::fake();

        [$left] = $this->stockedProduct('SKU-KIT-LEFT', 10);
        [$right] = $this->stockedProduct('SKU-KIT-RIGHT', 10);

        $soldKit = InventoryProduct::create([
            'sku' => 'SKU-SOLD-KIT',
            'name' => 'Sold kit',
            'product_type' => InventoryProduct::KIT,
            'is_active' => true,
        ]);

        InventoryKitComponent::create([
            'kit_product_id' => $soldKit->id,
            'component_product_id' => $left->id,
            'quantity' => 1,
        ]);

        InventoryKitComponent::create([
            'kit_product_id' => $soldKit->id,
            'component_product_id' => $right->id,
            'quantity' => 1,
        ]);

        $otherKit = InventoryProduct::create([
            'sku' => 'SKU-OTHER-KIT',
            'name' => 'Other kit',
            'product_type' => InventoryProduct::KIT,
            'is_active' => true,
        ]);

        InventoryKitComponent::create([
            'kit_product_id' => $otherKit->id,
            'component_product_id' => $left->id,
            'quantity' => 1,
        ]);

        $soldKitLink = $this->link(
            $soldKit,
            1,
            'MLM-SOLD-KIT',
            null,
            true,
            true,
            true,
            'GROUP-SOLD-KIT'
        );

        $leftLink = $this->link(
            $left,
            1,
            'MLM-KIT-LEFT',
            null,
            true,
            true,
            true,
            'GROUP-LEFT'
        );

        $rightLink = $this->link(
            $right,
            1,
            'MLM-KIT-RIGHT',
            null,
            true,
            true,
            true,
            'GROUP-RIGHT'
        );

        $otherKitLink = $this->link(
            $otherKit,
            1,
            'MLM-OTHER-KIT',
            null,
            true,
            true,
            true,
            'GROUP-OTHER-KIT'
        );

        $order = $this->order(
            1,
            1202,
            'paid',
            [[
                'item_id' => 'MLM-SOLD-KIT',
                'key' => 'MLM-SOLD-KIT:',
                'qty' => 1,
            ]]
        );

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle(app(InventoryMeliOrderReservationService::class));

        $expected = [
            $soldKitLink->id,
            $leftLink->id,
            $rightLink->id,
            $otherKitLink->id,
        ];

        sort($expected);

        $actual = Bus::dispatched(SyncInventoryMeliStockLinkJob::class)
            ->map(fn (SyncInventoryMeliStockLinkJob $job): int => $job->linkId)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);

        $this->assertSame(
            1,
            InventoryKitReservation::where(
                'status',
                InventoryKitReservation::ACTIVE
            )->sum('quantity')
        );

        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
    }

    public function test_affected_stock_dispatch_uses_only_active_stock_sync_enabled_links(): void
    {
        Bus::fake();

        [$product] = $this->stockedProduct('SKU-FILTER-DISPATCH', 5);

        $this->link(
            $product,
            1,
            'MLM-SOLD-DISABLED',
            null,
            true,
            true,
            false,
            'GROUP-DISABLED'
        );

        $enabled = $this->link(
            $product,
            1,
            'MLM-OTHER-ENABLED',
            null,
            true,
            true,
            true,
            'GROUP-ENABLED'
        );

        $this->link(
            $product,
            1,
            'MLM-OTHER-INACTIVE',
            null,
            true,
            false,
            true,
            'GROUP-INACTIVE'
        );

        $order = $this->order(
            1,
            1203,
            'paid',
            [[
                'item_id' => 'MLM-SOLD-DISABLED',
                'key' => 'MLM-SOLD-DISABLED:',
                'qty' => 1,
            ]]
        );

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle(app(InventoryMeliOrderReservationService::class));

        Bus::assertDispatchedTimes(
            SyncInventoryMeliStockLinkJob::class,
            1
        );

        Bus::assertDispatched(
            SyncInventoryMeliStockLinkJob::class,
            fn (SyncInventoryMeliStockLinkJob $job): bool =>
                $job->linkId === $enabled->id
        );

        $this->assertSame(1, InventoryReservation::active()->sum('quantity'));
    }
    private function stockedProduct(string $sku, int $quantity): array
    {
        $location = InventoryLocation::create(['code' => 'LOC-'.substr(hash('sha1', $sku), 0, 8), 'name' => 'Test', 'is_active' => true]);
        $product = InventoryProduct::create(['sku' => $sku, 'name' => $sku, 'is_active' => true]);
        InventoryMovement::create(['inventory_product_id' => $product->id, 'inventory_location_id' => $location->id,
            'type' => InventoryMovement::RECEIPT, 'quantity' => $quantity, 'occurred_at' => now()]);
        return [$product, $location];
    }

    private function link(InventoryProduct $product, int $account, string $mlm, ?string $variation, bool $enabled, bool $active = true, bool $stockSync = false, ?string $remote = null): InventoryChannelLink
    {
        return InventoryChannelLink::create(['inventory_product_id' => $product->id, 'channel' => 'mercado_libre',
            'account_key' => (string) $account, 'external_listing_id' => $mlm, 'external_variant_id' => $variation,
            'remote_user_product_id' => $remote, 'identity_key' => implode(':', [$account, $mlm, $variation ?? 'simple', $product->id]),
            'is_active' => $active, 'stock_sync_enabled' => $stockSync, 'order_reservation_enabled' => $enabled]);
    }

    private function order(int $account, int $remoteId, string $status, array $lines): MeliOrder
    {
        $order = MeliOrder::create(['meli_account_id' => $account, 'order_id' => $remoteId, 'status' => $status]);
        foreach ($lines as $line) $this->line($order, $line['item_id'], $line['variation'] ?? null, $line['key'], $line['qty']);
        return $order->load('items');
    }

    private function line(MeliOrder $order, string $mlm, ?string $variation, ?string $key, int $qty): MeliOrderItem
    {
        return MeliOrderItem::create(['meli_order_id' => $order->id, 'item_id' => $mlm, 'variation_id' => $variation,
            'remote_line_key' => $key, 'quantity' => $qty]);
    }
}
