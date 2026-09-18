<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_chat_flows', function (Blueprint $table): void {
            $table->string('last_message_role', 20)->nullable()->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->text('last_message_text')->nullable();
            $table->timestamp('last_message_synced_at')->nullable();
        });

        Schema::create('telegram_conversation_states', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 64)->unique();
            $table->string('mode', 64);
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('telegram_processed_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('update_key', 160)->unique();
            $table->timestamp('processed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_processed_updates');
        Schema::dropIfExists('telegram_conversation_states');
        Schema::table('meli_chat_flows', function (Blueprint $table): void {
            $table->dropIndex(['last_message_role']);
            $table->dropIndex(['last_message_at']);
        });
        Schema::table('meli_chat_flows', function (Blueprint $table): void {
            $table->dropColumn(['last_message_role', 'last_message_at', 'last_message_text', 'last_message_synced_at']);
        });
    }
};
