<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('automotive_part_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('file_hash')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('missing_compatibility_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('automotive_parts', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->unique();
            $table->string('item_number')->nullable();
            $table->string('manufacturer_part_number')->nullable();
            $table->string('vendor')->nullable();
            $table->string('vendor_normalized')->nullable();
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->text('description_original')->nullable();
            $table->text('description_normalized')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('original_currency')->default('USD');
            $table->decimal('retail_price_original', 18, 4)->nullable();
            $table->integer('min_model_year')->nullable();
            $table->integer('average_model_year')->nullable();
            $table->integer('max_model_year')->nullable();
            $table->string('prevalent_model')->nullable();
            $table->text('applicable_models_text')->nullable();
            $table->decimal('length_inches', 12, 4)->nullable();
            $table->decimal('width_inches', 12, 4)->nullable();
            $table->decimal('height_inches', 12, 4)->nullable();
            $table->decimal('cubic_inches', 12, 4)->nullable();
            $table->decimal('weight_pounds', 12, 4)->nullable();
            $table->decimal('length_cm', 12, 4)->nullable();
            $table->decimal('width_cm', 12, 4)->nullable();
            $table->decimal('height_cm', 12, 4)->nullable();
            $table->decimal('weight_kg', 12, 4)->nullable();
            $table->string('lifecycle')->nullable();
            $table->string('data_status')->default('imported');
            $table->json('missing_fields')->nullable();
            $table->foreignId('last_import_id')->nullable()->constrained('automotive_part_imports')->nullOnDelete();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();
            $table->index(['category', 'subcategory']);
            $table->index(['vendor_normalized', 'item_number']);
            $table->index(['data_status', 'quantity']);
        });

        Schema::create('automotive_part_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_import_id')->constrained('automotive_part_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_key')->nullable()->index();
            $table->string('category_raw')->nullable();
            $table->string('subcategory_raw')->nullable();
            $table->string('item_number_raw')->nullable();
            $table->string('manufacturer_part_number_raw')->nullable();
            $table->string('vendor_raw')->nullable();
            $table->text('description_raw')->nullable();
            $table->string('quantity_raw')->nullable();
            $table->string('retail_raw')->nullable();
            $table->string('extended_retail_raw')->nullable();
            $table->string('lifecycle_raw')->nullable();
            $table->string('min_model_year_raw')->nullable();
            $table->string('average_model_year_raw')->nullable();
            $table->string('max_model_year_raw')->nullable();
            $table->string('prevalent_model_raw')->nullable();
            $table->text('applicable_models_raw')->nullable();
            $table->string('length_raw')->nullable();
            $table->string('width_raw')->nullable();
            $table->string('height_raw')->nullable();
            $table->string('cubic_inches_raw')->nullable();
            $table->string('weight_raw')->nullable();
            $table->string('extended_weight_raw')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('validation_errors')->nullable();
            $table->foreignId('duplicate_of_row_id')->nullable()->constrained('automotive_part_import_rows')->nullOnDelete();
            $table->foreignId('automotive_part_id')->nullable()->constrained('automotive_parts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('automotive_part_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automotive_part_id')->constrained('automotive_parts')->cascadeOnDelete();
            $table->foreignId('automotive_part_import_id')->nullable()->constrained('automotive_part_imports')->nullOnDelete();
            $table->integer('previous_quantity')->default(0);
            $table->integer('new_quantity')->default(0);
            $table->integer('difference')->default(0);
            $table->string('reason')->default('import');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['automotive_part_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automotive_part_stock_movements');
        Schema::dropIfExists('automotive_part_import_rows');
        Schema::dropIfExists('automotive_parts');
        Schema::dropIfExists('automotive_part_imports');
    }
};
