<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_meli_order_reconciliations')) {
            return;
        }

        Schema::create('inventory_meli_order_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id');
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('remote_order_id', 64)->nullable();
            $table->string('state', 24)->default('IDLE');
            $table->unsignedBigInteger('requested_revision')->default(0);
            $table->unsignedBigInteger('processed_revision')->default(0);
            $table->unsignedBigInteger('last_enqueued_revision')->default(0);
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_meli_order_reconciliations');
    }
};
