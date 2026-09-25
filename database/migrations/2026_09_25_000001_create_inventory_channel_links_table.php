<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->string('channel', 32);
            $table->string('account_key')->nullable();
            $table->string('external_product_id')->nullable();
            $table->string('external_variant_id')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->text('external_url')->nullable();
            $table->string('remote_status')->nullable();
            $table->decimal('remote_price', 14, 2)->nullable();
            $table->string('remote_currency', 8)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('identity_key', 255)->unique();
            $table->timestamps();

            $table->index(['channel', 'account_key']);
            $table->index('external_product_id');
            $table->index('external_variant_id');
            $table->index('external_listing_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_channel_links');
    }
};
