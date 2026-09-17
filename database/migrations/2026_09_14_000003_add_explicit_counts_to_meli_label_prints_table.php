<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_label_prints', function (Blueprint $table): void {
            $table->unsignedSmallInteger('zpl_blocks_count')->nullable()->after('labels_count');
            $table->unsignedInteger('physical_labels_count')->nullable()->after('zpl_blocks_count');
        });

        DB::table('meli_label_prints')->update([
            'zpl_blocks_count' => DB::raw('labels_count'),
            'physical_labels_count' => DB::raw('labels_count'),
        ]);
    }

    public function down(): void
    {
        Schema::table('meli_label_prints', function (Blueprint $table): void {
            $table->dropColumn(['zpl_blocks_count', 'physical_labels_count']);
        });
    }
};
