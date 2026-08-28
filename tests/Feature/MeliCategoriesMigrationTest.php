<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliCategoriesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('meli_categories');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_preexisting_table_survives_up_and_down(): void
    {
        Schema::create('meli_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('category_id')->unique();
        });
        $migration = require database_path('migrations/2026_08_28_000002_create_meli_categories_table.php');

        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::hasTable('meli_categories'));
        $this->assertTrue(Schema::hasColumn('meli_categories', 'category_id'));
    }
}
