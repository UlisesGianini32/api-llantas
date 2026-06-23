<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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