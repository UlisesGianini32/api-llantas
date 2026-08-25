<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_part_meli_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('automotive_part_meli_draft_id')->constrained('automotive_part_meli_drafts')->restrictOnDelete();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->restrictOnDelete();
            $table->string('status', 48)->default('draft')->index();
            $table->string('site_id', 8);
            $table->string('seller_id');
            $table->string('category_id', 32);
            $table->string('listing_type_id');
            $table->string('request_fingerprint', 64);
            $table->string('approved_draft_fingerprint', 64);
            $table->json('local_payload');
            $table->json('validation_payload')->nullable();
            $table->json('validation_response')->nullable();
            $table->string('remote_validation_status')->default('not_requested');
            $table->timestamp('remote_validated_at')->nullable();
            $table->timestamp('remote_validation_expires_at')->nullable();
            $table->foreignId('final_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('final_approved_at')->nullable();
            $table->string('final_approval_fingerprint', 64)->nullable();
            $table->string('meli_item_id')->nullable()->unique();
            $table->string('permalink', 1024)->nullable();
            $table->string('item_status')->nullable();
            $table->json('publication_response')->nullable();
            $table->string('description_status')->default('not_started');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['meli_account_id', 'automotive_part_meli_draft_id'], 'autopart_pub_account_draft_uq');
            $table->index(['meli_account_id', 'published_at'], 'autopart_pub_account_published_idx');
        });

        Schema::create('automotive_part_meli_publication_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id');
            $table->foreign('publication_id', 'autopart_pub_attempts_publication_fk')->references('id')->on('automotive_part_meli_publications')->cascadeOnDelete();
            $table->string('operation', 48);
            $table->unsignedInteger('attempt_number');
            $table->string('request_fingerprint', 64);
            $table->json('sanitized_request')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('meli_request_id')->nullable();
            $table->json('sanitized_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('transient')->default(false);
            $table->boolean('ambiguous_result')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['publication_id', 'operation', 'attempt_number'], 'autopart_pub_attempt_number_uq');
        });

        Schema::create('automotive_part_meli_picture_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id');
            $table->foreign('publication_id', 'autopart_pic_uploads_publication_fk')->references('id')->on('automotive_part_meli_publications')->cascadeOnDelete();
            $table->foreignId('automotive_part_media_id')->constrained('automotive_part_media')->restrictOnDelete();
            $table->string('media_sha256', 64);
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('meli_picture_id')->nullable();
            $table->string('secure_url', 2048)->nullable();
            $table->json('sanitized_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->unique(['publication_id', 'automotive_part_media_id'], 'autopart_pic_uploads_publication_media_uq');
            $table->index(['media_sha256', 'status'], 'autopart_pic_uploads_hash_status_idx');
        });

        Schema::create('automotive_part_meli_publication_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id');
            $table->foreign('publication_id', 'autopart_pub_events_publication_fk')->references('id')->on('automotive_part_meli_publications')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 48)->nullable();
            $table->string('to_status', 48)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['publication_id', 'created_at'], 'autopart_pub_events_publication_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_part_meli_publication_events');
        Schema::dropIfExists('automotive_part_meli_picture_uploads');
        Schema::dropIfExists('automotive_part_meli_publication_attempts');
        Schema::dropIfExists('automotive_part_meli_publications');
    }
};
