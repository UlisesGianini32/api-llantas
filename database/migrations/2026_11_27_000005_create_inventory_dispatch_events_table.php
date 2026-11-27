<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_dispatch_events')) {
            $requiredColumns = [
                'id', 'inventory_dispatch_id', 'inventory_dispatch_item_id',
                'provisional_item_id', 'event_type', 'from_status', 'to_status',
                'actor_user_id', 'reason', 'payload', 'occurred_at', 'created_at',
            ];
            $missing = array_values(array_filter(
                $requiredColumns,
                static fn (string $col): bool => ! Schema::hasColumn('inventory_dispatch_events', $col),
            ));
            if ($missing !== []) {
                throw new RuntimeException(
                    'La tabla preexistente inventory_dispatch_events tiene un esquema parcial o incompatible; columnas faltantes: '.implode(', ', $missing)
                );
            }

            return;
        }

        Schema::create('inventory_dispatch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_dispatch_id')
                ->nullable()
                ->constrained('inventory_dispatches')
                ->restrictOnDelete();
            $table->foreignId('inventory_dispatch_item_id')
                ->nullable()
                ->constrained('inventory_dispatch_items')
                ->restrictOnDelete();
            $table->foreignId('provisional_item_id')
                ->nullable()
                ->constrained('inventory_provisional_items')
                ->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('from_status', 48)->nullable();
            $table->string('to_status', 48)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['inventory_dispatch_id', 'occurred_at'], 'inv_disp_evt_disp_idx');
            $table->index(['provisional_item_id', 'occurred_at'], 'inv_disp_evt_prov_idx');
            $table->index(['event_type', 'occurred_at'], 'inv_disp_evt_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dispatch_events');
    }
};
