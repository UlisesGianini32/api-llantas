<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_notification_reads')) {
            Schema::create('system_notification_reads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('notification_key', 160)->index();
                $table->timestamp('read_at')->nullable()->index();
                $table->timestamp('dismissed_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['user_id', 'notification_key'], 'user_notif_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notification_reads');
    }
};
