<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $this->dedupeByMlColumn();
        $this->dedupeEmptyMlStrings();

        $schema = Schema::getConnection()->getSchemaBuilder();
        if (! $schema->hasIndex('products', 'products_ml_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('ml', 'products_ml_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        $schema = Schema::getConnection()->getSchemaBuilder();
        if ($schema->hasIndex('products', 'products_ml_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_ml_unique');
            });
        }
    }

    /**
     * Conserva un solo registro por Mercado Libre item id (ml); prioriza la fila con id mayor (datos más recientes).
     */
    private function dedupeByMlColumn(): void
    {
        $dupMls = DB::table('products')
            ->whereNotNull('ml')
            ->where('ml', '!=', '')
            ->select('ml')
            ->groupBy('ml')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ml');

        foreach ($dupMls as $ml) {
            $keepId = (int) DB::table('products')->where('ml', $ml)->max('id');
            if ($keepId <= 0) {
                continue;
            }
            DB::table('products')->where('ml', $ml)->where('id', '!=', $keepId)->delete();
        }
    }

    /**
     * Índice único en ml no permite varias filas con cadena vacía; deja una sola.
     */
    private function dedupeEmptyMlStrings(): void
    {
        $count = (int) DB::table('products')->where('ml', '')->count();
        if ($count <= 1) {
            return;
        }
        $keepId = (int) DB::table('products')->where('ml', '')->max('id');
        if ($keepId <= 0) {
            return;
        }
        DB::table('products')->where('ml', '')->where('id', '!=', $keepId)->delete();
    }
};
