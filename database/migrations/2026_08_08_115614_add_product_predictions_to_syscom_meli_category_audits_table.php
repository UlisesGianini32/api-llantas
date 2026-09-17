<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasColumn(
                'syscom_meli_category_audits',
                'product_predictions'
            )
        ) {
            Schema::table(
                'syscom_meli_category_audits',
                function (Blueprint $table) {
                    $table->json('product_predictions')
                        ->nullable()
                        ->after('distribution');
                }
            );
        }
    }

    public function down(): void
    {
        if (
            Schema::hasColumn(
                'syscom_meli_category_audits',
                'product_predictions'
            )
        ) {
            Schema::table(
                'syscom_meli_category_audits',
                function (Blueprint $table) {
                    $table->dropColumn(
                        'product_predictions'
                    );
                }
            );
        }
    }
};
