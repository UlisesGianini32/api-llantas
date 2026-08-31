<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_claims', function (Blueprint $table): void {
            $table->json('messages')->nullable()->after('available_actions');
            $table->json('changes')->nullable()->after('messages');
        });
    }

    public function down(): void
    {
        Schema::table('meli_claims', function (Blueprint $table): void {
            $table->dropColumn(['messages', 'changes']);
        });
    }
};
