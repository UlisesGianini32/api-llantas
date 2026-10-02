<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ams_product_issues', function (Blueprint $table) {
            $table->id();
            $table->string('item_id', 64)->index();
            $table->string('sku', 128)->nullable()->index();
            $table->text('title')->nullable();
            $table->text('current_image_url')->nullable();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('shipping_id', 64)->nullable();
            $table->string('issue_type', 32)->index(); // sin_imagen, imagen_incorrecta, sku_incorrecto, titulo_incorrecto, otro
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending')->index(); // pending, resolved, ignored
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('applied_changes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ams_product_issues');
    }
};
