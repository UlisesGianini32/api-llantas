<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_chat_flows', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('order_id')->nullable()->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('message_id')->nullable()->index();
            $table->string('buyer_id')->nullable()->index();
            $table->string('item_id')->nullable()->index();
            $table->string('sku')->nullable()->index();

            $table->boolean('menu_sent')->default(false);
            $table->timestamp('menu_sent_at')->nullable();

            $table->string('last_option_selected')->nullable();
            $table->timestamp('last_option_selected_at')->nullable();

            $table->boolean('requires_human')->default(false);
            $table->timestamp('requires_human_at')->nullable();

            $table->string('product_pdf_url')->nullable();
            $table->string('catalog_pdf_url')->nullable();
            $table->string('invoice_url')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'buyer_id'], 'meli_chat_flows_order_buyer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_chat_flows');
    }
};