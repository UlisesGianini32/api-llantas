<?php

use App\Support\AutopartesConnectionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = AutopartesConnectionGuard::CONNECTION_NAME;

    public function up(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->create('automotive_catalog_files', function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64)->unique('auto_file_sha256_unique');
            $table->char('semantic_sha256', 64)->nullable()->index('auto_file_semantic_sha256_idx');
            $table->string('original_name', 255);
            $table->string('disk', 32);
            $table->string('storage_path', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by')->nullable()->index('auto_file_uploaded_by_idx');
            $table->timestamps();

            $table->unique(['disk', 'storage_path'], 'auto_source_path_unique');
        });

        $schema->create('automotive_catalog_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')
                ->constrained('automotive_catalog_files', 'id', 'fk_ap_sheet_file')
                ->restrictOnDelete();
            $table->unsignedInteger('sheet_index');
            $table->string('name', 255);
            $table->unsignedInteger('header_row_number');
            $table->json('headers');
            $table->json('header_cells')->nullable();
            $table->unsignedInteger('data_row_count');
            $table->unsignedInteger('column_count');
            $table->timestamps();

            $table->unique(['file_id', 'sheet_index'], 'auto_sheet_unique');
        });

        $schema->create('automotive_catalog_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sheet_id')
                ->constrained('automotive_catalog_sheets', 'id', 'fk_ap_row_sheet')
                ->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->char('row_hash', 64)->index('auto_row_hash_idx'); // No unique: filas idénticas se conservan.
            $table->char('semantic_hash', 64)->nullable()->index('auto_row_semantic_hash_idx');
            $table->char('identity_key', 64)->nullable()->index('auto_row_identity_key_idx');
            $table->json('cells'); // 21 columnas originales íntegras.
            $table->text('item_number')->nullable();
            $table->text('manufacturer_part_number')->nullable();
            $table->text('description')->nullable();
            $table->text('category')->nullable();
            $table->text('subcategory')->nullable();
            $table->text('vendor_name')->nullable();
            $table->text('search_text')->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'row_number'], 'auto_source_row_unique');
        });

        // Catálogo maestro independiente del módulo (sin vínculo alguno con inventory_products).
        $schema->create('automotive_parts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique('auto_part_code_unique');
            $table->foreignId('source_row_id')
                ->nullable()
                ->unique('auto_part_source_row_unique')
                ->constrained('automotive_catalog_rows', 'id', 'fk_ap_part_source_row')
                ->restrictOnDelete();
            $table->string('identity_status', 32)->default('provisional')->index('auto_part_identity_idx');
            $table->text('item_number')->nullable();
            $table->text('manufacturer_part_number')->nullable();
            $table->text('vendor_name')->nullable();
            $table->text('category')->nullable();
            $table->text('subcategory')->nullable();
            $table->text('description')->nullable();
            $table->text('applicable_models')->nullable();
            $table->string('verified_barcode', 128)->nullable()->unique('auto_part_verified_barcode_unique');
            $table->boolean('allow_receipt')->default(true);
            $table->unsignedBigInteger('verified_by')->nullable()->index('auto_part_verified_by_idx');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_evidence')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        $schema->create('automotive_part_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_ident_part')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('automotive_branches', 'id', 'fk_ap_ident_branch')
                ->restrictOnDelete();
            $table->string('identifier_type', 32);
            $table->string('identifier_value', 191)->index('auto_ident_value_idx');
            $table->string('identifier_normalized', 191)->index('auto_ident_norm_idx');
            $table->boolean('is_verified')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('evidence');
            $table->unsignedBigInteger('verified_by')->nullable()->index('auto_ident_verified_by_idx');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['automotive_part_id', 'identifier_value'], 'auto_part_ident_unique');
        });

        $schema->create('automotive_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')
                ->constrained('automotive_catalog_files', 'id', 'fk_ap_run_file')
                ->restrictOnDelete();
            $table->foreignId('reference_file_id')
                ->nullable()
                ->constrained('automotive_catalog_files', 'id', 'fk_ap_run_ref_file')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_run_branch')
                ->restrictOnDelete();
            $table->string('parser_version', 64);
            $table->string('mapping_version', 64);
            $table->string('idempotency_key', 191)->unique('auto_run_idemp_unique');
            $table->string('uploaded_name', 255)->nullable();
            $table->string('status', 32)->default('pending')->index('auto_run_status_idx');
            $table->json('counters')->nullable();
            $table->json('preview')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->uuid('lock_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index('auto_run_req_by_idx');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_import_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')
                ->constrained('automotive_import_runs', 'id', 'fk_ap_issue_run')
                ->restrictOnDelete();
            $table->foreignId('source_row_id')
                ->nullable()
                ->constrained('automotive_catalog_rows', 'id', 'fk_ap_issue_row')
                ->restrictOnDelete();
            $table->string('column_reference', 16)->nullable();
            $table->string('severity', 16);
            $table->string('code', 80);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_source_part_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_row_id')
                ->constrained('automotive_catalog_rows', 'id', 'fk_ap_dec_row')
                ->restrictOnDelete();
            $table->foreignId('automotive_part_id')
                ->nullable()
                ->constrained('automotive_parts', 'id', 'fk_ap_dec_part')
                ->restrictOnDelete();
            $table->foreignId('supersedes_id')
                ->nullable()
                ->unique('auto_dec_supersedes_unique')
                ->constrained('automotive_source_part_decisions', 'id', 'fk_ap_dec_supersedes')
                ->restrictOnDelete();
            $table->string('decision', 32);
            $table->text('reason');
            $table->unsignedBigInteger('decided_by')->index('auto_dec_by_idx');
            $table->timestamps();

            $table->index(['source_row_id', 'id'], 'auto_decision_hist_idx');
        });
    }

    public function down(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->dropIfExists('automotive_source_part_decisions');
        $schema->dropIfExists('automotive_import_issues');
        $schema->dropIfExists('automotive_import_runs');
        $schema->dropIfExists('automotive_part_identifiers');
        $schema->dropIfExists('automotive_parts');
        $schema->dropIfExists('automotive_catalog_rows');
        $schema->dropIfExists('automotive_catalog_sheets');
        $schema->dropIfExists('automotive_catalog_files');
    }
};
