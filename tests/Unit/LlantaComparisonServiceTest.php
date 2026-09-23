<?php

namespace Tests\Unit;

use App\Models\Llanta;
use App\Services\Llantas\LlantaComparisonService;
use App\Services\Llantas\LlantaDescriptionParser;
use App\Services\Llantas\LlantaDuplicateDetectorService;
use Tests\TestCase;

class LlantaComparisonServiceTest extends TestCase
{
    private LlantaComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $parser = new LlantaDescriptionParser;
        $this->service = new LlantaComparisonService(
            $parser,
            new LlantaDuplicateDetectorService($parser)
        );
    }

    public function test_brand_and_measure_variants_are_normalized(): void
    {
        $parser = new LlantaDescriptionParser;

        $this->assertSame('MICHELIN', $parser->normalize(' Michelin '));
        foreach (['205/55 R16', '205 55R16', '205/55R16'] as $description) {
            $parsed = $this->service->parse($this->tire('SKU-'.$description, 'MICHELIN', '205/55R16', 'MICHELIN '.$description));
            $this->assertSame('205/55R16', $parsed['medida']);
        }
    }

    public function test_same_model_with_spacing_variants_gets_high_score(): void
    {
        $result = $this->service->compare(
            $this->tire('MIC-1', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4 205/55R16 91V'),
            $this->tire('MIC-2', 'MICHELIN', '205/55R16', 'Michelin Primacy4 205 55 R16 91 V')
        );

        $this->assertFalse($result['vetoed']);
        $this->assertGreaterThanOrEqual(86, $result['score']);
        $this->assertContains('Modelo normalizado igual', $result['reasons']);
    }

    public function test_different_models_same_brand_and_measure_are_not_high_certainty(): void
    {
        $result = $this->service->compare(
            $this->tire('MIC-1', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4 205/55R16'),
            $this->tire('MIC-2', 'MICHELIN', '205/55R16', 'MICHELIN ENERGY XM2 205/55R16')
        );

        $this->assertFalse($result['vetoed']);
        $this->assertLessThan(86, $result['score']);
        $this->assertNotEmpty($result['differences']);
    }

    public function test_different_brands_are_vetoed_and_technical_mismatch_is_explained(): void
    {
        $brandResult = $this->service->compare(
            $this->tire('MIC-1', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4 205/55R16'),
            $this->tire('BRI-1', 'BRIDGESTONE', '205/55R16', 'BRIDGESTONE TURANZA 205/55R16')
        );
        $technicalResult = $this->service->compare(
            $this->tire('MIC-1', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4 205/55R16 91V'),
            $this->tire('MIC-2', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4 205/55R16 95W')
        );

        $this->assertTrue($brandResult['vetoed']);
        $this->assertSame(0.0, $brandResult['score']);
        $this->assertTrue($technicalResult['vetoed']);
        $this->assertNotEmpty($technicalResult['differences']);
    }

    public function test_score_is_bounded_and_canonical_pair_is_symmetric(): void
    {
        $result = $this->service->compare(
            $this->tire('A', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4'),
            $this->tire('B', 'MICHELIN', '205/55R16', 'MICHELIN PRIMACY 4')
        );

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertSame(
            ['llanta_a_id' => 2, 'llanta_b_id' => 9],
            $this->service->canonicalPair(9, 2)
        );
        $this->assertSame(
            $this->service->canonicalPair(9, 2),
            $this->service->canonicalPair(2, 9)
        );
    }

    public function test_legacy_detector_scoring_ignores_comparison_only_model_signature(): void
    {
        $parser = new LlantaDescriptionParser;
        $detector = new LlantaDuplicateDetectorService($parser);
        $left = $parser->parse('MICHELIN PRIMACY 4 205/55R16');
        $right = $parser->parse('MICHELIN PRIMACY4 205 55 R16');

        $legacy = $detector->compareParsed($left, $right);
        $legacyWithExtraKeys = $detector->compareParsed(
            [...$left, 'model_signature' => 'PRIMACY4'],
            [...$right, 'model_signature' => 'PRIMACY4']
        );

        $this->assertSame($legacy['score'], $legacyWithExtraKeys['score']);
        $this->assertSame($legacy['reasons'], $legacyWithExtraKeys['reasons']);
        $this->assertSame($legacy['differences'], $legacyWithExtraKeys['differences']);
    }

    private function tire(string $sku, string $brand, string $size, string $description): Llanta
    {
        return new Llanta([
            'sku' => $sku,
            'marca' => $brand,
            'medida' => $size,
            'descripcion' => $description,
            'costo' => 100,
            'precio_ML' => 150,
            'stock' => 5,
        ]);
    }
}
