<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llanta_sku_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llanta_id')->nullable()->constrained('llantas')->nullOnDelete();
            $table->string('sku_new');
            $table->text('description_new')->nullable();
            $table->decimal('score', 5, 2)->default(0);
            $table->string('status')->default('pending');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['llanta_id', 'sku_new']);
            $table->index(['status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llanta_sku_candidates');
    }
};
