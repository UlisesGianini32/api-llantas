<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_full_shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shipment_code', 50)->unique(); // Ej: FULL-ENV-2026-0001
            $table->string('status', 30)->default('DRAFT'); // DRAFT, PACKED, IN_TRANSIT, DELIVERED, IN_CHECKIN, RECEIVED, DISCREPANCY, CANCELLED
            
            // Bodega MeLi Destino
            $table->string('meli_warehouse_code', 20)->default('MXCD01'); // MXCD01, MXRC01, MXJA01, MXNL01, MXTE01, OTRO
            $table->string('meli_warehouse_name', 150)->nullable(); // Ej: CEDIS Cuautitlán Izcalli
            $table->string('meli_shipment_id', 50)->nullable(); // ID / Cita en MeLi
            
            // Guía y paquetería de ENVIA
            $table->string('envia_carrier', 50)->nullable(); // Paquetexpress, FedEx, DHL, Estafeta, etc.
            $table->string('envia_tracking_number', 100)->nullable()->index(); // Número de guía ENVIA
            $table->string('envia_tracking_url', 500)->nullable(); // Link de rastreo
            $table->decimal('envia_cost', 10, 2)->default(0); // Costo del envío
            
            // Métricas y totales
            $table->unsignedInteger('total_boxes')->default(0);
            $table->unsignedInteger('total_units')->default(0);
            $table->unsignedInteger('total_units_received')->default(0);
            $table->unsignedInteger('total_units_damaged')->default(0);
            $table->unsignedInteger('total_units_missing')->default(0);
            
            // Fechas del ciclo de vida
            $table->timestamp('shipped_at')->nullable(); // Salida física de almacén
            $table->timestamp('delivered_at')->nullable(); // Entrega en CEDIS MeLi
            $table->timestamp('received_at')->nullable(); // Procesado / ingresado a stock MeLi
            
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_full_shipment_boxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_full_shipment_id')->constrained('meli_full_shipments')->cascadeOnDelete();
            $table->unsignedInteger('box_number')->default(1); // Caja 1, Caja 2...
            $table->string('box_code', 50)->nullable(); // Código / Rótulo de la caja ej. BOX-001
            $table->unsignedInteger('capacity')->default(30); // Capacidad estándar: 30 unidades
            $table->unsignedInteger('units_count')->default(0); // Unidades empacadas en esta caja
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->string('dimensions', 50)->default('40x30x30'); // Medidas en cm
            $table->string('status', 20)->default('PACKING'); // PACKING, PACKED, SEALED
            $table->timestamps();
        });

        Schema::create('meli_full_shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_full_shipment_id')->constrained('meli_full_shipments')->cascadeOnDelete();
            $table->foreignId('meli_full_shipment_box_id')->nullable()->constrained('meli_full_shipment_boxes')->cascadeOnDelete();
            $table->foreignId('inventory_product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
            
            $table->string('sku', 100)->index();
            $table->string('product_name', 255);
            $table->string('mlm', 50)->nullable()->index();
            $table->string('variation_id', 50)->nullable();
            
            $table->unsignedInteger('quantity_sent')->default(0); // Cantidad enviada en esta caja
            $table->unsignedInteger('quantity_received')->default(0); // Confirmadas por MeLi en buen estado
            $table->unsignedInteger('quantity_damaged')->default(0); // Dañadas / mermadas en recepción
            $table->unsignedInteger('quantity_missing')->default(0); // Faltantes
            
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_full_shipment_items');
        Schema::dropIfExists('meli_full_shipment_boxes');
        Schema::dropIfExists('meli_full_shipments');
    }
};
