<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->restrictOnDelete();
            $table->foreignId('brand_group_id')->constrained('meli_brand_groups')->restrictOnDelete();
            $table->decimal('discount_percentage', 5, 2);
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('timezone', 64);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['meli_account_id', 'brand_group_id'], 'meli_beauty_discounts_account_brand_uq');
            $table->index(['active', 'starts_at', 'ends_at'], 'meli_beauty_discounts_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_beauty_scheduled_discounts');
    }
};