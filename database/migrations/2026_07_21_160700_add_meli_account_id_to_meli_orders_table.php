<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            $table->foreignId('meli_account_id')
                ->nullable()
                ->after('id')
                ->constrained('meli_accounts')
                ->nullOnDelete();

            $table->index(
                ['meli_account_id', 'status'],
                'meli_orders_account_status_index'
            );
        });

        /*
         * Las órdenes antiguas se asignan a la cuenta principal.
         * Esto evita que desaparezcan del AMS principal.
         */
        $defaultAccounts = DB::table('meli_accounts')
            ->where('is_default', true)
            ->pluck('id', 'user_id');

        foreach ($defaultAccounts as $userId => $accountId) {
            DB::table('meli_orders')
                ->whereNull('meli_account_id')
                ->update([
                    'meli_account_id' => $accountId,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('meli_orders', function (Blueprint $table) {
            $table->dropIndex('meli_orders_account_status_index');
            $table->dropConstrainedForeignId('meli_account_id');
        });
    }
};