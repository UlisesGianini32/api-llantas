<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_claim_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('reason_id', 100)->unique();
            $table->string('name')->nullable();
            $table->text('detail')->nullable();
            $table->string('flow', 100)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('meli_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->cascadeOnDelete();
            $table->foreignId('meli_order_id')->nullable()->constrained('meli_orders')->nullOnDelete();
            $table->string('claim_id', 64);
            $table->index('claim_id');
            $table->string('resource')->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('pack_id', 64)->nullable()->index();
            $table->string('type', 80)->nullable()->index();
            $table->string('stage', 80)->nullable()->index();
            $table->string('status', 80)->nullable()->index();
            $table->string('reason_id', 100)->nullable()->index();
            $table->boolean('fulfilled')->nullable();
            $table->unsignedInteger('claimed_quantity')->nullable();
            $table->string('action_responsible', 80)->nullable()->index();
            $table->timestamp('due_date')->nullable()->index();
            $table->string('detail_title')->nullable();
            $table->text('detail_description')->nullable();
            $table->text('problem')->nullable();
            $table->boolean('affects_reputation')->nullable()->index();
            $table->boolean('reputation_has_incentive')->nullable();
            $table->timestamp('reputation_due_date')->nullable();
            $table->string('resolution_reason')->nullable();
            $table->timestamp('date_created')->nullable()->index();
            $table->timestamp('last_updated')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->text('sync_error')->nullable();
            $table->json('raw_claim')->nullable();
            $table->json('raw_detail')->nullable();
            $table->json('status_history')->nullable();
            $table->json('actions_history')->nullable();
            $table->json('expected_resolutions')->nullable();
            $table->json('available_actions')->nullable();
            $table->timestamps();

            $table->unique(['meli_account_id', 'claim_id'], 'meli_claims_account_claim_unique');
            $table->index(['meli_account_id', 'status', 'stage', 'due_date'], 'meli_claims_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_claims');
        Schema::dropIfExists('meli_claim_reasons');
    }
};
