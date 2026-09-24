<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->foreignId('primary_location_id')
                ->nullable()
                ->after('is_active')
                ->constrained('inventory_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropForeign(['primary_location_id']);
            $table->dropColumn('primary_location_id');
        });
    }
};
