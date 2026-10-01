<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_products') && ! Schema::hasColumn('inventory_products', 'requires_meli_labeling')) {
            Schema::table('inventory_products', function (Blueprint $table): void {
                $table->boolean('requires_meli_labeling')->default(false)->after('weight_kg');
            });
        }

        if (Schema::hasTable('meli_full_shipments') && ! Schema::hasColumn('meli_full_shipments', 'total_labeled_units')) {
            Schema::table('meli_full_shipments', function (Blueprint $table): void {
                $table->unsignedInteger('total_labeled_units')->default(0)->after('total_units');
            });
        }

        if (Schema::hasTable('meli_full_shipment_items') && ! Schema::hasColumn('meli_full_shipment_items', 'requires_labeling')) {
            Schema::table('meli_full_shipment_items', function (Blueprint $table): void {
                $table->boolean('requires_labeling')->default(false)->after('quantity_sent');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meli_full_shipment_items') && Schema::hasColumn('meli_full_shipment_items', 'requires_labeling')) {
            Schema::table('meli_full_shipment_items', function (Blueprint $table): void {
                $table->dropColumn('requires_labeling');
            });
        }

        if (Schema::hasTable('meli_full_shipments') && Schema::hasColumn('meli_full_shipments', 'total_labeled_units')) {
            Schema::table('meli_full_shipments', function (Blueprint $table): void {
                $table->dropColumn('total_labeled_units');
            });
        }

        if (Schema::hasTable('inventory_products') && Schema::hasColumn('inventory_products', 'requires_meli_labeling')) {
            Schema::table('inventory_products', function (Blueprint $table): void {
                $table->dropColumn('requires_meli_labeling');
            });
        }
    }
};
