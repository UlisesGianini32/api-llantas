<?php

namespace Tests\Unit;

use App\Services\MercadoLibre\MeliVariationStockPayloadBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MeliVariationStockPayloadBuilderTest extends TestCase
{
    private const TARGET_ID = 174167198388;

    public function test_preserves_all_94_ids_and_only_sets_stock_on_target(): void
    {
        $builder = new MeliVariationStockPayloadBuilder;

        $payload = $builder->build(
            $this->remoteVariations(),
            (string) self::TARGET_ID,
            5,
        );

        $this->assertSame(['variations'], array_keys($payload));
        $this->assertCount(94, $payload['variations']);

        $expectedIds = array_map(
            fn (int $i): int => 174167198300 + $i,
            range(0, 93),
        );

        $actualIds = array_map(
            fn (array $row): int => (int) $row['id'],
            $payload['variations'],
        );

        $this->assertSame($expectedIds, $actualIds);
        $this->assertCount(94, array_unique($actualIds));

        $withQuantity = array_values(array_filter(
            $payload['variations'],
            fn (array $row): bool => array_key_exists('available_quantity', $row),
        ));

        $this->assertCount(1, $withQuantity);
        $this->assertSame(self::TARGET_ID, (int) $withQuantity[0]['id']);
        $this->assertSame(5, $withQuantity[0]['available_quantity']);

        foreach ($payload['variations'] as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayNotHasKey('pictures', $row);
            $this->assertArrayNotHasKey('picture_ids', $row);
            $this->assertArrayNotHasKey('attributes', $row);
            $this->assertArrayNotHasKey('attribute_combinations', $row);

            $keys = array_keys($row);
            sort($keys);

            if ((int) $row['id'] === self::TARGET_ID) {
                $this->assertSame(['available_quantity', 'id'], $keys);
            } else {
                $this->assertSame(['id'], $keys);
            }
        }
    }

    public function test_rejects_missing_target_variation(): void
    {
        $builder = new MeliVariationStockPayloadBuilder;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'La variante seleccionada ya no existe actualmente en Mercado Libre.'
        );

        $builder->build(
            $this->remoteVariations(),
            '999999999999',
            5,
        );
    }

    public function test_rejects_empty_remote_variations(): void
    {
        $builder = new MeliVariationStockPayloadBuilder;

        $this->expectException(RuntimeException::class);

        $builder->build([], (string) self::TARGET_ID, 5);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function remoteVariations(): array
    {
        $rows = [];

        for ($i = 0; $i < 94; $i++) {
            $id = 174167198300 + $i;

            $rows[] = [
                'id' => $id,
                'available_quantity' => $id === self::TARGET_ID ? 0 : $i % 17,
                'picture_ids' => [
                    'PIC-'.$id.'-A',
                    'PIC-'.$id.'-B',
                ],
                'attribute_combinations' => [
                    [
                        'id' => 'COLOR',
                        'value_name' => 'Tono '.$i,
                    ],
                ],
            ];
        }

        return $rows;
    }
}
