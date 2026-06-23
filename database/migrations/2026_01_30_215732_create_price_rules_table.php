<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();

            // "llanta", "par", "juego4"
            $table->string('scope')->unique();

            // Fórmula en texto. Ej: "costo * 1.5", "(costo * piezas) * 1.45"
            $table->string('formula');

            // Variables permitidas (por si mañana agregas más)
            // Lo dejo simple: validaremos por código.
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};

