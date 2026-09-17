<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_order_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id')->nullable()->constrained('meli_orders')->nullOnDelete();
            $table->string('remote_order_id', 40)->index();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('reason', 80);
            $table->json('request_payload_sanitized');
            $table->string('remote_response_id', 100)->nullable();
            $table->unsignedSmallInteger('remote_status')->nullable();
            $table->boolean('success')->nullable()->index();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['meli_account_id', 'remote_order_id', 'created_at'], 'meli_order_action_cooldown_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_order_action_logs');
    }
};
