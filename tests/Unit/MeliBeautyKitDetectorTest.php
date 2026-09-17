<?php

namespace Tests\Unit;

use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliBeautyKitDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeliBeautyKitDetectorTest extends TestCase
{
    #[DataProvider('items')]
    public function test_detects_kits_with_structured_data_before_the_normalized_fallback(array $attributes, string $title, bool $expected): void
    {
        $item = new MeliPriceManagerItem(['raw_attributes' => $attributes, 'title' => $title]);

        $this->assertSame($expected, app(MeliBeautyKitDetector::class)->isKit($item));
    }

    public static function items(): array
    {
        return [
            'structured quantity' => [[['id' => 'UNITS_PER_PACK', 'value_name' => '3']], 'Shampoo', true],
            'structured false wins over title fallback' => [[['id' => 'IS_KIT', 'value_name' => 'No']], 'Kit de shampoo', false],
            'title fallback' => [[], 'Dúo de tratamiento capilar', true],
            'single item' => [[], 'Tratamiento capilar individual', false],
        ];
    }
}
