<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_account_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->unique()->constrained('meli_accounts')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->decimal('vat_included_rate', 7, 4)->nullable();
            $table->decimal('vat_withholding_rate', 7, 4)->nullable();
            $table->decimal('income_tax_withholding_rate', 7, 4)->nullable();
            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_account_tax_profiles');
    }
};
