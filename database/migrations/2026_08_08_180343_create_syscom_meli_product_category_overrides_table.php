<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'syscom_meli_product_category_overrides',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('syscom_product_id')
                    ->unique()
                    ->constrained('syscom_products')
                    ->cascadeOnDelete();

                $table->string(
                    'meli_category_id',
                    32
                );

                $table->string(
                    'meli_category_name'
                )->nullable();

                $table->string(
                    'meli_category_path',
                    1000
                )->nullable();

                $table->unsignedTinyInteger(
                    'confidence'
                )->default(100);

                $table->boolean(
                    'approved'
                )->default(false);

                $table->string(
                    'source',
                    100
                )->nullable();

                $table->text(
                    'note'
                )->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'approved',
                        'meli_category_id',
                    ],
                    'syscom_product_override_approved_mlm_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'syscom_meli_product_category_overrides'
        );
    }
};
