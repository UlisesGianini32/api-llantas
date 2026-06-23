<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // ❌ Migración anulada porque la columna no existía
        // ❌ Se reemplaza por migración de rescate
    }

    public function down(): void
    {
        // rollback deshabilitado
    }
};

