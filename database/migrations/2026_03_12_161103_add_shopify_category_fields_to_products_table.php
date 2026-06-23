<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('shopify_category_id')->nullable()->after('category_id');
            $table->string('shopify_category_name')->nullable()->after('shopify_category_id');
            $table->string('shopify_category_source')->nullable()->after('shopify_category_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_category_id',
                'shopify_category_name',
                'shopify_category_source',
            ]);
        });
    }
};