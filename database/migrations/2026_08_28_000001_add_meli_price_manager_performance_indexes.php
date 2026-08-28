<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{column: string, index: string}> */
    private const INDEXES = [
        'llantas' => ['column' => 'MLM', 'index' => 'llantas_mlm_index'],
        'producto_compuestos' => ['column' => 'MLM', 'index' => 'producto_compuestos_mlm_index'],
        'meli_price_manager_items' => ['column' => 'updated_at', 'index' => 'meli_pm_items_updated_at_index'],
    ];

    public function up(): void
    {
        $schema = Schema::getConnection()->getSchemaBuilder();

        foreach (self::INDEXES as $tableName => $definition) {
            if (! Schema::hasTable($tableName)
                || ! Schema::hasColumn($tableName, $definition['column'])
                || $schema->hasIndex($tableName, $definition['index'])) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($definition): void {
                $table->index($definition['column'], $definition['index']);
            });
        }
    }

    public function down(): void
    {
        // These indexes may predate this migration (they were created manually in production).
        // Without persistent ownership metadata, down() cannot safely distinguish those indexes
        // from indexes created by up(), so rollback is intentionally non-destructive.
    }
};
