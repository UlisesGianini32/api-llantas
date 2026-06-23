<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syscom_meli_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syscom_product_id')->constrained('syscom_products')->cascadeOnDelete();
            $table->unsignedBigInteger('syscom_producto_id')->index();
            $table->string('branch_code', 100)->nullable();
            $table->string('status', 40)->default('pending_price')->index();
            $table->decimal('desired_price', 12, 2)->nullable();
            $table->string('mlm')->nullable()->index();
            $table->text('publish_error')->nullable();
            $table->timestamp('last_stock_synced_at')->nullable();
            $table->timestamp('last_price_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'syscom_producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syscom_meli_queues');
    }
};
