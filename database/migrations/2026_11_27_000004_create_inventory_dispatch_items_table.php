<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_dispatch_items')) {
            $requiredColumns = [
                'id', 'inventory_dispatch_id', 'line_order', 'inventory_product_id',
                'provisional_item_id', 'inventory_location_id', 'location_code_snapshot',
                'capture_method', 'captured_barcode', 'captured_sku', 'captured_name',
                'captured_brand', 'captured_line', 'captured_tone', 'captured_presentation',
                'captured_quantity', 'captured_unit_of_measure', 'unit_conversion_factor',
                'normalized_physical_quantity', 'quantity_returned', 'unit_cost_snapshot',
                'unit_price_stylist_snapshot', 'line_notes', 'identification_status',
                'linked_at', 'linked_by_user_id', 'inventory_apply_status',
                'cutover_evaluated_at', 'cutover_reference_type', 'cutover_reference_id',
                'cutover_boundary_at', 'inventory_movement_id', 'inventory_movement_external_key',
                'inventory_applied_at', 'inventory_applied_by_user_id',
                'inventory_resolution_notes', 'meli_sync_status', 'created_at', 'updated_at',
            ];
            $missing = array_values(array_filter(
                $requiredColumns,
                static fn (string $col): bool => ! Schema::hasColumn('inventory_dispatch_items', $col),
            ));
            if ($missing !== []) {
                throw new RuntimeException(
                    'La tabla preexistente inventory_dispatch_items tiene un esquema parcial o incompatible; columnas faltantes: '.implode(', ', $missing)
                );
            }

            $hasExtKeyUnique = collect(Schema::getIndexes('inventory_dispatch_items'))
                ->contains(static fn (array $idx): bool => ! empty($idx['unique']) && ($idx['columns'] ?? []) === ['inventory_movement_external_key']);
            if (! $hasExtKeyUnique) {
                throw new RuntimeException(
                    'La tabla preexistente inventory_dispatch_items carece del índice único requerido sobre inventory_movement_external_key.'
                );
            }

            return;
        }

        Schema::create('inventory_dispatch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_dispatch_id')
                ->constrained('inventory_dispatches')
                ->restrictOnDelete();
            $table->unsignedInteger('line_order')->default(1);
            $table->foreignId('inventory_product_id')
                ->nullable()
                ->constrained('inventory_products')
                ->restrictOnDelete();
            $table->foreignId('provisional_item_id')
                ->nullable()
                ->constrained('inventory_provisional_items')
                ->restrictOnDelete();
            $table->foreignId('inventory_location_id')
                ->nullable()
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->string('location_code_snapshot', 100)->nullable();
            $table->string('capture_method', 24)->default('MANUAL_PROVISIONAL');
            $table->string('captured_barcode', 100)->nullable();
            $table->string('captured_sku', 100)->nullable();
            $table->string('captured_name', 255);
            $table->string('captured_brand', 100)->nullable();
            $table->string('captured_line', 100)->nullable();
            $table->string('captured_tone', 50)->nullable();
            $table->string('captured_presentation', 100)->nullable();
            $table->unsignedInteger('captured_quantity');
            $table->string('captured_unit_of_measure', 32)->default('UNIT');
            $table->unsignedInteger('unit_conversion_factor')->nullable();
            $table->unsignedInteger('normalized_physical_quantity')->nullable();
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable();
            $table->decimal('unit_price_stylist_snapshot', 12, 2)->nullable();
            $table->text('line_notes')->nullable();
            $table->string('identification_status', 32)->default('PROVISIONAL');
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inventory_apply_status', 32)->default('PENDING');
            $table->timestamp('cutover_evaluated_at')->nullable();
            $table->string('cutover_reference_type', 48)->nullable();
            $table->unsignedBigInteger('cutover_reference_id')->nullable();
            $table->timestamp('cutover_boundary_at')->nullable();
            $table->foreignId('inventory_movement_id')
                ->nullable()
                ->constrained('inventory_movements')
                ->restrictOnDelete();
            $table->string('inventory_movement_external_key', 191)->nullable();
            $table->timestamp('inventory_applied_at')->nullable();
            $table->foreignId('inventory_applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('inventory_resolution_notes')->nullable();
            $table->string('meli_sync_status', 32)->default('NOT_ELIGIBLE');
            $table->timestamps();

            $table->unique('inventory_movement_external_key', 'inv_disp_item_ext_key_uq');
            $table->index('inventory_dispatch_id', 'inv_disp_item_disp_idx');
            $table->index(['inventory_product_id', 'inventory_apply_status'], 'inv_disp_item_prod_apply_idx');
            $table->index('provisional_item_id', 'inv_disp_item_prov_idx');
            $table->index('identification_status', 'inv_disp_item_id_status_idx');
            $table->index('inventory_apply_status', 'inv_disp_item_apply_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dispatch_items');
    }
};
