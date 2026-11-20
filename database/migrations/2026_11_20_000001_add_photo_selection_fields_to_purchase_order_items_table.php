<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_order_items')) {
            return;
        }

        $afterBase = Schema::hasColumn('purchase_order_items', 'supplier_sku') ? 'supplier_sku' : 'sku';

        Schema::table('purchase_order_items', function (Blueprint $table) use ($afterBase): void {
            if (! Schema::hasColumn('purchase_order_items', 'photo_source_mode')) {
                $table->string('photo_source_mode', 32)->nullable()->after($afterBase);
            }
            if (! Schema::hasColumn('purchase_order_items', 'approved_mlm')) {
                $table->string('approved_mlm', 40)->nullable()->after('photo_source_mode');
            }
            if (! Schema::hasColumn('purchase_order_items', 'approved_variation_id')) {
                $table->string('approved_variation_id', 64)->nullable()->after('approved_mlm');
            }
            if (! Schema::hasColumn('purchase_order_items', 'approved_meli_account_id')) {
                $table->unsignedBigInteger('approved_meli_account_id')->nullable()->after('approved_variation_id');
            }
            if (! Schema::hasColumn('purchase_order_items', 'approved_photo_url')) {
                $table->text('approved_photo_url')->nullable()->after('approved_meli_account_id');
            }
            if (! Schema::hasColumn('purchase_order_items', 'manual_photo_path')) {
                $table->string('manual_photo_path', 255)->nullable()->after('approved_photo_url');
            }
            if (! Schema::hasColumn('purchase_order_items', 'manual_photo_mime')) {
                $table->string('manual_photo_mime', 64)->nullable()->after('manual_photo_path');
            }
            if (! Schema::hasColumn('purchase_order_items', 'photo_notes')) {
                $table->string('photo_notes', 500)->nullable()->after('manual_photo_mime');
            }
            if (! Schema::hasColumn('purchase_order_items', 'rejected_mlms')) {
                $table->json('rejected_mlms')->nullable()->after('photo_notes');
            }
            if (! Schema::hasColumn('purchase_order_items', 'photo_updated_by')) {
                $table->unsignedBigInteger('photo_updated_by')->nullable()->after('rejected_mlms');
            }
            if (! Schema::hasColumn('purchase_order_items', 'photo_updated_at')) {
                $table->timestamp('photo_updated_at')->nullable()->after('photo_updated_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_order_items')) {
            return;
        }

        $columns = [
            'photo_source_mode',
            'approved_mlm',
            'approved_variation_id',
            'approved_meli_account_id',
            'approved_photo_url',
            'manual_photo_path',
            'manual_photo_mime',
            'photo_notes',
            'rejected_mlms',
            'photo_updated_by',
            'photo_updated_at',
        ];

        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('purchase_order_items', $column)
        ));

        if ($existing !== []) {
            Schema::table('purchase_order_items', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
