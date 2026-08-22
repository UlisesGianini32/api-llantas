<?php

namespace Tests\Unit;

use App\Services\Autopartes\AutomotivePartNormalizer;
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
}
