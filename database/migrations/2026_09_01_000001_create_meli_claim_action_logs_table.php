<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_claim_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_claim_id')->constrained('meli_claims')->cascadeOnDelete();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 80);
            $table->string('receiver_role', 40)->nullable();
            $table->json('request_payload_sanitized')->nullable();
            $table->string('message_hash', 64)->index();
            $table->string('remote_response_id', 100)->nullable();
            $table->unsignedSmallInteger('remote_status')->nullable();
            $table->boolean('success')->nullable()->index();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['meli_claim_id', 'user_id', 'created_at'], 'meli_claim_action_dedupe_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_claim_action_logs');
    }
};
