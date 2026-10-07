<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 150);
                $table->string('rfc', 30)->nullable();
                $table->string('contact_name', 150)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('phone', 50)->nullable();
                $table->text('address')->nullable();
                $table->unsignedInteger('lead_time_days')->default(7);
                $table->unsignedInteger('credit_days')->default(0); // 0 = Contado, 7, 15, 30, 45, 60...
                $table->decimal('credit_limit', 12, 2)->default(0);
                $table->string('payment_method_preferred', 60)->nullable();
                $table->string('website', 255)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('name');
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('supplier_brands')) {
            Schema::create('supplier_brands', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('brand', 100);
                $table->boolean('is_primary')->default(true);
                $table->unsignedInteger('lead_time_override')->nullable();
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->unique(['supplier_id', 'brand']);
                $table->index('brand');
                $table->index('is_primary');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_brands');
        Schema::dropIfExists('suppliers');
    }
};
