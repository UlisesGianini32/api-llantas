<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
            $table->boolean('all_day')->default(false)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
            $table->dropColumn('all_day');
        });
    }
};
