<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_price_change_batches', function (Blueprint $table): void {
            $table->string('source', 32)->default('manual')->after('type')->index();
            $table->foreignId('meli_beauty_scheduled_discount_id')
                ->nullable()
                ->after('brand_group_id')
                ->constrained('meli_beauty_scheduled_discounts')
                ->nullOnDelete();
        });

        Schema::table('meli_price_changes', function (Blueprint $table): void {
            $table->string('source', 32)->default('manual')->after('status')->index();
            $table->string('scheduled_action', 32)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('meli_price_changes', function (Blueprint $table): void {
            $table->dropIndex('meli_price_changes_source_index');
            $table->dropColumn(['source', 'scheduled_action']);
        });
        Schema::table('meli_price_change_batches', function (Blueprint $table): void {
            $table->dropForeign(['meli_beauty_scheduled_discount_id']);
            $table->dropIndex('meli_price_change_batches_source_index');
            $table->dropColumn(['source', 'meli_beauty_scheduled_discount_id']);
        });
    }
};