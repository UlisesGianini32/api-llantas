<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syscom_products', function (Blueprint $table) {
            $table->unsignedInteger('stock_hermosillo')->default(0)->after('total_existencia');
        });
    }

    public function down(): void
    {
        Schema::table('syscom_products', function (Blueprint $table) {
            $table->dropColumn('stock_hermosillo');
        });
    }
};
