<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_products', 'brand')) {
                $table->string('brand', 100)->nullable()->after('name')->index();
            }
            if (! Schema::hasColumn('inventory_products', 'supplier')) {
                $table->string('supplier', 100)->nullable()->after('brand')->index();
            }
            if (! Schema::hasColumn('inventory_products', 'min_stock')) {
                $table->unsignedInteger('min_stock')->default(0)->after('cost');
            }
            if (! Schema::hasColumn('inventory_products', 'lead_time_days')) {
                $table->unsignedInteger('lead_time_days')->nullable()->after('min_stock');
            }
            if (! Schema::hasColumn('inventory_products', 'restock_cadence_days')) {
                $table->unsignedInteger('restock_cadence_days')->nullable()->after('lead_time_days');
            }
        });

        Schema::create('brand_restock_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('brand', 100)->unique();
            $table->string('supplier', 100)->nullable();
            $table->string('cadence_preset', 30)->default('BIWEEKLY_15_20'); // WEEKLY_7_10, BIWEEKLY_15_20, MONTHLY_30_45, IMPORT_90_120, CUSTOM
            $table->unsignedInteger('lead_time_days')->default(7);
            $table->unsignedInteger('target_coverage_days')->default(15);
            $table->unsignedInteger('safety_stock_days')->default(5);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('cadence_preset');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_restock_configurations');

        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropColumn([
                'brand',
                'supplier',
                'min_stock',
                'lead_time_days',
                'restock_cadence_days',
            ]);
        });
    }
};
