<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_part_meli_categories', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 8);
            $table->string('category_id', 32);
            $table->string('name');
            $table->string('domain_id')->nullable();
            $table->json('path_from_root');
            $table->json('settings')->nullable();
            $table->json('raw_payload');
            $table->timestamp('attributes_synced_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['site_id', 'category_id'], 'autopart_meli_categories_site_category_uq');
            $table->index('domain_id');
        });

        Schema::create('automotive_part_meli_category_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('automotive_part_enrichment_review_id')->nullable();
            $table->foreign('automotive_part_enrichment_review_id', 'autopart_meli_candidate_review_fk')
                ->references('id')->on('automotive_part_enrichment_reviews')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->string('category_id', 32);
            $table->string('category_name');
            $table->string('domain_id')->nullable();
            $table->string('source');
            $table->text('query_text')->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->decimal('score', 10, 4)->nullable();
            $table->json('evidence');
            $table->json('raw_payload')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['automotive_part_id', 'status'], 'autopart_meli_candidates_part_status_idx');
            $table->index(['category_id', 'source']);
        });

        Schema::create('automotive_part_meli_attribute_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_meli_category_id');
            $table->foreign('automotive_part_meli_category_id', 'autopart_meli_attr_category_fk')
                ->references('id')->on('automotive_part_meli_categories')->cascadeOnDelete();
            $table->string('attribute_id');
            $table->string('name');
            $table->string('value_type')->nullable();
            $table->unsignedInteger('value_max_length')->nullable();
            $table->json('tags');
            $table->json('allowed_values')->nullable();
            $table->string('hierarchy')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_catalog_required')->default(false);
            $table->boolean('is_conditional_required')->default(false);
            $table->json('raw_payload');
            $table->timestamps();

            $table->unique(['automotive_part_meli_category_id', 'attribute_id'], 'autopart_meli_attr_category_attribute_uq');
        });

        Schema::create('automotive_part_meli_readiness', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->unique()->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('approved_category_candidate_id')->nullable();
            $table->foreign('approved_category_candidate_id', 'autopart_meli_readiness_candidate_fk')
                ->references('id')->on('automotive_part_meli_category_candidates')->nullOnDelete();
            $table->string('status')->default('unmapped')->index();
            $table->json('proposed_attributes');
            $table->json('missing_required_attributes');
            $table->json('missing_conditional_attributes');
            $table->json('compatibility_requirements')->nullable();
            $table->json('warnings');
            $table->string('evaluation_fingerprint', 64)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('last_evaluated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_part_meli_readiness');
        Schema::dropIfExists('automotive_part_meli_attribute_requirements');
        Schema::dropIfExists('automotive_part_meli_category_candidates');
        Schema::dropIfExists('automotive_part_meli_categories');
    }
};
