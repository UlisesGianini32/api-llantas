<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations');
            $table->foreignId('cashier_id')->constrained('users');
            $table->string('status', 20)->default('OPEN'); // OPEN, CLOSED
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('closing_cash_expected', 12, 2)->nullable();
            $table->decimal('closing_cash_counted', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->decimal('total_sales_cash', 12, 2)->default(0);
            $table->decimal('total_sales_card', 12, 2)->default(0);
            $table->decimal('total_sales_transfer', 12, 2)->default(0);
            $table->decimal('total_sales_other', 12, 2)->default(0);
            $table->decimal('total_sales_amount', 12, 2)->default(0);
            $table->unsignedInteger('total_sales_count')->default(0);
            $table->decimal('total_cash_in', 12, 2)->default(0);
            $table->decimal('total_cash_out', 12, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['inventory_location_id', 'status']);
            $table->index(['cashier_id', 'status']);
        });

        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('type', 10); // IN, OUT
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['pos_shift_id', 'type']);
        });

        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->foreignId('pos_shift_id')->nullable()->after('inventory_location_id')->constrained('pos_shifts')->nullOnDelete();
            $table->index('pos_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropForeign(['pos_shift_id']);
            $table->dropColumn('pos_shift_id');
        });

        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_shifts');
    }
};
