<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_part_meli_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('automotive_part_enrichment_review_id')->nullable();
            $table->foreign('automotive_part_enrichment_review_id', 'autopart_draft_enrichment_fk')
                ->references('id')->on('automotive_part_enrichment_reviews')->nullOnDelete();
            $table->foreignId('approved_category_candidate_id')->nullable();
            $table->foreign('approved_category_candidate_id', 'autopart_draft_category_candidate_fk')
                ->references('id')->on('automotive_part_meli_category_candidates')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('category_id', 32)->nullable();
            $table->string('category_name')->nullable();
            $table->string('domain_id')->nullable();
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->decimal('price_mxn', 18, 2)->nullable();
            $table->integer('stock')->nullable();
            $table->string('currency', 8)->default('MXN');
            $table->string('condition')->nullable();
            $table->json('prepared_attributes');
            $table->json('prepared_compatibilities');
            $table->json('prepared_images');
            $table->json('source_snapshot');
            $table->string('fingerprint', 64);
            $table->string('status')->default('draft')->index();
            $table->json('blocking_errors');
            $table->json('warnings');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['automotive_part_id', 'fingerprint'], 'autopart_drafts_part_fingerprint_uq');
            $table->unique(['automotive_part_id', 'version'], 'autopart_drafts_part_version_uq');
            $table->index(['automotive_part_id', 'status'], 'autopart_drafts_part_status_idx');
        });

        Schema::create('automotive_part_meli_draft_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_meli_draft_id');
            $table->foreign('automotive_part_meli_draft_id', 'autopart_draft_events_draft_fk')
                ->references('id')->on('automotive_part_meli_drafts')->cascadeOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['automotive_part_meli_draft_id', 'created_at'], 'autopart_draft_events_draft_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_part_meli_draft_events');
        Schema::dropIfExists('automotive_part_meli_drafts');
    }
};
