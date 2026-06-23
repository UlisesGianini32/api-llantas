<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('producto_compuestos', function (Blueprint $table) {

            if (!Schema::hasColumn('producto_compuestos', 'precio_ML')) {
                $table->decimal('precio_ML', 10, 2)->default(0);
            }

            if (!Schema::hasColumn('producto_compuestos', 'costo')) {
                $table->decimal('costo', 10, 2)->default(0);
            }

            if (!Schema::hasColumn('producto_compuestos', 'sku')) {
                $table->string('sku')->unique();
            }

            if (!Schema::hasColumn('producto_compuestos', 'descripcion')) {
                $table->text('descripcion')->nullable();
            }

            if (!Schema::hasColumn('producto_compuestos', 'title_familyname')) {
                $table->string('title_familyname')->nullable();
            }

            if (!Schema::hasColumn('producto_compuestos', 'MLM')) {
                $table->string('MLM')->nullable();
            }
        });
    }

    public function down()
    {
        // NO rollback (es rescate)
    }
};
