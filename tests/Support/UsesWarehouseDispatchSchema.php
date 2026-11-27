<?php

namespace Tests\Support;

use App\Models\InventoryDispatchDestination;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

trait UsesWarehouseDispatchSchema
{
    protected function bootstrapWarehouseDispatchSchema(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();

        if (blank(config('app.key'))) {
            config()->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        }

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('role', 32)->default('operations');
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_logistic_type')->nullable();
            $table->string('shipping_mode')->nullable();
            $table->string('shipping_type')->nullable();
            $table->timestamp('date_created')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id');
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('meli_full_stocks', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable();
            $table->integer('full_available_quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('meli_full_shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('DRAFT');
            $table->timestamps();
        });

        Schema::create('meli_full_shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_full_shipment_id');
            $table->foreignId('inventory_product_id');
            $table->integer('quantity_sent')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('sale_number')->unique();
            $table->timestamps();
        });

        $migrations = [
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
            '2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php',
            '2026_09_30_000003_create_restock_configurations_table.php',
            '2026_10_05_160000_add_secondary_location_and_amazon_locations.php',
            '2026_10_07_183419_add_barcode_secondary_to_inventory_products_table.php',
            '2026_10_13_200000_create_inventory_shelf_audits_tables.php',
            '2026_10_13_210000_add_shelf_code_to_inventory_shelf_audits_tables.php',
            '2026_10_15_180000_add_brand_and_audit_type_to_inventory_shelf_audits.php',
            '2026_11_27_000001_create_inventory_dispatch_destinations_table.php',
            '2026_11_27_000002_create_inventory_provisional_items_table.php',
            '2026_11_27_000003_create_inventory_dispatches_table.php',
            '2026_11_27_000004_create_inventory_dispatch_items_table.php',
            '2026_11_27_000005_create_inventory_dispatch_events_table.php',
        ];

        foreach ($migrations as $file) {
            $migration = require database_path("migrations/{$file}");
            $migration->up();
        }
    }

    protected function makeUser(string $role, string $emailPrefix = 'user'): User
    {
        $user = new User;
        $user->forceFill([
            'name' => ucfirst($role).' '.$emailPrefix,
            'email' => "{$emailPrefix}-{$role}-".uniqid().'@example.test',
            'password' => Hash::make('secret-password-123'),
            'role' => $role,
        ])->save();

        return $user->fresh();
    }

    protected function makeLocation(string $code, ?string $name = null): InventoryLocation
    {
        return InventoryLocation::create([
            'code' => $code,
            'name' => $name ?? "Ubicación {$code}",
            'is_active' => true,
        ]);
    }

    protected function makeProduct(array $overrides = []): InventoryProduct
    {
        return InventoryProduct::create(array_merge([
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'barcode' => null,
            'barcode_secondary' => null,
            'name' => 'Producto Prueba',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'cost' => 100.00,
            'price_stylist' => 165.00,
            'price_public' => 220.00,
            'is_active' => true,
        ], $overrides));
    }

    protected function makeDestination(string $code = 'SALON-NORTE', string $name = 'Salón Norte', bool $active = true): InventoryDispatchDestination
    {
        $dest = new InventoryDispatchDestination;
        $dest->forceFill([
            'code' => $code,
            'name' => $name,
            'destination_type' => InventoryDispatchDestination::TYPE_SALON,
            'is_active' => $active,
        ])->save();

        return $dest->fresh();
    }
}
