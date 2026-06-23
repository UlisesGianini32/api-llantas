<?php

namespace Tests\Unit;

use App\Models\SyscomProduct;
use App\Support\SyscomProductPriceHydrator;
use PHPUnit\Framework\TestCase;

class SyscomProductPriceHydratorTest extends TestCase
{
    public function test_merge_preserves_database_prices_when_list_empty(): void
    {
        $existing = new SyscomProduct([
            'precio_lista' => 100,
            'precio_descuento' => 80,
            'raw_list' => [],
            'raw_detail' => null,
        ]);

        $merged = SyscomProductPriceHydrator::mergeWithExistingProduct(
            ['precio_lista' => null, 'precio_especial' => null, 'precio_descuento' => null],
            $existing
        );

        $this->assertSame(100.0, (float) $merged['precio_lista']);
        $this->assertSame(80.0, (float) $merged['precio_descuento']);
    }

    public function test_merge_list_keeps_old_precios_block(): void
    {
        $item = ['producto_id' => 1, 'titulo' => 'Nuevo'];
        $oldList = ['producto_id' => 1, 'precios' => ['precio_descuento' => 50]];

        $merged = SyscomProductPriceHydrator::mergeListPayload($item, $oldList);

        $this->assertSame(50, $merged['precios']['precio_descuento']);
        $this->assertSame('Nuevo', $merged['titulo']);
    }
}
