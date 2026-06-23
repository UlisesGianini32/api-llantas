<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shipping_id')->nullable()->after('status');
            $table->string('shipping_status', 80)->nullable()->after('shipping_id');
            $table->string('shipping_substatus', 120)->nullable()->after('shipping_status');
            $table->string('shipping_mode', 80)->nullable()->after('shipping_substatus');
            $table->string('shipping_type', 80)->nullable()->after('shipping_mode');
            $table->date('shipping_process_date')->nullable()->after('shipping_type');
            $table->longText('shipping_raw')->nullable()->after('shipping_process_date');

            $table->index('shipping_id');
            $table->index('shipping_process_date');
        });
    }

    public function down(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            $table->dropIndex(['shipping_id']);
            $table->dropIndex(['shipping_process_date']);

            $table->dropColumn([
                'shipping_id',
                'shipping_status',
                'shipping_substatus',
                'shipping_mode',
                'shipping_type',
                'shipping_process_date',
                'shipping_raw',
            ]);
        });
    }
};