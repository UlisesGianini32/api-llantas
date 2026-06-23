<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('producto_compuestos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('llanta_id')
                ->constrained('llantas')
                ->cascadeOnDelete();

            $table->string('sku')->unique();       // SKU-2 / SKU-4
            $table->enum('tipo', ['par', 'juego4']);
            $table->unsignedInteger('stock');      // consumo 2 o 4

            $table->text('descripcion')->nullable();
            $table->string('title_familyname')->nullable();

            $table->decimal('costo', 10, 2)->default(0);
            $table->decimal('precio_ML', 10, 2)->default(0);

            $table->string('MLM')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_compuestos');
    }
};
