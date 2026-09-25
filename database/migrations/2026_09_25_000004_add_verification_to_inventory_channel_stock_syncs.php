<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_channel_stock_syncs', function (Blueprint $table): void {
            $table->unsignedInteger('verified_quantity')->nullable()->after('previous_known_quantity');
            $table->string('verification_status', 32)->nullable()->after('finished_at');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_channel_stock_syncs', function (Blueprint $table): void {
            $table->dropColumn(['verified_quantity', 'verification_status', 'verified_at']);
        });
    }
};
