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

        $schema->create('automotive_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 48)->unique('auto_rec_folio_unique');
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_rec_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_rec_loc')
                ->restrictOnDelete();
            $table->string('status', 24)->default('draft')->index('auto_rec_status_idx');
            $table->string('reference', 191)->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique('auto_rec_idemp_unique');
            $table->char('confirmation_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by')->index('auto_rec_created_by_idx');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_receipt_id')
                ->constrained('automotive_receipts', 'id', 'fk_ap_reci_receipt')
                ->cascadeOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_reci_part')
                ->restrictOnDelete();
            $table->string('scanned_code', 191)->nullable();
            $table->string('entry_mode', 32)->default('scan');
            $table->string('identity_status_snapshot', 24)->default('provisional');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('scan_events_count')->default(1);
            $table->json('count_detail');
            $table->integer('stock_before')->nullable();
            $table->integer('stock_after')->nullable();
            $table->foreignId('automotive_movement_id')
                ->nullable()
                ->unique('auto_reci_mov_unique')
                ->constrained('automotive_inventory_movements', 'id', 'fk_ap_reci_mov')
                ->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['automotive_receipt_id', 'automotive_part_id'], 'auto_receipt_item_part_unique');
        });

        $schema->create('automotive_stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 48)->unique('auto_adj_folio_unique');
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_adj_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_adj_loc')
                ->restrictOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_adj_part')
                ->restrictOnDelete();
            $table->string('type', 24);
            $table->unsignedInteger('quantity');
            $table->string('reason', 1000);
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('pending')->index('auto_adj_status_idx');
            $table->string('idempotency_key', 191)->unique('auto_adj_idemp_unique');
            $table->char('payload_hash', 64);
            $table->integer('stock_before')->nullable();
            $table->integer('stock_after')->nullable();
            $table->foreignId('automotive_movement_id')
                ->nullable()
                ->unique('auto_adj_mov_unique')
                ->constrained('automotive_inventory_movements', 'id', 'fk_ap_adj_mov')
                ->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->index('auto_adj_created_by_idx');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->dropIfExists('automotive_stock_adjustments');
        $schema->dropIfExists('automotive_receipt_items');
        $schema->dropIfExists('automotive_receipts');
    }
};
