<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->boolean('stock_sync_enabled')->default(false)->after('is_active');
            $table->index(['channel', 'is_active', 'stock_sync_enabled'], 'inventory_channel_links_stock_sync_idx');
        });
        DB::table('inventory_channel_links')->update(['stock_sync_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->dropIndex('inventory_channel_links_stock_sync_idx');
            $table->dropColumn('stock_sync_enabled');
        });
    }
};
