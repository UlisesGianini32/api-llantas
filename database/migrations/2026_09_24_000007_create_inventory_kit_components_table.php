<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_kit_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kit_product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->foreignId('component_product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['kit_product_id', 'component_product_id'], 'inv_kit_component_pair_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_kit_components');
    }
};
