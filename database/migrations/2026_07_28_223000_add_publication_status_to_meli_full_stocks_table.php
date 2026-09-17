<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_full_stocks', function (Blueprint $table) {
            $table->string('publication_status', 40)
                ->nullable()
                ->after('permalink');
            $table->json('publication_sub_status')
                ->nullable()
                ->after('publication_status');
            $table->json('publication_tags')
                ->nullable()
                ->after('publication_sub_status');

            $table->index(
                ['meli_account_id', 'publication_status'],
                'meli_full_account_pub_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('meli_full_stocks', function (Blueprint $table) {
            $table->dropIndex('meli_full_account_pub_status_idx');
            $table->dropColumn([
                'publication_status',
                'publication_sub_status',
                'publication_tags',
            ]);
        });
    }
};
