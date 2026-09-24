<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 100)->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('price_mercado_libre', 12, 2)->nullable();
            $table->decimal('price_amazon', 12, 2)->nullable();
            $table->decimal('price_stylist', 12, 2)->nullable();
            $table->decimal('price_public', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_products');
    }
};
