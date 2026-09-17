<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `meli_brand_aliases` MODIFY `match_type` ENUM('exact', 'contains', 'starts_with', 'manual', 'title_contains') NOT NULL");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('meli_brand_aliases', function (Blueprint $table): void {
                $table->enum('match_type', ['exact', 'contains', 'starts_with', 'manual', 'title_contains'])->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('meli_brand_aliases')
            ->where('match_type', 'title_contains')
            ->update(['match_type' => 'manual']);

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `meli_brand_aliases` MODIFY `match_type` ENUM('exact', 'contains', 'starts_with', 'manual') NOT NULL");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('meli_brand_aliases', function (Blueprint $table): void {
                $table->enum('match_type', ['exact', 'contains', 'starts_with', 'manual'])->change();
            });
        }
    }
};
