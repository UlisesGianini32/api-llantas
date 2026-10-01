<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE syscom_meli_category_maps
                MODIFY source VARCHAR(100) NOT NULL DEFAULT 'pending'
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE syscom_meli_category_maps
                MODIFY source VARCHAR(32) NOT NULL DEFAULT 'pending'
            ");
        }
    }
};
