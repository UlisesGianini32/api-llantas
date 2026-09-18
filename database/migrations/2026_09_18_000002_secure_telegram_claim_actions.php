<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_operator_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('meli_claim_action_logs', function (Blueprint $table): void {
            $table->string('telegram_chat_id', 64)->nullable()->after('source')->index();
            $table->timestamp('reconciled_at')->nullable()->after('error_message')->index();
            $table->string('reconciliation_result', 80)->nullable()->after('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::table('meli_claim_action_logs', function (Blueprint $table): void {
            $table->dropIndex(['telegram_chat_id']);
            $table->dropIndex(['reconciled_at']);
            $table->dropColumn(['telegram_chat_id', 'reconciled_at', 'reconciliation_result']);
        });
        Schema::dropIfExists('telegram_operator_identities');
    }
};
