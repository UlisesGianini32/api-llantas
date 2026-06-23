<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('meli_orders', 'syscom_order_folio')) {
                $table->string('syscom_order_folio')->nullable()->after('stock_applied_at');
            }
            if (! Schema::hasColumn('meli_orders', 'syscom_order_synced_at')) {
                $table->timestamp('syscom_order_synced_at')->nullable()->after('syscom_order_folio');
            }
            if (! Schema::hasColumn('meli_orders', 'syscom_order_error')) {
                $table->text('syscom_order_error')->nullable()->after('syscom_order_synced_at');
            }
            if (! Schema::hasColumn('meli_orders', 'syscom_order_raw')) {
                $table->json('syscom_order_raw')->nullable()->after('syscom_order_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            if (Schema::hasColumn('meli_orders', 'syscom_order_raw')) {
                $table->dropColumn('syscom_order_raw');
            }
            if (Schema::hasColumn('meli_orders', 'syscom_order_error')) {
                $table->dropColumn('syscom_order_error');
            }
            if (Schema::hasColumn('meli_orders', 'syscom_order_synced_at')) {
                $table->dropColumn('syscom_order_synced_at');
            }
            if (Schema::hasColumn('meli_orders', 'syscom_order_folio')) {
                $table->dropColumn('syscom_order_folio');
            }
        });
    }
};

