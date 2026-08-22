<?php

namespace Tests\Feature;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartImport;
use App\Models\AutomotivePartStockMovement;
use App\Services\Autopartes\AutomotivePartImportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomotivePartImportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);

        DB::purge('sqlite');
    }

    public function test_it_classifies_new_and_existing_products_without_creating_unchanged_stock_movements(): void
    {
        $migration = require database_path('migrations/2026_08_21_000001_create_automotive_part_tables.php');
        $migration->up();

        try {
            $service = app(AutomotivePartImportService::class);

            $firstImport = $this->createImport('first.xls');
            $firstStats = $service->processRows($firstImport->id, $this->rowsWithQuantity(5));

            $this->assertSame(1, $firstStats['imported_rows']);
            $this->assertSame(0, $firstStats['updated_rows']);
            $this->assertSame(1, $firstImport->fresh()->metadata['unique_rows']);
            $this->assertSame(1, AutomotivePart::query()->count());
            $this->assertSame(1, AutomotivePartStockMovement::query()->count());
            $this->assertSame('initial_import', AutomotivePartStockMovement::query()->first()->reason);

            $secondImport = $this->createImport('second.xls');
            $secondStats = $service->processRows($secondImport->id, $this->rowsWithQuantity(5));

            $this->assertSame(0, $secondStats['imported_rows']);
            $this->assertSame(1, $secondStats['updated_rows']);
            $this->assertSame(1, $secondImport->fresh()->metadata['unique_rows']);
            $this->assertSame(1, AutomotivePart::query()->count());
            $this->assertSame(1, AutomotivePartStockMovement::query()->count());

            $thirdImport = $this->createImport('third.xls');
            $thirdStats = $service->processRows($thirdImport->id, $this->rowsWithQuantity(7));

            $this->assertSame(0, $thirdStats['imported_rows']);
            $this->assertSame(1, $thirdStats['updated_rows']);
            $this->assertSame(2, AutomotivePartStockMovement::query()->count());
            $this->assertSame('import_update', AutomotivePartStockMovement::query()->latest('id')->first()->reason);
        } finally {
            $migration->down();
        }
    }

    private function createImport(string $filename): AutomotivePartImport
    {
        return AutomotivePartImport::query()->create([
            'original_filename' => $filename,
            'stored_filename' => $filename,
        ]);
    }

    private function rowsWithQuantity(int $quantity): Collection
    {
        return collect([
            collect([
                'Category',
                'Subcategory',
                'Item Number',
                'Manufacturer Part Number',
                'Vendor',
                'Description',
                'Quantity',
                'Retail',
                'Extended Retail',
                'Lifecycle',
                'Min Model Year',
                'Average Model Year',
                'Max Model Year',
                'Prevalent Model',
                'Applicable Models',
                'Length',
                'Width',
                'Height',
                'Cubic Inches',
                'Weight',
                'Extended Weight',
            ]),
            collect([
                'Brakes',
                'Pads',
                'ITEM-001',
                'MFG-001',
                'ACME Auto',
                'Brake pad',
                $quantity,
                10,
                50,
                'Active',
                2020,
                2021,
                2022,
                'Model X',
                'Model X 2020-2022',
                1,
                2,
                3,
                6,
                4,
                20,
            ]),
        ]);
    }
}
