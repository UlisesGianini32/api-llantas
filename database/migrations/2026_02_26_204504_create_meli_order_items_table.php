<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meli_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();

            $table->string('item_id', 30);          // MLMxxxx
            $table->string('sku', 120)->nullable(); // tu SKU interno
            $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->nullable();

            $table->timestamps();

            $table->unique(['meli_order_id', 'item_id']); // idempotencia por item
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_order_items');
    }
};