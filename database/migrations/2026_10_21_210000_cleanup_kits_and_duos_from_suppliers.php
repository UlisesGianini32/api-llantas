<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Marcar formalmente como KIT en inventory_products aquellos productos que:
        //    a) Tienen componentes en inventory_kit_components
        //    b) Tienen SKU que empieza o contiene patrones de KIT, DUO o COMBO
        //    c) Tienen Nombre que empieza o contiene Kit, Duo, Dúo, Combo, Paquete, Trío
        if (Schema::hasTable('inventory_products')) {
            if (Schema::hasTable('inventory_kit_components')) {
                DB::table('inventory_products')
                    ->whereIn('id', function ($query) {
                        $query->select('kit_product_id')->from('inventory_kit_components');
                    })
                    ->where(function ($query) {
                        $query->whereNull('product_type')->orWhere('product_type', '!=', 'KIT');
                    })
                    ->update(['product_type' => 'KIT']);
            }

            DB::table('inventory_products')
                ->where(function ($query) {
                    $query->where('sku', 'like', 'KIT%')
                        ->orWhere('sku', 'like', '%-KIT%')
                        ->orWhere('sku', 'like', 'DUO%')
                        ->orWhere('sku', 'like', '%-DUO%')
                        ->orWhere('sku', 'like', '%DUO')
                        ->orWhere('sku', 'like', 'COMBO%')
                        ->orWhere('sku', 'like', '%-COMBO%');
                })
                ->where(function ($query) {
                    $query->whereNull('product_type')->orWhere('product_type', '!=', 'KIT');
                })
                ->update(['product_type' => 'KIT']);

            DB::table('inventory_products')
                ->where(function ($query) {
                    $query->where('name', 'like', 'Kit %')
                        ->orWhere('name', 'like', 'KIT %')
                        ->orWhere('name', 'like', '% Kit %')
                        ->orWhere('name', 'like', '% KIT %')
                        ->orWhere('name', 'like', 'Duo %')
                        ->orWhere('name', 'like', 'DUO %')
                        ->orWhere('name', 'like', 'Dúo %')
                        ->orWhere('name', 'like', 'DÚO %')
                        ->orWhere('name', 'like', '% Duo %')
                        ->orWhere('name', 'like', '% Dúo %')
                        ->orWhere('name', 'like', 'Paquete %')
                        ->orWhere('name', 'like', 'PAQUETE %')
                        ->orWhere('name', 'like', '% Paquete %')
                        ->orWhere('name', 'like', 'Combo %')
                        ->orWhere('name', 'like', 'COMBO %')
                        ->orWhere('name', 'like', '% Combo %')
                        ->orWhere('name', 'like', 'Trío %')
                        ->orWhere('name', 'like', 'Trio %')
                        ->orWhere('name', 'like', 'TRÍO %')
                        ->orWhere('name', 'like', 'TRIO %')
                        ->orWhere('name', 'like', '% Trío %')
                        ->orWhere('name', 'like', '% Trio %');
                })
                ->where(function ($query) {
                    $query->whereNull('product_type')->orWhere('product_type', '!=', 'KIT');
                })
                ->update(['product_type' => 'KIT']);
        }

        // 2. Eliminar cualquier registro en supplier_products que apunte a un kit o producto no comprable
        if (Schema::hasTable('supplier_products') && Schema::hasTable('inventory_products')) {
            DB::table('supplier_products')
                ->whereIn('inventory_product_id', function ($query) {
                    $query->select('id')
                        ->from('inventory_products')
                        ->where('product_type', 'KIT');
                })
                ->delete();
        }
    }

    public function down(): void
    {
        // No se revierte el borrado de relaciones no deseadas ni la clasificación de kits
    }
};
