<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Inventory Movements: composite indexing for ledger reference & forecasting velocity
        if (Schema::hasTable('inventory_movements')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->index(['reference_type', 'reference_id'], 'inv_mov_ref_type_id_idx');
                $table->index(['inventory_product_id', 'type', 'occurred_at'], 'inv_mov_prod_type_date_idx');
            });
        }

        // 2. Inventory Reservations: indexing for order source lookup and release/fulfillment
        if (Schema::hasTable('inventory_reservations')) {
            Schema::table('inventory_reservations', function (Blueprint $table): void {
                $table->index(['source_type', 'source_id'], 'inv_res_source_idx');
                $table->index('reference', 'inv_res_reference_idx');
            });
        }

        // 3. Inventory Channel Links: active links lookup for stock sync
        if (Schema::hasTable('inventory_channel_links')) {
            Schema::table('inventory_channel_links', function (Blueprint $table): void {
                $table->index(['inventory_product_id', 'is_active'], 'inv_chan_prod_active_idx');
            });
        }

        // 4. POS Sales: sales list filtered by location and date
        if (Schema::hasTable('pos_sales')) {
            Schema::table('pos_sales', function (Blueprint $table): void {
                $table->index(['inventory_location_id', 'created_at'], 'pos_sales_loc_created_idx');
            });
        }

        // 5. Purchase Orders: location and status incoming deliveries
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->index(['inventory_location_id', 'status'], 'po_loc_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropIndex('po_loc_status_idx');
            });
        }

        if (Schema::hasTable('pos_sales')) {
            Schema::table('pos_sales', function (Blueprint $table): void {
                $table->dropIndex('pos_sales_loc_created_idx');
            });
        }

        if (Schema::hasTable('inventory_channel_links')) {
            Schema::table('inventory_channel_links', function (Blueprint $table): void {
                $table->dropIndex('inv_chan_prod_active_idx');
            });
        }

        if (Schema::hasTable('inventory_reservations')) {
            Schema::table('inventory_reservations', function (Blueprint $table): void {
                $table->dropIndex('inv_res_source_idx');
                $table->dropIndex('inv_res_reference_idx');
            });
        }

        if (Schema::hasTable('inventory_movements')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->dropIndex('inv_mov_ref_type_id_idx');
                $table->dropIndex('inv_mov_prod_type_date_idx');
            });
        }
    }
};
