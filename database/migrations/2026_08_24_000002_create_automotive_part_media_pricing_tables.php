<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automotive_part_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('detected_mime', 64);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('sha256', 64);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('approved_primary_slot')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'archived'])->default('pending');
            $table->enum('provenance_type', ['user_upload', 'supplier_file', 'manufacturer_file', 'owned_photo']);
            $table->string('provenance_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path'], 'autopart_media_disk_path_uq');
            $table->unique(['automotive_part_id', 'sha256'], 'autopart_media_part_sha_uq');
            $table->unique('approved_primary_slot', 'autopart_media_approved_primary_uq');
            $table->index(['automotive_part_id', 'status', 'is_primary'], 'autopart_media_part_status_primary_idx');
        });

        Schema::create('automotive_part_media_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_media_id')->constrained('automotive_part_media')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['automotive_part_media_id', 'created_at'], 'autopart_media_events_media_created_idx');
        });

        Schema::create('automotive_part_price_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('rule_key');
            $table->string('name');
            $table->enum('scope_type', ['global', 'category', 'vendor', 'automotive_part']);
            $table->string('scope_value')->nullable();
            $table->string('source_currency', 8)->default('USD');
            $table->string('target_currency', 8)->default('MXN');
            $table->decimal('usd_mxn_rate', 18, 6);
            $table->decimal('markup_percent', 10, 4)->default(0);
            $table->decimal('meli_fee_percent', 10, 4)->default(0);
            $table->decimal('fixed_cost_mxn', 18, 4)->default(0);
            $table->enum('rounding_mode', ['none', 'nearest', 'up', 'down'])->default('nearest');
            $table->decimal('rounding_increment', 18, 4)->default(1);
            $table->decimal('minimum_price_mxn', 18, 2)->nullable();
            $table->decimal('maximum_price_mxn', 18, 2)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'superseded'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['rule_key', 'version'], 'autopart_price_rules_key_version_uq');
            $table->index(['scope_type', 'scope_value', 'status'], 'autopart_price_rules_scope_status_idx');
            $table->index(['effective_from', 'effective_until'], 'autopart_price_rules_effective_idx');
        });

        Schema::create('automotive_part_price_rule_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_price_rule_id');
            $table->foreign('automotive_part_price_rule_id', 'autopart_price_events_rule_fk')
                ->references('id')->on('automotive_part_price_rules')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['automotive_part_price_rule_id', 'created_at'], 'autopart_price_events_rule_created_idx');
        });

        Schema::create('automotive_part_price_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('price_rule_id')->constrained('automotive_part_price_rules')->restrictOnDelete();
            $table->decimal('source_price', 18, 4);
            $table->string('source_currency', 8);
            $table->decimal('exchange_rate', 18, 6);
            $table->decimal('calculated_price_mxn', 18, 2);
            $table->json('calculation_breakdown');
            $table->string('fingerprint', 64);
            $table->enum('status', ['valid', 'stale'])->default('valid');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['automotive_part_id', 'fingerprint'], 'autopart_price_calcs_part_fingerprint_uq');
            $table->index(['automotive_part_id', 'status', 'calculated_at'], 'autopart_price_calcs_part_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automotive_part_price_calculations');
        Schema::dropIfExists('automotive_part_price_rule_events');
        Schema::dropIfExists('automotive_part_price_rules');
        Schema::dropIfExists('automotive_part_media_events');
        Schema::dropIfExists('automotive_part_media');
    }
};
