<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_dispatches')) {
            return;
        }

        Schema::create('inventory_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('dispatch_number', 50);
            $table->string('create_idempotency_key', 100)->nullable();
            $table->string('create_payload_hash', 64)->nullable();
            $table->string('confirm_idempotency_key', 100)->nullable();
            $table->string('confirm_payload_hash', 64)->nullable();
            $table->unsignedInteger('draft_version')->default(1);
            $table->foreignId('destination_id')
                ->constrained('inventory_dispatch_destinations')
                ->restrictOnDelete();
            $table->string('destination_code_snapshot', 50)->nullable();
            $table->string('destination_name_snapshot', 150)->nullable();
            $table->string('destination_type_snapshot', 32)->nullable();
            $table->foreignId('source_location_id')
                ->nullable()
                ->constrained('inventory_locations')
                ->nullOnDelete();
            $table->string('operation_type', 32);
            $table->string('commercial_status', 32)->default('NOT_APPLICABLE');
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('effective_at');
            $table->boolean('is_retroactive')->default(false);
            $table->text('retroactive_reason')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('delivered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('delivered_by_name_snapshot', 150)->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('confirmed_by_name_snapshot', 150)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->string('cancel_physical_situation', 32)->nullable();
            $table->unsignedInteger('total_lines_count')->default(0);
            $table->unsignedInteger('total_physical_units')->default(0);
            $table->unsignedInteger('unconverted_containers_count')->default(0);
            $table->unsignedInteger('total_units_returned')->default(0);
            $table->decimal('reference_subtotal_cost', 14, 2)->nullable();
            $table->boolean('reference_cost_complete')->default(false);
            $table->decimal('reference_subtotal_stylist', 14, 2)->nullable();
            $table->boolean('reference_stylist_complete')->default(false);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('dispatch_number', 'inv_disp_number_uq');
            $table->unique('create_idempotency_key', 'inv_disp_create_idem_uq');
            $table->unique('confirm_idempotency_key', 'inv_disp_confirm_idem_uq');
            $table->index(['destination_id', 'effective_at'], 'inv_disp_dest_eff_idx');
            $table->index(['status', 'effective_at'], 'inv_disp_status_eff_idx');
            $table->index(['operation_type', 'status', 'effective_at'], 'inv_disp_op_status_eff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dispatches');
    }
};
