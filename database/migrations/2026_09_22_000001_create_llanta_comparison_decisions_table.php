<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llanta_comparison_decisions', function (Blueprint $table): void {
            $table->id();
            // No FK cascade: llantas:purgar-stock-cero must remain usable and decisions stay auditable.
            $table->unsignedBigInteger('llanta_a_id');
            $table->unsignedBigInteger('llanta_b_id');
            $table->decimal('score', 5, 2)->default(0);
            $table->json('reasons')->nullable();
            $table->json('differences')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->timestamps();

            $table->unique(['llanta_a_id', 'llanta_b_id'], 'llanta_comparison_pair_unique');
            $table->index(['status', 'score'], 'llanta_comparison_status_score_index');
            $table->index('last_detected_at', 'llanta_comparison_last_detected_index');
            $table->index('decided_by', 'llanta_comparison_decided_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llanta_comparison_decisions');
    }
};
