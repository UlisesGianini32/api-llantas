<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_locations') && ! Schema::hasColumn('inventory_locations', 'amazon_aisle')) {
            Schema::table('inventory_locations', function (Blueprint $table): void {
                $table->string('amazon_aisle', 10)
                    ->nullable()
                    ->after('name')
                    ->comment('Pasillo/sección compatible con Amazon (A-I)');
            });
        }

        if (Schema::hasTable('inventory_products')) {
            Schema::table('inventory_products', function (Blueprint $table): void {
                if (! Schema::hasColumn('inventory_products', 'secondary_location_id')) {
                    $table->foreignId('secondary_location_id')
                        ->nullable()
                        ->after('primary_location_id')
                        ->constrained('inventory_locations')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('inventory_products', 'reserve_notes')) {
                    $table->string('reserve_notes', 255)
                        ->nullable()
                        ->after('secondary_location_id')
                        ->comment('Detalle de ubicación de reserva/pulmón o sobreflujo');
                }
            });
        }

        // Crear/asegurar las 3 ubicaciones de anaqueles de Amazon mapeadas (J->A, K->B, L->C)
        if (Schema::hasTable('inventory_locations')) {
            $now = now();
            $defaultLocations = [
                [
                    'code' => 'AMZ-A',
                    'name' => 'Anaquel J (Amazon Pasillo A)',
                    'amazon_aisle' => 'A',
                    'description' => 'Hilera J de anaqueles mapeada como Pasillo A para compatibilidad con Amazon',
                    'is_active' => true,
                    'sort_order' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'AMZ-B',
                    'name' => 'Anaquel K (Amazon Pasillo B)',
                    'amazon_aisle' => 'B',
                    'description' => 'Hilera K de anaqueles mapeada como Pasillo B para compatibilidad con Amazon',
                    'is_active' => true,
                    'sort_order' => 11,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'AMZ-C',
                    'name' => 'Anaquel L (Amazon Pasillo C)',
                    'amazon_aisle' => 'C',
                    'description' => 'Hilera L de anaqueles mapeada como Pasillo C para compatibilidad con Amazon',
                    'is_active' => true,
                    'sort_order' => 12,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            foreach ($defaultLocations as $loc) {
                DB::table('inventory_locations')->updateOrInsert(
                    ['code' => $loc['code']],
                    $loc
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_products')) {
            Schema::table('inventory_products', function (Blueprint $table): void {
                if (Schema::hasColumn('inventory_products', 'secondary_location_id')) {
                    $table->dropForeign(['secondary_location_id']);
                    $table->dropColumn('secondary_location_id');
                }
                if (Schema::hasColumn('inventory_products', 'reserve_notes')) {
                    $table->dropColumn('reserve_notes');
                }
            });
        }

        if (Schema::hasTable('inventory_locations') && Schema::hasColumn('inventory_locations', 'amazon_aisle')) {
            Schema::table('inventory_locations', function (Blueprint $table): void {
                $table->dropColumn('amazon_aisle');
            });
        }
    }
};
