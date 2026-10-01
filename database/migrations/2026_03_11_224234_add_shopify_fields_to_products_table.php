<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('ml')->nullable()->index();
                $table->string('sku')->nullable()->index();
                $table->string('official_store_id')->nullable();
                $table->string('category_name')->nullable();
                $table->string('category_id')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->integer('stock')->default(0);
                $table->string('status_ml')->nullable();
                $table->string('thumbnail', 1000)->nullable();
                $table->text('permalink')->nullable();
                $table->string('brand', 255)->nullable();
                $table->json('pictures')->nullable();
                $table->longText('description')->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('thumbnail', 1000)->nullable()->after('status_ml');
            $table->text('permalink')->nullable()->after('thumbnail');
            $table->string('brand', 255)->nullable()->after('permalink');
            $table->json('pictures')->nullable()->after('brand');
            $table->longText('description')->nullable()->after('pictures');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'thumbnail',
                'permalink',
                'brand',
                'pictures',
                'description',
            ]);
        });
    }
};