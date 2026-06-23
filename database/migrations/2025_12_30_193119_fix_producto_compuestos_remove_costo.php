<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('producto_compuestos', function (Blueprint $table) {
            if (Schema::hasColumn('producto_compuestos', 'costo')) {
                $table->dropColumn('costo');
            }

            if (Schema::hasColumn('producto_compuestos', 'precio_ML')) {
                $table->dropColumn('precio_ML');
            }
        });
    }

    public function down()
    {
        Schema::table('producto_compuestos', function (Blueprint $table) {
            $table->decimal('costo', 10, 2)->default(0);
            $table->decimal('precio_ML', 10, 2)->default(0);
        });
    }
};
