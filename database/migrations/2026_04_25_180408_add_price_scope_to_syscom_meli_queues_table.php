<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syscom_meli_queues', function (Blueprint $table) {
            $table->string('price_scope', 20)->default('llanta')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('syscom_meli_queues', function (Blueprint $table) {
            $table->dropColumn('price_scope');
        });
    }
};