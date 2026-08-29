<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table): void {
            $table->decimal('estimated_receivable', 15, 2)->nullable()->after('current_price');
            $table->decimal('estimated_receivable_price', 15, 2)->nullable()->after('estimated_receivable');
            $table->timestamp('estimated_receivable_calculated_at')->nullable()->after('estimated_receivable_price');
        });
    }

    public function down(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table): void {
            $table->dropColumn([
                'estimated_receivable',
                'estimated_receivable_price',
                'estimated_receivable_calculated_at',
            ]);
        });
    }
};
