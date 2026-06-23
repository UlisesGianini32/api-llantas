<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syscom_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('syscom_producto_id')->unique();
            $table->string('modelo')->nullable();
            $table->string('titulo')->nullable();
            $table->string('marca')->nullable();
            $table->string('sat_key')->nullable();
            $table->string('img_portada', 1024)->nullable();
            $table->decimal('precio_lista', 12, 2)->nullable();
            $table->decimal('precio_especial', 12, 2)->nullable();
            $table->decimal('precio_descuento', 12, 2)->nullable();
            $table->integer('total_existencia')->default(0);
            $table->json('existencia')->nullable();
            $table->json('imagenes')->nullable();
            $table->longText('descripcion')->nullable();
            $table->json('categorias')->nullable();
            $table->json('raw_list')->nullable();
            $table->json('raw_detail')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syscom_products');
    }
};
