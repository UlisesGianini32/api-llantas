<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled')) {
            Schema::table('inventory_channel_links', function (Blueprint $table): void {
                $table->boolean('order_reservation_enabled')->default(false)->after('stock_sync_enabled');
            });
        }

        if (! Schema::hasColumn('meli_order_items', 'variation_id')) {
            Schema::table('meli_order_items', function (Blueprint $table): void {
                $table->string('variation_id', 64)->nullable()->after('item_id');
            });
        }

        if (! Schema::hasColumn('meli_order_items', 'remote_line_key')) {
            Schema::table('meli_order_items', function (Blueprint $table): void {
                $table->string('remote_line_key', 128)->nullable()->after('variation_id');
            });
        }

        if (! Schema::hasIndex('meli_order_items', 'meli_order_items_order_fk_idx')) {
            Schema::table('meli_order_items', function (Blueprint $table): void {
                $table->index('meli_order_id', 'meli_order_items_order_fk_idx');
            });
        }

        if (Schema::hasIndex('meli_order_items', 'meli_order_items_meli_order_id_item_id_unique')) {
            Schema::table('meli_order_items', function (Blueprint $table): void {
                $table->dropUnique('meli_order_items_meli_order_id_item_id_unique');
            });
        }

        if (! Schema::hasIndex('meli_order_items', 'meli_order_line_key_unique')) {
            Schema::table('meli_order_items', function (Blueprint $table): void {
                $table->unique(['meli_order_id', 'remote_line_key'], 'meli_order_line_key_unique');
            });
        }

        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            Schema::create('inventory_channel_order_allocations', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 32);
                $table->string('account_key', 64);
                $table->string('remote_order_id', 64);
                $table->string('remote_line_key', 191);
                $table->string('identity_hash', 64)->unique();
                $table->foreignId('inventory_channel_link_id')->nullable()->constrained('inventory_channel_links')->nullOnDelete();
                $table->foreignId('inventory_product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
                $table->string('reservation_kind', 16)->nullable();
                $table->unsignedBigInteger('reservation_id')->nullable();
                $table->unsignedInteger('quantity')->default(0);
                $table->unsignedInteger('reservation_version')->default(0);
                $table->string('status', 32)->default('PENDING');
                $table->string('diagnostic_code', 64)->nullable();
                $table->json('diagnostic_metadata')->nullable();
                $table->timestamps();
                $table->index(['channel', 'account_key', 'remote_order_id'], 'inv_order_alloc_order_idx');
                $table->index(['reservation_kind', 'reservation_id'], 'inv_order_alloc_res_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meli_order_items')) {
            $duplicate = DB::table('meli_order_items')
                ->select('meli_order_id', 'item_id')
                ->groupBy('meli_order_id', 'item_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicate) {
                throw new RuntimeException(
                    'No se puede revertir la identidad de líneas: existen varias variaciones para el mismo '
                    .'meli_order_id + item_id y el esquema antiguo no puede representarlas. Preserve/resuelva '
                    .'esas líneas antes de reintentar el rollback.'
                );
            }
        }

        Schema::dropIfExists('inventory_channel_order_allocations');

        if (Schema::hasTable('meli_order_items')) {
            if (! Schema::hasIndex('meli_order_items', 'meli_order_items_meli_order_id_item_id_unique')) {
                Schema::table('meli_order_items', function (Blueprint $table): void {
                    $table->unique(['meli_order_id', 'item_id'], 'meli_order_items_meli_order_id_item_id_unique');
                });
            }

            if (Schema::hasIndex('meli_order_items', 'meli_order_line_key_unique')) {
                Schema::table('meli_order_items', function (Blueprint $table): void {
                    $table->dropUnique('meli_order_line_key_unique');
                });
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('meli_order_items', 'variation_id') ? 'variation_id' : null,
                Schema::hasColumn('meli_order_items', 'remote_line_key') ? 'remote_line_key' : null,
            ]));
            if ($columns !== []) {
                Schema::table('meli_order_items', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }

            if (Schema::hasIndex('meli_order_items', 'meli_order_items_order_fk_idx')) {
                Schema::table('meli_order_items', function (Blueprint $table): void {
                    $table->dropIndex('meli_order_items_order_fk_idx');
                });
            }
        }

        if (Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled')) {
            Schema::table('inventory_channel_links', function (Blueprint $table): void {
                $table->dropColumn('order_reservation_enabled');
            });
        }
    }
};
