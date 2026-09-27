<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->string('remote_user_product_id', 64)->nullable()->after('external_variant_id');
            $table->index(['channel', 'account_key', 'remote_user_product_id'], 'inv_ch_user_product_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->dropIndex('inv_ch_user_product_idx');
            $table->dropColumn('remote_user_product_id');
        });
    }
};
