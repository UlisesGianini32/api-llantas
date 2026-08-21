<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llanta_sku_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llanta_id')->constrained('llantas')->cascadeOnDelete();
            $table->string('sku_alias')->unique();
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->index(['llanta_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llanta_sku_aliases');
    }
};
