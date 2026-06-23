<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_chat_flows', function (Blueprint $table) {
            if (!Schema::hasColumn('meli_chat_flows', 'pack_id')) {
                $table->string('pack_id')->nullable()->after('order_id')->index();
            }
            if (!Schema::hasColumn('meli_chat_flows', 'last_inbound_message_id')) {
                $table->string('last_inbound_message_id')->nullable()->after('message_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('meli_chat_flows', function (Blueprint $table) {
            if (Schema::hasColumn('meli_chat_flows', 'last_inbound_message_id')) {
                $table->dropColumn('last_inbound_message_id');
            }
            if (Schema::hasColumn('meli_chat_flows', 'pack_id')) {
                $table->dropColumn('pack_id');
            }
        });
    }
};
