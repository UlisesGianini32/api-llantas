<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_claim_action_logs', function (Blueprint $table): void {
            $table->string('source', 20)->default('web')->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('meli_claim_action_logs', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
