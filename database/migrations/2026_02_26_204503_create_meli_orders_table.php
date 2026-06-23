<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meli_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique(); // ML order id
            $table->string('topic', 50)->default('orders_v2');
            $table->string('resource', 100)->nullable(); // /orders/{id}
            $table->string('status', 40)->nullable();     // paid, cancelled, etc
            $table->json('raw')->nullable();              // payload completo de la orden
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_orders');
    }
};