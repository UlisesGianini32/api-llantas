<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE syscom_meli_category_maps
            MODIFY source VARCHAR(100) NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE syscom_meli_category_maps
            MODIFY source VARCHAR(32) NOT NULL DEFAULT 'pending'
        ");
    }
};
