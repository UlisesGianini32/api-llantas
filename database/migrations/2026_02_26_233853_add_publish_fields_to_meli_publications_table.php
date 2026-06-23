<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meli_publications', function (Blueprint $table) {
            $table->string('category_id')->nullable()->index();
            $table->json('pictures')->nullable(); // guarda ids o urls elegidas
            $table->boolean('is_current')->default(true)->index(); // cuál es la vigente por sku
        });
    }

    public function down(): void
    {
        Schema::table('meli_publications', function (Blueprint $table) {
            $table->dropColumn(['category_id','pictures','is_current']);
        });
    }
};