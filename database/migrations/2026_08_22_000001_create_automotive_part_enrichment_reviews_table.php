<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_part_enrichment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->unique()->constrained('automotive_parts')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->json('issue_codes')->nullable();
            $table->string('proposed_title')->nullable();
            $table->text('proposed_description')->nullable();
            $table->string('proposed_brand')->nullable();
            $table->string('proposed_category')->nullable();
            $table->json('proposed_compatibility')->nullable();
            $table->json('proposed_attributes')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('enrichment_source')->default('rules');
            $table->text('reviewer_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'enrichment_source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_part_enrichment_reviews');
    }
};
