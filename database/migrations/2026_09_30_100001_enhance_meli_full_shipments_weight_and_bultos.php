<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar peso en kg a inventory_products
        Schema::table('inventory_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_products', 'weight_kg')) {
                $table->decimal('weight_kg', 8, 3)->default(1.000)->after('price_public');
            }
        });

        // 2. Agregar total_bultos y total_weight_kg a meli_full_shipments
        Schema::table('meli_full_shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('meli_full_shipments', 'total_bultos')) {
                $table->unsignedInteger('total_bultos')->default(1)->after('total_boxes');
            }
            if (! Schema::hasColumn('meli_full_shipments', 'total_weight_kg')) {
                $table->decimal('total_weight_kg', 10, 2)->default(0)->after('total_units');
            }
        });

        // 3. Agregar bulto_number, boxes_in_bulto y capacity_kg a meli_full_shipment_boxes
        Schema::table('meli_full_shipment_boxes', function (Blueprint $table): void {
            if (! Schema::hasColumn('meli_full_shipment_boxes', 'bulto_number')) {
                $table->unsignedInteger('bulto_number')->default(1)->after('box_number');
            }
            if (! Schema::hasColumn('meli_full_shipment_boxes', 'boxes_in_bulto')) {
                $table->unsignedInteger('boxes_in_bulto')->default(1)->after('bulto_number');
            }
            if (! Schema::hasColumn('meli_full_shipment_boxes', 'capacity_kg')) {
                $table->decimal('capacity_kg', 8, 2)->default(30.00)->after('capacity');
            }
        });

        // 4. Agregar unit_weight_kg y total_weight_kg a meli_full_shipment_items
        Schema::table('meli_full_shipment_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('meli_full_shipment_items', 'unit_weight_kg')) {
                $table->decimal('unit_weight_kg', 8, 3)->default(1.000)->after('quantity_sent');
            }
            if (! Schema::hasColumn('meli_full_shipment_items', 'total_weight_kg')) {
                $table->decimal('total_weight_kg', 8, 3)->default(0.000)->after('unit_weight_kg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meli_full_shipment_items', function (Blueprint $table): void {
            $table->dropColumn(['unit_weight_kg', 'total_weight_kg']);
        });

        Schema::table('meli_full_shipment_boxes', function (Blueprint $table): void {
            $table->dropColumn(['bulto_number', 'boxes_in_bulto', 'capacity_kg']);
        });

        Schema::table('meli_full_shipments', function (Blueprint $table): void {
            $table->dropColumn(['total_bultos', 'total_weight_kg']);
        });

        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropColumn(['weight_kg']);
        });
    }
};
