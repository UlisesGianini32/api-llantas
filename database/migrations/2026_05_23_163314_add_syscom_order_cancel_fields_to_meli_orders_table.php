<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('meli_orders', 'syscom_order_cancelled_at')) {
                $table->timestamp('syscom_order_cancelled_at')->nullable()->after('syscom_order_raw');
            }
            if (! Schema::hasColumn('meli_orders', 'syscom_order_cancel_error')) {
                $table->text('syscom_order_cancel_error')->nullable()->after('syscom_order_cancelled_at');
            }
            if (! Schema::hasColumn('meli_orders', 'syscom_order_cancel_raw')) {
                $table->json('syscom_order_cancel_raw')->nullable()->after('syscom_order_cancel_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            if (Schema::hasColumn('meli_orders', 'syscom_order_cancel_raw')) {
                $table->dropColumn('syscom_order_cancel_raw');
            }
            if (Schema::hasColumn('meli_orders', 'syscom_order_cancel_error')) {
                $table->dropColumn('syscom_order_cancel_error');
            }
            if (Schema::hasColumn('meli_orders', 'syscom_order_cancelled_at')) {
                $table->dropColumn('syscom_order_cancelled_at');
            }
        });
    }
};
