<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_scheduled_price_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_manager_item_id')->constrained('meli_price_manager_items')->restrictOnDelete();
            $table->foreignId('meli_beauty_scheduled_discount_id')
                ->constrained('meli_beauty_scheduled_discounts')
                ->restrictOnDelete();
            $table->decimal('base_price', 15, 2);
            $table->decimal('promotional_price', 15, 2);
            $table->decimal('last_confirmed_remote_price', 15, 2)->nullable();
            $table->decimal('last_observed_remote_price', 15, 2)->nullable();
            $table->enum('status', ['active', 'restore_pending', 'restored', 'failed'])->default('active')->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique('price_manager_item_id', 'meli_scheduled_states_item_uq');
            $table->index(['meli_beauty_scheduled_discount_id', 'status'], 'meli_scheduled_states_rule_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_scheduled_price_states');
    }
};