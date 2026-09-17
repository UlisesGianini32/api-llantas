<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_label_prints', function (Blueprint $table): void {
            $table->id();
            $table->string('shipment_id', 80)->nullable();
            $table->string('type', 20)->index();
            $table->string('original_filename');
            $table->char('file_hash', 64);
            $table->unsignedSmallInteger('labels_count');
            $table->string('printer_name')->nullable();
            $table->string('status', 20)->index();
            $table->text('error_message')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['file_hash', 'status'], 'meli_label_prints_hash_status_index');
            $table->index(['shipment_id', 'type'], 'meli_label_prints_shipment_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_label_prints');
    }
};
