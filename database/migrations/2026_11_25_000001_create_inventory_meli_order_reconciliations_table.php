<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_meli_order_reconciliations')) {
            Schema::create('inventory_meli_order_reconciliations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('meli_order_id');
                $table->unsignedBigInteger('meli_account_id')->nullable();
                $table->string('remote_order_id', 64)->nullable();
                $table->string('state', 24)->default('IDLE');
                $table->unsignedBigInteger('requested_revision')->default(0);
                $table->unsignedBigInteger('processed_revision')->default(0);
                $table->unsignedBigInteger('last_enqueued_revision')->default(0);
                $table->unsignedBigInteger('fencing_token')->default(0);
                $table->unsignedBigInteger('pending_job_id')->nullable();
                $table->timestamp('dispatch_confirmed_at')->nullable();
                $table->boolean('stock_sync_required')->default(false);
                $table->unsignedBigInteger('stock_sync_completed_revision')->default(0);
                $table->string('last_state_fingerprint', 64)->nullable();
                $table->string('last_reconciled_fingerprint', 64)->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->string('lease_owner', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('last_error', 500)->nullable();
                $table->timestamp('last_reconciled_at')->nullable();
                $table->timestamps();

                $table->unique('meli_order_id', 'inv_meli_order_rec_order_uq');
                $table->index('meli_account_id', 'inv_meli_order_rec_account_idx');
                $table->index('remote_order_id', 'inv_meli_order_rec_remote_idx');
                $table->index(['state', 'lease_expires_at'], 'inv_meli_order_rec_state_lease_idx');
                $table->index(['state', 'dispatch_confirmed_at'], 'inv_meli_order_rec_outbox_idx');
                $table->index('pending_job_id', 'inv_meli_order_rec_pending_job_idx');
            });

            return;
        }

        // Additive idempotent guard if an earlier dev/staging table existed without R3.1 columns.
        Schema::table('inventory_meli_order_reconciliations', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_meli_order_reconciliations', 'fencing_token')) {
                $table->unsignedBigInteger('fencing_token')->default(0)->after('last_enqueued_revision');
            }
            if (! Schema::hasColumn('inventory_meli_order_reconciliations', 'pending_job_id')) {
                $table->unsignedBigInteger('pending_job_id')->nullable()->after('fencing_token');
            }
            if (! Schema::hasColumn('inventory_meli_order_reconciliations', 'dispatch_confirmed_at')) {
                $table->timestamp('dispatch_confirmed_at')->nullable()->after('pending_job_id');
            }
            if (! Schema::hasColumn('inventory_meli_order_reconciliations', 'stock_sync_required')) {
                $table->boolean('stock_sync_required')->default(false)->after('dispatch_confirmed_at');
            }
            if (! Schema::hasColumn('inventory_meli_order_reconciliations', 'stock_sync_completed_revision')) {
                $table->unsignedBigInteger('stock_sync_completed_revision')->default(0)->after('stock_sync_required');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_meli_order_reconciliations');
    }
};
