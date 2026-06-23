<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llantas', function (Blueprint $table) {
            $table->enum('price_mode', ['auto','manual'])
                  ->default('auto')
                  ->after('precio_ML');

            $table->timestamp('price_locked_at')
                  ->nullable()
                  ->after('price_mode');
        });
    }

    public function down(): void
    {
        Schema::table('llantas', function (Blueprint $table) {
            $table->dropColumn(['price_mode','price_locked_at']);
        });
    }
};

