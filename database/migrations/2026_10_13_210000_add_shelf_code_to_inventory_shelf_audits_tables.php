<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_shelf_audits', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_shelf_audits', 'shelf_code')) {
                $table->string('shelf_code', 50)->nullable()->after('audit_number')->index();
            }
        });

        Schema::table('inventory_shelf_audit_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_shelf_audit_items', 'location_breakdown')) {
                $table->string('location_breakdown', 500)->nullable()->after('difference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_shelf_audit_items', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_shelf_audit_items', 'location_breakdown')) {
                $table->dropColumn('location_breakdown');
            }
        });

        Schema::table('inventory_shelf_audits', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_shelf_audits', 'shelf_code')) {
                $table->dropColumn('shelf_code');
            }
        });
    }
};
