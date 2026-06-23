<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syscom_meli_queues', function (Blueprint $table) {
            $table->string('price_mode', 20)->default('auto')->after('price_scope');
            $table->timestamp('price_locked_at')->nullable()->after('price_mode');
        });
    }

    public function down(): void
    {
        Schema::table('syscom_meli_queues', function (Blueprint $table) {
            $table->dropColumn(['price_mode', 'price_locked_at']);
        });
    }
};
