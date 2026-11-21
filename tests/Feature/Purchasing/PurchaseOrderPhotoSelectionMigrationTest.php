<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\User;
use App\Services\Purchasing\MeliListingSkuIndexService;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderPhotoSelectionMigrationTest extends TestCase
{
    private const MIGRATION_FILE = 'migrations/2026_11_20_000001_add_photo_selection_fields_to_purchase_order_items_table.php';

    private User $admin;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        $this->createBaseProductionLikeSchema(withSupplierSku: true);

        $this->admin = User::forceCreate([
            'name' => 'Admin Migración',
            'email' => 'admin-mig@sbs.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'BOD-CENTRAL',
            'name' => 'Bodega Central SBS',
            'type' => 'warehouse',
            'is_active' => true,
        ]);
    }

    public function test_migration_applies_cleanly_preserves_existing_data_supports_pre_migration_reads_and_rolls_back_safely(): void
    {
        $account = MeliAccount::create([
            'user_id' => $this->admin->id,
            'meli_user_id' => '99887766',
            'nickname' => 'SBS_CHINA',
            'is_default' => true,
        ]);

        $product = InventoryProduct::create([
            'sku' => 'BSHC003',
            'name' => 'Pinzas mariposa 12 piezas negro',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 35.50,
            'brand' => 'CHINA',
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $account->id,
            'sku' => 'BSHC003',
            'mlm' => 'MLM1964933341',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'title' => 'Pinzas Mariposa 12 Piezas Negro',
                    'seller_custom_field' => 'BSHC003',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/pinzas-bshc003.jpg']],
                ],
            ],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261113-0001',
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_PARTIAL,
            'total_items_count' => 1,
            'total_units_ordered' => 120,
            'total_units_received' => 40,
            'subtotal' => 4260.00,
            'total_cost' => 4260.00,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $product->id,
            'sku' => 'BSHC003',
            'supplier_sku' => 'SUP-BSHC003',
            'product_name' => 'Pinzas mariposa 12 piezas negro',
            'quantity_ordered' => 120,
            'quantity_received' => 40,
            'unit_cost' => 35.50,
            'subtotal' => 4260.00,
            'status' => PurchaseOrderItem::STATUS_PARTIAL,
        ]);

        $receipt = PurchaseOrderReceipt::create([
            'purchase_order_id' => $order->id,
            'user_id' => $this->admin->id,
            'inventory_location_id' => $this->location->id,
            'receipt_number' => 'REC-20261120-0001',
            'received_at' => now(),
            'total_units_received' => 40,
        ]);

        $receiptItem = PurchaseOrderReceiptItem::create([
            'purchase_order_receipt_id' => $receipt->id,
            'purchase_order_item_id' => $item->id,
            'inventory_product_id' => $product->id,
            'quantity_received' => 40,
        ]);

        $movement = InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 40,
            'reference_type' => PurchaseOrderReceipt::class,
            'reference_id' => $receipt->id,
            'created_by' => $this->admin->id,
            'occurred_at' => now(),
        ]);

        // 1. Before migration runs: resolver and read-only audit command work cleanly without new columns
        $this->assertFalse(Schema::hasColumn('purchase_order_items', 'photo_source_mode'));
        $resolver = app(PurchaseOrderMeliImageResolver::class);
        $preMigration = $resolver->resolveForOrder($order->fresh(['items.product']), false);
        $this->assertSame('https://http2.mlstatic.com/pinzas-bshc003.jpg', $preMigration[$item->id]['url']);

        $auditPreCode = Artisan::call('purchasing:audit-order-photos', [
            'order' => 'OC-20261113-0001',
            '--json' => true,
        ]);
        $this->assertSame(0, $auditPreCode);

        // 2. Apply migration (and verify idempotency on repeated up())
        $migration = require database_path(self::MIGRATION_FILE);
        $migration->up();
        $migration->up();

        $expectedColumns = [
            'photo_source_mode',
            'approved_mlm',
            'approved_variation_id',
            'approved_meli_account_id',
            'approved_photo_url',
            'manual_photo_path',
            'manual_photo_mime',
            'photo_notes',
            'rejected_mlms',
            'photo_updated_by',
            'photo_updated_at',
        ];

        foreach ($expectedColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('purchase_order_items', $column),
                "Expected column [{$column}] after migration up()."
            );
        }

        // 3. Existing order items, costs, quantities, receipts, and inventory movements are preserved
        $item->refresh();
        $this->assertSame('SUP-BSHC003', $item->supplier_sku);
        $this->assertSame(120, $item->quantity_ordered);
        $this->assertSame(40, $item->quantity_received);
        $this->assertSame('35.50', (string) $item->unit_cost);
        $this->assertSame('4260.00', (string) $item->subtotal);
        $this->assertSame(PurchaseOrderItem::STATUS_PARTIAL, $item->status);
        $this->assertNull($item->photo_source_mode);

        // 4. New fields persist and cast cleanly
        $item->update([
            'photo_source_mode' => PurchaseOrderItem::PHOTO_MODE_APPROVED_ML,
            'approved_mlm' => 'MLM1964933341',
            'approved_variation_id' => null,
            'approved_meli_account_id' => $account->id,
            'approved_photo_url' => 'https://http2.mlstatic.com/pinzas-bshc003.jpg',
            'photo_notes' => 'Aprobada visualmente',
            'rejected_mlms' => ['MLM3446472074'],
            'photo_updated_by' => $this->admin->id,
            'photo_updated_at' => now(),
        ]);

        $item->refresh();
        $this->assertSame(PurchaseOrderItem::PHOTO_MODE_APPROVED_ML, $item->photo_source_mode);
        $this->assertSame('MLM1964933341', $item->approved_mlm);
        $this->assertSame(['MLM3446472074'], $item->rejected_mlms);
        $this->assertSame($this->admin->id, $item->photo_updated_by);
        $this->assertNotNull($item->photo_updated_at);

        // 5. Rollback cleanly removes ONLY new columns and preserves items, receipts, and inventory
        $migration->down();

        foreach ($expectedColumns as $column) {
            $this->assertFalse(
                Schema::hasColumn('purchase_order_items', $column),
                "Expected column [{$column}] to be removed after migration down()."
            );
        }

        $rowAfterDown = DB::table('purchase_order_items')->where('id', $item->id)->first();
        $this->assertNotNull($rowAfterDown);
        $this->assertSame('SUP-BSHC003', $rowAfterDown->supplier_sku);
        $this->assertSame(120, (int) $rowAfterDown->quantity_ordered);
        $this->assertSame(40, (int) $rowAfterDown->quantity_received);
        $this->assertEquals(4260.00, (float) $rowAfterDown->subtotal);

        $this->assertDatabaseHas('purchase_order_receipts', ['id' => $receipt->id, 'total_units_received' => 40]);
        $this->assertDatabaseHas('purchase_order_receipt_items', ['id' => $receiptItem->id, 'quantity_received' => 40]);
        $this->assertDatabaseHas('inventory_movements', ['id' => $movement->id, 'quantity' => 40, 'type' => InventoryMovement::RECEIPT]);
    }

    public function test_migration_applies_cleanly_even_when_supplier_sku_column_is_absent(): void
    {
        DB::purge('sqlite');
        $this->createBaseProductionLikeSchema(withSupplierSku: false);

        $this->assertFalse(Schema::hasColumn('purchase_order_items', 'supplier_sku'));

        $migration = require database_path(self::MIGRATION_FILE);
        $migration->up();

        $this->assertTrue(Schema::hasColumn('purchase_order_items', 'photo_source_mode'));
        $this->assertTrue(Schema::hasColumn('purchase_order_items', 'approved_photo_url'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('purchase_order_items', 'photo_source_mode'));
    }

    public function test_meli_listing_sku_index_migration_backfill_model_hooks_and_safe_rollback(): void
    {
        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id');
            $table->string('meli_item_id', 64);
            $table->string('sku', 191)->nullable();
            $table->string('title');
            $table->decimal('current_price', 15, 2)->default(0);
            $table->string('status', 64)->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        $account = MeliAccount::create([
            'user_id' => $this->admin->id,
            'meli_user_id' => '55443322',
            'nickname' => 'SBS_INDEX_MIG',
            'is_default' => true,
        ]);

        // Seed listings BEFORE index table exists to ensure model hooks never fail pre-migration.
        $existingPub = MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $account->id,
            'sku' => 'ATOMIZADORBCOAF',
            'mlm' => 'MLM3373409704',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'p1', 'secure_url' => 'https://http2.mlstatic.com/atom-azul.jpg'],
                        ['id' => 'p2', 'secure_url' => 'https://http2.mlstatic.com/atom-rosa.jpg'],
                    ],
                    'variations' => [
                        ['id' => 186105657167, 'seller_custom_field' => 'ATOMIZADORBCOAF', 'picture_ids' => ['p1']],
                        ['id' => 186106913357, 'seller_custom_field' => 'ATOMIZADORBR', 'picture_ids' => ['p2']],
                    ],
                ],
            ],
        ]);

        $existingPm = MeliPriceManagerItem::create([
            'meli_account_id' => $account->id,
            'meli_item_id' => 'MLM2124053049',
            'sku' => 'CAMILLAPROFB',
            'title' => 'Camilla Portátil Profesional',
            'status' => 'active',
            'last_synced_at' => now(),
            'raw_item' => [
                'id' => 'MLM2124053049',
                'pictures' => [['id' => 'c1', 'secure_url' => 'https://http2.mlstatic.com/camilla-negra.jpg']],
                'variations' => [
                    ['id' => 184371561381, 'seller_custom_field' => 'CAMILLAPROFN', 'picture_ids' => ['c1']],
                ],
            ],
        ]);

        $this->assertFalse(Schema::hasTable(MeliListingSkuIndexService::TABLE));

        // 1. Apply migration (and verify idempotency on repeated up()).
        $indexMigration = require database_path('migrations/2026_11_21_000001_create_meli_listing_sku_index_table.php');
        $indexMigration->up();
        $indexMigration->up();

        $this->assertTrue(Schema::hasTable(MeliListingSkuIndexService::TABLE));
        $this->assertTrue(Schema::hasTable(MeliListingSkuIndexService::STATE_TABLE));

        // 2. Run Artisan backfill command and verify indexed SKUs for both tables.
        $exitCode = Artisan::call('purchasing:sync-meli-photo-sku-index', ['--chunk' => 50]);
        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas(MeliListingSkuIndexService::STATE_TABLE, [
            'id' => 1,
            'status' => MeliListingSkuIndexService::STATUS_READY,
            'active_generation' => 1,
        ]);

        $this->assertDatabaseHas(MeliListingSkuIndexService::TABLE, [
            'generation' => 1,
            'source_table' => MeliListingSkuIndexService::SOURCE_PUBLICATIONS,
            'source_row_id' => $existingPub->id,
            'mlm' => 'MLM3373409704',
            'variation_id' => '186106913357',
            'sku_normalized' => 'ATOMIZADORBR',
        ]);
        $this->assertDatabaseHas(MeliListingSkuIndexService::TABLE, [
            'generation' => 1,
            'source_table' => MeliListingSkuIndexService::SOURCE_PRICE_MANAGER,
            'source_row_id' => $existingPm->id,
            'mlm' => 'MLM2124053049',
            'variation_id' => '184371561381',
            'sku_normalized' => 'CAMILLAPROFN',
        ]);

        // 3. Automatic Eloquent model hooks keep the active generation synchronized on save and delete.
        $hookPub = MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $account->id,
            'sku' => 'HOOK-SKU-1',
            'mlm' => 'MLM8880001',
            'status' => 'active',
            'raw' => ['item' => ['seller_custom_field' => 'HOOK-SKU-1']],
        ]);
        $this->assertDatabaseHas(MeliListingSkuIndexService::TABLE, [
            'generation' => 1,
            'source_table' => MeliListingSkuIndexService::SOURCE_PUBLICATIONS,
            'source_row_id' => $hookPub->id,
            'sku_normalized' => 'HOOK-SKU-1',
        ]);

        $hookPub->delete();
        $this->assertDatabaseMissing(MeliListingSkuIndexService::TABLE, [
            'source_table' => MeliListingSkuIndexService::SOURCE_PUBLICATIONS,
            'source_row_id' => $hookPub->id,
        ]);

        // 4. Safe rollback drops only the auxiliary index tables without touching source listings.
        $indexMigration->down();
        $this->assertFalse(Schema::hasTable(MeliListingSkuIndexService::TABLE));
        $this->assertFalse(Schema::hasTable(MeliListingSkuIndexService::STATE_TABLE));
        $this->assertDatabaseHas('meli_publications', ['id' => $existingPub->id, 'mlm' => 'MLM3373409704']);
        $this->assertDatabaseHas('meli_price_manager_items', ['id' => $existingPm->id, 'meli_item_id' => 'MLM2124053049']);
    }

    private function createBaseProductionLikeSchema(bool $withSupplierSku): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
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

        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('source_mlm')->nullable();
            $table->string('status')->nullable();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('pictures')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
        (require database_path('migrations/2026_10_07_193000_create_suppliers_and_supplier_brands_tables.php'))->up();

        if ($withSupplierSku) {
            (require database_path('migrations/2026_10_21_160000_create_supplier_products_table.php'))->up();
        }
    }
}
