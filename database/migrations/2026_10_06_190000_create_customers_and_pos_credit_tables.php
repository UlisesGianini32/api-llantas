<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('business_name')->nullable()->comment('Nombre del salón, estética o negocio');
                $table->string('phone', 50)->nullable()->comment('Teléfono o WhatsApp');
                $table->string('email')->nullable();
                $table->text('address')->nullable()->comment('Dirección física del salón o entrega');
                $table->decimal('credit_limit', 12, 2)->default(0)->comment('Límite de crédito autorizado');
                $table->integer('credit_days_default')->default(15)->comment('Días de crédito predeterminados: 7, 15, 30');
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('name');
                $table->index('phone');
                $table->index('is_active');
            });
        }

        if (Schema::hasTable('pos_sales')) {
            Schema::table('pos_sales', function (Blueprint $table): void {
                if (! Schema::hasColumn('pos_sales', 'customer_id')) {
                    $table->foreignId('customer_id')
                        ->nullable()
                        ->after('pos_shift_id')
                        ->constrained('customers')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('pos_sales', 'payment_status')) {
                    $table->string('payment_status', 30)
                        ->default('paid')
                        ->after('payment_method')
                        ->comment('paid, credit_pending, credit_partial, cancelled');
                }

                if (! Schema::hasColumn('pos_sales', 'credit_days')) {
                    $table->integer('credit_days')
                        ->nullable()
                        ->after('payment_status')
                        ->comment('Plazo otorgado en días: 7, 15, 30');
                }

                if (! Schema::hasColumn('pos_sales', 'credit_due_date')) {
                    $table->date('credit_due_date')
                        ->nullable()
                        ->after('credit_days')
                        ->comment('Fecha límite de pago');
                }

                if (! Schema::hasColumn('pos_sales', 'balance_due')) {
                    $table->decimal('balance_due', 12, 2)
                        ->default(0)
                        ->after('credit_due_date')
                        ->comment('Saldo pendiente por liquidar');
                }

                if (! Schema::hasColumn('pos_sales', 'amount_paid')) {
                    $table->decimal('amount_paid', 12, 2)
                        ->default(0)
                        ->after('balance_due')
                        ->comment('Monto total abonado / pagado');
                }

                $table->index(['payment_status', 'credit_due_date']);
            });
        }

        if (! Schema::hasTable('customer_payments')) {
            Schema::create('customer_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('pos_sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->comment('Cajero o usuario que recibió el abono');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 30)->default('cash'); // cash, card, transfer
                $table->dateTime('payment_date');
                $table->string('receipt_number', 50)->nullable()->comment('Folio de recibo o comprobante de abono');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'payment_date']);
                $table->index('pos_sale_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');

        if (Schema::hasTable('pos_sales')) {
            Schema::table('pos_sales', function (Blueprint $table): void {
                if (Schema::hasColumn('pos_sales', 'customer_id')) {
                    $table->dropForeign(['customer_id']);
                    $table->dropColumn('customer_id');
                }
                if (Schema::hasColumn('pos_sales', 'payment_status')) {
                    $table->dropColumn([
                        'payment_status',
                        'credit_days',
                        'credit_due_date',
                        'balance_due',
                        'amount_paid',
                    ]);
                }
            });
        }

        Schema::dropIfExists('customers');
    }
};
