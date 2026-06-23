<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('meli_order_items', 'title')) {
                $table->string('title')->nullable()->after('sku');
            }

            if (!Schema::hasColumn('meli_order_items', 'variation_text')) {
                $table->string('variation_text')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meli_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('meli_order_items', 'variation_text')) {
                $table->dropColumn('variation_text');
            }

            if (Schema::hasColumn('meli_order_items', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};