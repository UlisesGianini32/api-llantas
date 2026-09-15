<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deploy with claim writers paused so the baseline has a defined boundary.
        Schema::table('meli_claims', function (Blueprint $table): void {
            $table->timestamp('telegram_notified_at')->nullable()->index();
        });

        DB::table('meli_claims')->whereNull('telegram_notified_at')->update(['telegram_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('meli_claims', function (Blueprint $table): void {
            $table->dropIndex(['telegram_notified_at']);
            $table->dropColumn('telegram_notified_at');
        });
    }
};
