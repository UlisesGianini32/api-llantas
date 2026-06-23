<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meli_publications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->index();
            $table->string('sku')->index();
            $table->string('mlm')->index();

            $table->string('status')->nullable()->index(); // active/paused/closed/under_review...
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();

            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'mlm']);
            $table->index(['user_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_publications');
    }
};