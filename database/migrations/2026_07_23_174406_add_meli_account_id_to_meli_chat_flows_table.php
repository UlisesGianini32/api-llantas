<?php

use App\Models\MeliAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_chat_flows', function (Blueprint $table) {
            if (! Schema::hasColumn('meli_chat_flows', 'meli_account_id')) {
                $table->unsignedBigInteger('meli_account_id')
                    ->nullable()
                    ->after('user_id')
                    ->index();
            }
        });

        /*
         * Las conversaciones existentes pertenecían a la cuenta principal,
         * porque antes el sistema solo utilizaba users.meli_id/access_token.
         */
        MeliAccount::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->get()
            ->each(function (MeliAccount $account): void {
                DB::table('meli_chat_flows')
                    ->where('user_id', $account->user_id)
                    ->whereNull('meli_account_id')
                    ->update([
                        'meli_account_id' => $account->id,
                    ]);
            });

        /*
         * Si un usuario no tiene una cuenta marcada como principal,
         * asignamos su primera cuenta vinculada.
         */
        MeliAccount::query()
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->each(function ($accounts, $userId): void {
                $account = $accounts->first();

                DB::table('meli_chat_flows')
                    ->where('user_id', $userId)
                    ->whereNull('meli_account_id')
                    ->update([
                        'meli_account_id' => $account->id,
                    ]);
            });

        Schema::table('meli_chat_flows', function (Blueprint $table) {
            try {
                $table->dropUnique('meli_chat_flows_order_buyer_unique');
            } catch (\Throwable) {
                // El índice puede no existir en instalaciones antiguas.
            }
        });

        Schema::table('meli_chat_flows', function (Blueprint $table) {
            $table->unique(
                ['meli_account_id', 'order_id', 'buyer_id'],
                'meli_chat_flows_account_order_buyer_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('meli_chat_flows', function (Blueprint $table) {
            try {
                $table->dropUnique('meli_chat_flows_account_order_buyer_unique');
            } catch (\Throwable) {
                // No interrumpir el rollback si el índice no existe.
            }
        });

        Schema::table('meli_chat_flows', function (Blueprint $table) {
            $table->unique(
                ['order_id', 'buyer_id'],
                'meli_chat_flows_order_buyer_unique'
            );
        });

        Schema::table('meli_chat_flows', function (Blueprint $table) {
            if (Schema::hasColumn('meli_chat_flows', 'meli_account_id')) {
                $table->dropColumn('meli_account_id');
            }
        });
    }
};
