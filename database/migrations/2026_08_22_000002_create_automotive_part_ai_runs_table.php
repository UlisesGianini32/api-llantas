<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_part_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('automotive_part_enrichment_review_id');
            $table->foreign('automotive_part_enrichment_review_id', 'autopart_ai_runs_review_fk')
                ->references('id')
                ->on('automotive_part_enrichment_reviews')
                ->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('model');
            $table->string('prompt_version');
            $table->string('request_fingerprint', 64)->unique();
            $table->json('input_snapshot');
            $table->json('output_payload')->nullable();
            $table->string('response_id')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['automotive_part_enrichment_review_id', 'created_at'], 'autopart_ai_runs_review_created_idx');
            $table->index(['automotive_part_id', 'created_at'], 'autopart_ai_runs_part_created_idx');
            $table->index(['model', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_part_ai_runs');
    }
};
