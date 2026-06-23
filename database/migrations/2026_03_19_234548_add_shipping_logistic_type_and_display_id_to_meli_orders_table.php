<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('meli_orders', 'shipping_logistic_type')) {
                $table->string('shipping_logistic_type', 80)
                    ->nullable()
                    ->after('shipping_type');
            }

            if (!Schema::hasColumn('meli_orders', 'display_id')) {
                $table->string('display_id', 120)
                    ->nullable()
                    ->after('shipping_raw');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            if (Schema::hasColumn('meli_orders', 'display_id')) {
                $table->dropColumn('display_id');
            }

            if (Schema::hasColumn('meli_orders', 'shipping_logistic_type')) {
                $table->dropColumn('shipping_logistic_type');
            }
        });
    }
};