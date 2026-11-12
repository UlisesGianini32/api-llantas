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

        $schema->create('automotive_part_branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_pbs_part')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_pbs_branch')
                ->restrictOnDelete();
            $table->foreignId('primary_location_id')
                ->nullable()
                ->constrained('automotive_locations', 'id', 'fk_ap_pbs_loc1')
                ->restrictOnDelete();
            $table->foreignId('secondary_location_id')
                ->nullable()
                ->constrained('automotive_locations', 'id', 'fk_ap_pbs_loc2')
                ->restrictOnDelete();
            $table->decimal('sale_price_mxn', 12, 2)->nullable();
            $table->string('price_status', 32)->default('unpriced')->index('auto_pbs_price_status_idx');
            $table->unsignedBigInteger('price_updated_by')->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->unsignedBigInteger('price_approved_by')->nullable();
            $table->timestamp('price_approved_at')->nullable();
            $table->string('price_reason', 500)->nullable();
            $table->boolean('is_sellable')->default(false);
            $table->boolean('sale_blocked')->default(false);
            $table->string('sale_block_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['automotive_part_id', 'branch_id'], 'auto_part_branch_set_unique');
        });

        // Existencias físicas dedicadas por autoparte y ubicación, bloqueables fila por fila bajo InnoDB FOR UPDATE.
        $schema->create('automotive_inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_bal_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_bal_part')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_bal_loc')
                ->restrictOnDelete();
            $table->unsignedInteger('physical_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->timestamps();

            $table->unique(['automotive_part_id', 'automotive_location_id'], 'auto_balance_part_loc_unique');
            $table->index(['branch_id', 'automotive_part_id'], 'auto_balance_branch_part_idx');
        });

        // Libro mayor histórico e inmutable exclusivo de Autopartes (sin relación con inventory_movements).
        $schema->create('automotive_inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_mov_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_mov_part')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_mov_loc')
                ->restrictOnDelete();
            $table->string('type', 32)->index('auto_mov_type_idx'); // RECEIPT | ADJUSTMENT_IN | ADJUSTMENT_OUT | SALE | RETURN | CANCEL
            $table->integer('quantity');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference', 191)->nullable()->index('auto_mov_ref_folio_idx');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->string('external_key', 191)->nullable()->unique('auto_mov_external_key_unique');
            $table->unsignedBigInteger('created_by')->nullable()->index('auto_mov_created_by_idx');
            $table->timestamp('occurred_at')->nullable()->index('auto_mov_occurred_at_idx');
            $table->timestamps();

            $table->index(['branch_id', 'automotive_part_id', 'occurred_at'], 'auto_mov_branch_part_date_idx');
            $table->index(['reference_type', 'reference_id'], 'auto_mov_ref_idx');
        });

        $schema->create('automotive_price_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_ph_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_ph_part')
                ->restrictOnDelete();
            $table->decimal('previous_price_mxn', 12, 2)->nullable();
            $table->decimal('new_price_mxn', 12, 2)->nullable();
            $table->string('previous_price_status', 32)->default('unpriced');
            $table->string('new_price_status', 32)->default('unpriced');
            $table->boolean('previous_is_sellable')->default(false);
            $table->boolean('new_is_sellable')->default(false);
            $table->boolean('previous_sale_blocked')->default(false);
            $table->boolean('new_sale_blocked')->default(false);
            $table->string('operation_type', 32)->default('single');
            $table->string('batch_reference', 64)->nullable()->index('auto_ph_batch_ref_idx');
            $table->string('reason', 500);
            $table->unsignedBigInteger('changed_by')->index('auto_ph_changed_by_idx');
            $table->timestamps();

            $table->index(['branch_id', 'automotive_part_id'], 'auto_price_hist_branch_part_idx');
        });
    }

    public function down(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->dropIfExists('automotive_price_histories');
        $schema->dropIfExists('automotive_inventory_movements');
        $schema->dropIfExists('automotive_inventory_balances');
        $schema->dropIfExists('automotive_part_branch_settings');
    }
};
