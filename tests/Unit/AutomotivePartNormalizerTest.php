<?php

namespace Tests\Unit;

use App\Services\Autopartes\AutomotivePartImportService;
use App\Services\Autopartes\AutomotivePartNormalizer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class AutomotivePartNormalizerTest extends TestCase
{
    public function test_source_key_is_deterministic(): void
    {
        $normalizer = new AutomotivePartNormalizer();

        $first = $normalizer->makeSourceKey('ABC-001', 'MFG-01', 'ACME Auto');
        $second = $normalizer->makeSourceKey('ABC-001', 'MFG-01', 'acme auto');

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
    }

    public function test_inches_are_converted_to_centimeters(): void
    {
        $normalizer = new AutomotivePartNormalizer();

        $this->assertSame(5.08, $normalizer->inchesToCm(2.0));
    }

    public function test_pounds_are_converted_to_kilograms(): void
    {
        $normalizer = new AutomotivePartNormalizer();

        $this->assertSame(0.4536, $normalizer->poundsToKg(1.0));
    }

    public function test_part_numbers_are_normalized(): void
    {
        $normalizer = new AutomotivePartNormalizer();

        $this->assertSame('ABC-001', $normalizer->normalizePartNumber('  abc-001  '));
    }

    public function test_import_service_normalizes_collection_rows(): void
    {
        $service = new class(new AutomotivePartNormalizer()) extends AutomotivePartImportService
        {
            public function normalizeRow(mixed $row): array
            {
                return $this->normalizeRowValues($row);
            }

            public function findStartIndex(Collection $rows): int
            {
                return $this->findDataStartIndex($rows);
            }
        };

        $header = collect(['Category', 'Subcategory', 'Item Number', 'Manufacturer Part Number', 'Vendor']);
        $data = collect(['Brakes', 'Pads', 'ABC-001', 'MFG-01', 'ACME Auto']);

        $this->assertSame(1, $service->findStartIndex(collect([$header, $data])));
        $this->assertSame($data->all(), $service->normalizeRow($data));
    }
}
