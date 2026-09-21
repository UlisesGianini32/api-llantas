<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_orders', function (Blueprint $table): void {
            $table->string('pack_id', 80)->nullable()->after('shipping_id')->index();
            $table->string('delivery_type', 40)->nullable()->after('shipping_logistic_type');
            $table->string('delivery_classification_reason')->nullable()->after('delivery_type');
            $table->timestamp('delivery_classified_at')->nullable()->after('delivery_classification_reason');
            $table->boolean('needs_shipping_review')->default(false)->after('delivery_classified_at');

            $table->index(
                ['meli_account_id', 'delivery_type', 'created_at'],
                'meli_orders_account_delivery_created_index'
            );
            $table->index(
                ['needs_shipping_review', 'created_at'],
                'meli_orders_shipping_review_created_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('meli_orders', function (Blueprint $table): void {
            $table->dropIndex('meli_orders_account_delivery_created_index');
            $table->dropIndex('meli_orders_shipping_review_created_index');
            $table->dropIndex(['pack_id']);
            $table->dropColumn([
                'pack_id',
                'delivery_type',
                'delivery_classification_reason',
                'delivery_classified_at',
                'needs_shipping_review',
            ]);
        });
    }
};
