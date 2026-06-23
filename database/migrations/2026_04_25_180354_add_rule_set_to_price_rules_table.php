<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->string('rule_set', 20)->default('llantas')->after('id');
        });

        if (Schema::hasColumn('price_rules', 'scope')) {
            try {
                Schema::table('price_rules', function (Blueprint $table) {
                    $table->dropUnique(['scope']);
                });
            } catch (\Throwable) {
                // SQLite u otro motor puede usar otro nombre de índice
            }
        }

        DB::table('price_rules')->update(['rule_set' => 'llantas']);

        foreach (['llanta', 'par', 'juego4'] as $scope) {
            $src = DB::table('price_rules')->where('rule_set', 'llantas')->where('scope', $scope)->first();
            if ($src) {
                DB::table('price_rules')->updateOrInsert(
                    ['rule_set' => 'syscom', 'scope' => $scope],
                    [
                        'formula' => $src->formula,
                        'active' => $src->active,
                        'created_at' => $src->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        Schema::table('price_rules', function (Blueprint $table) {
            $table->unique(['rule_set', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->dropUnique(['rule_set', 'scope']);
        });

        DB::table('price_rules')->where('rule_set', 'syscom')->delete();

        Schema::table('price_rules', function (Blueprint $table) {
            $table->dropColumn('rule_set');
        });

        Schema::table('price_rules', function (Blueprint $table) {
            $table->unique('scope');
        });
    }
};
