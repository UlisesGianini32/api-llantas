<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_shelf_audits', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_shelf_audits', 'audit_type')) {
                $table->string('audit_type', 30)->default('shelf')->after('audit_number')->index();
            }

            if (! Schema::hasColumn('inventory_shelf_audits', 'brand_name')) {
                $table->string('brand_name', 150)->nullable()->after('audit_type')->index();
            }
        });

        // Permitir inventory_location_id nullable si la base de datos lo soporta
        try {
            Schema::table('inventory_shelf_audits', function (Blueprint $table): void {
                $table->unsignedBigInteger('inventory_location_id')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // Ignorar si el driver no requiere o no permite change sin dbal
        }
    }

    public function down(): void
    {
        Schema::table('inventory_shelf_audits', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_shelf_audits', 'brand_name')) {
                $table->dropColumn('brand_name');
            }
            if (Schema::hasColumn('inventory_shelf_audits', 'audit_type')) {
                $table->dropColumn('audit_type');
            }
        });
    }
};
