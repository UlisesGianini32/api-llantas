<?php

use App\Support\AutopartesConnectionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = AutopartesConnectionGuard::CONNECTION_NAME;

    public function up(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->create('automotive_branch_commercial_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->unique('auto_pol_branch_unique')
                ->constrained('automotive_branches', 'id', 'fk_ap_pol_branch')
                ->restrictOnDelete();
            $table->boolean('pos_sales_enabled')->default(false);
            $table->boolean('fiscal_policy_validated')->default(false);
            $table->string('fiscal_regime_notes', 500)->nullable();
            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_rate', 6, 4)->default(0.0000);
            $table->boolean('manual_discounts_enabled')->default(false);
            $table->decimal('cashier_max_discount_percent', 5, 2)->default(0.00);
            $table->decimal('cashier_max_discount_amount', 12, 2)->default(0.00);
            $table->string('default_thermal_width', 10)->default('80mm');
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_sellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_sel_branch')
                ->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index('auto_sel_user_id_idx');
            $table->string('code', 48);
            $table->string('full_name', 191);
            $table->string('phone', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index('auto_sel_active_idx');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'code'], 'auto_seller_branch_code_unique');
        });

        $schema->create('automotive_wholesale_discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_wdr_branch')
                ->restrictOnDelete();
            $table->string('name', 191);
            $table->boolean('is_active')->default(true)->index('auto_wdr_active_idx');
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity')->nullable();
            $table->string('discount_type', 24);
            $table->decimal('discount_value', 12, 2);
            $table->string('scope_type', 32);
            $table->json('eligible_categories')->nullable();
            $table->json('eligible_part_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('priority')->default(100)->index('auto_wdr_priority_idx');
            $table->boolean('stackable')->default(false);
            $table->boolean('allow_manual_combination')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_discount_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_da_branch')
                ->restrictOnDelete();
            $table->string('token', 64)->unique('auto_da_token_unique');
            $table->char('cart_hash', 64)->index('auto_da_cart_hash_idx');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('status', 24)->default('pending')->index('auto_da_status_idx');
            $table->string('discount_type', 24);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('discount_percentage_effective', 8, 4)->default(0);
            $table->decimal('computed_discount_amount', 12, 2);
            $table->decimal('cart_subtotal_before_manual', 12, 2);
            $table->string('reason', 500);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('consumed_sale_id')->nullable()->index('auto_da_sale_idx');
            $table->timestamps();
        });

        $schema->create('automotive_cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_cr_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_cr_loc')
                ->restrictOnDelete();
            $table->string('code', 48)->unique('auto_cr_code_unique');
            $table->string('name', 191);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index('auto_cr_active_idx');
            $table->timestamps();
        });

        $schema->create('automotive_pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 48)->unique('auto_shift_folio_unique');
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_sh_branch')
                ->restrictOnDelete();
            $table->foreignId('cash_register_id')
                ->constrained('automotive_cash_registers', 'id', 'fk_ap_sh_cr')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_sh_loc')
                ->restrictOnDelete();
            $table->unsignedBigInteger('cashier_id')->index('auto_shift_cashier_idx');
            $table->string('status', 24)->default('OPEN')->index('auto_shift_status_idx');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('closing_cash_expected', 12, 2)->nullable();
            $table->decimal('closing_cash_counted', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->decimal('total_sales_cash', 12, 2)->default(0);
            $table->decimal('total_sales_card', 12, 2)->default(0);
            $table->decimal('total_sales_transfer', 12, 2)->default(0);
            $table->decimal('total_sales_amount', 12, 2)->default(0);
            $table->unsignedInteger('total_sales_count')->default(0);
            $table->decimal('total_cash_in', 12, 2)->default(0);
            $table->decimal('total_cash_out', 12, 2)->default(0);
            $table->decimal('total_refunds_cash', 12, 2)->default(0);
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_pos_shift_id')
                ->constrained('automotive_pos_shifts', 'id', 'fk_ap_cm_shift')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_cm_branch')
                ->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->index('auto_cm_user_idx');
            $table->string('type', 16);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 48)->unique('auto_sale_folio_unique');
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_sale_branch')
                ->restrictOnDelete();
            $table->foreignId('cash_register_id')
                ->constrained('automotive_cash_registers', 'id', 'fk_ap_sale_cr')
                ->restrictOnDelete();
            $table->foreignId('automotive_pos_shift_id')
                ->constrained('automotive_pos_shifts', 'id', 'fk_ap_sale_shift')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_sale_loc')
                ->restrictOnDelete();
            $table->unsignedBigInteger('cashier_id')->index('auto_sale_cashier_idx');
            $table->string('cashier_name_snapshot', 191)->nullable();
            $table->foreignId('seller_id')
                ->constrained('automotive_sellers', 'id', 'fk_ap_sale_seller')
                ->restrictOnDelete();
            $table->string('seller_code_snapshot', 48);
            $table->string('seller_name_snapshot', 191);
            $table->string('customer_name', 191)->nullable();
            $table->string('status', 32)->default('completed')->index('auto_sale_status_idx');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('wholesale_discount_amount', 12, 2)->default(0);
            $table->decimal('manual_discount_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('payment_method', 24);
            $table->string('payment_status', 32)->default('paid');
            $table->decimal('amount_tendered', 12, 2)->default(0);
            $table->decimal('change_due', 12, 2)->default(0);
            $table->string('payment_reference', 191)->nullable();
            $table->json('wholesale_rule_snapshot')->nullable();
            $table->json('manual_discount_snapshot')->nullable();
            $table->foreignId('discount_approval_id')
                ->nullable()
                ->constrained('automotive_discount_approvals', 'id', 'fk_ap_sale_da')
                ->restrictOnDelete();
            $table->string('idempotency_key', 191)->unique('auto_sale_idemp_unique');
            $table->char('payload_hash', 64);
            $table->unsignedInteger('reprint_count')->default(0);
            $table->timestamp('last_reprinted_at')->nullable();
            $table->unsignedBigInteger('last_reprinted_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at'], 'auto_pos_sales_branch_date_idx');
            $table->index(['branch_id', 'seller_id'], 'auto_pos_sales_branch_seller_idx');
        });

        $schema->create('automotive_pos_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_pos_sale_id')
                ->constrained('automotive_pos_sales', 'id', 'fk_ap_si_sale')
                ->cascadeOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_si_part')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_si_loc')
                ->restrictOnDelete();
            $table->foreignId('automotive_movement_id')
                ->nullable()
                ->unique('auto_si_mov_unique')
                ->constrained('automotive_inventory_movements', 'id', 'fk_ap_si_mov')
                ->restrictOnDelete();
            $table->string('part_code_snapshot', 128);
            $table->string('item_number_snapshot', 191)->nullable();
            $table->string('mfg_part_number_snapshot', 191)->nullable();
            $table->string('description_snapshot', 500);
            $table->string('category_snapshot', 191)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->decimal('unit_price_mxn', 12, 2);
            $table->decimal('line_gross_amount', 12, 2);
            $table->decimal('wholesale_discount_amount', 12, 2)->default(0);
            $table->decimal('manual_discount_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        $schema->create('automotive_pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_pos_sale_id')
                ->constrained('automotive_pos_sales', 'id', 'fk_ap_pay_sale')
                ->cascadeOnDelete();
            $table->string('method', 24);
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('verification_status', 32)->default('manual_capture');
            $table->timestamps();
        });

        $schema->create('automotive_pos_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('folio', 48)->unique('auto_ret_folio_unique');
            $table->foreignId('automotive_pos_sale_id')
                ->constrained('automotive_pos_sales', 'id', 'fk_ap_ret_sale')
                ->restrictOnDelete();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_ret_branch')
                ->restrictOnDelete();
            $table->foreignId('automotive_pos_shift_id')
                ->nullable()
                ->constrained('automotive_pos_shifts', 'id', 'fk_ap_ret_shift')
                ->restrictOnDelete();
            $table->unsignedBigInteger('processed_by')->index('auto_ret_proc_by_idx');
            $table->string('type', 24);
            $table->string('reason', 500);
            $table->string('refund_method', 32);
            $table->string('refund_reference', 191)->nullable();
            $table->decimal('refund_amount', 12, 2);
            $table->string('idempotency_key', 191)->unique('auto_ret_idemp_unique');
            $table->char('payload_hash', 64);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $schema->create('automotive_pos_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automotive_pos_return_id')
                ->constrained('automotive_pos_returns', 'id', 'fk_ap_ri_ret')
                ->cascadeOnDelete();
            $table->foreignId('automotive_pos_sale_item_id')
                ->constrained('automotive_pos_sale_items', 'id', 'fk_ap_ri_sale_item')
                ->restrictOnDelete();
            $table->foreignId('automotive_part_id')
                ->constrained('automotive_parts', 'id', 'fk_ap_ri_part')
                ->restrictOnDelete();
            $table->foreignId('automotive_location_id')
                ->constrained('automotive_locations', 'id', 'fk_ap_ri_loc')
                ->restrictOnDelete();
            $table->foreignId('automotive_movement_id')
                ->nullable()
                ->unique('auto_ri_mov_unique')
                ->constrained('automotive_inventory_movements', 'id', 'fk_ap_ri_mov')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_refund_amount', 12, 2);
            $table->decimal('refund_subtotal', 12, 2);
            $table->timestamps();
        });

        // Provisionar caja inicial y candados comerciales estrictamente cerrados dentro de la BD de Autopartes.
        $db = DB::connection($conn);
        $now = now();
        $hmo = $db->table('automotive_branches')->where('code', 'HMO')->first();
        $hmoLocation = $hmo
            ? $db->table('automotive_locations')->where('branch_id', $hmo->id)->where('code', 'HMO-REC-01')->first()
            : null;

        if ($hmo && ! $db->table('automotive_branch_commercial_policies')->where('branch_id', $hmo->id)->exists()) {
            $db->table('automotive_branch_commercial_policies')->insert([
                'branch_id' => $hmo->id,
                'pos_sales_enabled' => false,
                'fiscal_policy_validated' => false,
                'tax_enabled' => false,
                'tax_rate' => '0.0000',
                'manual_discounts_enabled' => false,
                'cashier_max_discount_percent' => '0.00',
                'cashier_max_discount_amount' => '0.00',
                'default_thermal_width' => '80mm',
                'reason' => 'Candado comercial cerrado por defecto en inicialización de Autopartes',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($hmo && $hmoLocation && ! $db->table('automotive_cash_registers')->where('code', 'HMO-CAJA-01')->exists()) {
            $db->table('automotive_cash_registers')->insert([
                'branch_id' => $hmo->id,
                'automotive_location_id' => $hmoLocation->id,
                'code' => 'HMO-CAJA-01',
                'name' => 'CAJA MOSTRADOR HERMOSILLO 01',
                'description' => 'Caja principal de punto de venta para Autopartes Hermosillo',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->dropIfExists('automotive_pos_return_items');
        $schema->dropIfExists('automotive_pos_returns');
        $schema->dropIfExists('automotive_pos_payments');
        $schema->dropIfExists('automotive_pos_sale_items');
        $schema->dropIfExists('automotive_pos_sales');
        $schema->dropIfExists('automotive_pos_cash_movements');
        $schema->dropIfExists('automotive_pos_shifts');
        $schema->dropIfExists('automotive_cash_registers');
        $schema->dropIfExists('automotive_discount_approvals');
        $schema->dropIfExists('automotive_wholesale_discount_rules');
        $schema->dropIfExists('automotive_sellers');
        $schema->dropIfExists('automotive_branch_commercial_policies');
    }
};
