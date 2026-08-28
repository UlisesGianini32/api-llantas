<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliPriceManagerPerformanceIndexesMigrationTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('MLM')->nullable();
            $table->index('MLM', 'llantas_mlm_index');
        });
        Schema::create('producto_compuestos', function (Blueprint $table): void {
            $table->id();
            $table->string('MLM')->nullable();
        });
        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $this->migration = require database_path('migrations/2026_08_28_000001_add_meli_price_manager_performance_indexes.php');
    }

    protected function tearDown(): void
    {
        foreach (['llantas', 'producto_compuestos', 'meli_price_manager_items'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_rollback_preserves_preexisting_index_with_the_same_name(): void
    {
        $schema = Schema::getConnection()->getSchemaBuilder();

        $this->migration->up();
        $this->migration->up();

        $this->assertTrue($schema->hasIndex('llantas', 'llantas_mlm_index'));
        $this->assertTrue($schema->hasIndex('producto_compuestos', 'producto_compuestos_mlm_index'));
        $this->assertTrue($schema->hasIndex('meli_price_manager_items', 'meli_pm_items_updated_at_index'));

        $this->migration->down();
        $this->migration->down();

        $this->assertTrue($schema->hasIndex('llantas', 'llantas_mlm_index'));
        $this->assertTrue($schema->hasIndex('producto_compuestos', 'producto_compuestos_mlm_index'));
        $this->assertTrue($schema->hasIndex('meli_price_manager_items', 'meli_pm_items_updated_at_index'));
    }
}
