<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syscom_categories', function (Blueprint $table) {
            $table->id();

            $table->string('syscom_category_id', 32)->unique();
            $table->string('name', 255);
            $table->unsignedSmallInteger('level')->nullable();

            $table->string('parent_syscom_category_id', 32)
                ->nullable()
                ->index();

            $table->text('path')->nullable();

            $table->json('raw')->nullable();

            $table->timestamps();
        });

        Schema::create('syscom_product_category', function (Blueprint $table) {
            $table->id();

            $table->foreignId('syscom_product_id')
                ->constrained('syscom_products')
                ->cascadeOnDelete();

            $table->foreignId('syscom_category_id')
                ->constrained('syscom_categories')
                ->cascadeOnDelete();

            $table->boolean('is_primary')->default(false);

            $table->string('source', 32)
                ->default('raw_list');

            $table->timestamps();

            $table->unique(
                ['syscom_product_id', 'syscom_category_id'],
                'syscom_product_category_unique'
            );
        });

        Schema::table('syscom_products', function (Blueprint $table) {
            $table->foreignId('syscom_primary_category_id')
                ->nullable()
                ->after('categorias')
                ->constrained('syscom_categories')
                ->nullOnDelete();
        });

        /*
         * Una categoría SYSCOM tendrá una categoría ML definitiva.
         * Al principio quedan sin mapear.
         */
        Schema::create('syscom_meli_category_maps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('syscom_category_id')
                ->unique()
                ->constrained('syscom_categories')
                ->cascadeOnDelete();

            $table->string('meli_category_id', 32)->nullable();
            $table->string('meli_category_name', 255)->nullable();
            $table->text('meli_category_path')->nullable();

            $table->unsignedTinyInteger('confidence')->nullable();

            $table->boolean('approved')->default(false);

            $table->string('source', 32)
                ->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syscom_meli_category_maps');

        Schema::table('syscom_products', function (Blueprint $table) {
            $table->dropForeign(['syscom_primary_category_id']);
            $table->dropColumn('syscom_primary_category_id');
        });

        Schema::dropIfExists('syscom_product_category');
        Schema::dropIfExists('syscom_categories');
    }
};
