<?php

namespace Tests\Unit;

use App\Support\SyscomHermosilloStock;
use PHPUnit\Framework\TestCase;

class SyscomHermosilloStockTest extends TestCase
{
    public function test_existencia_keyed_by_branch_name(): void
    {
        $existencia = [
            'hermosillo' => 5,
            'mexico' => 0,
            'tijuana' => 12,
        ];

        $this->assertSame(5, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
        $this->assertSame(12, SyscomHermosilloStock::forBranch($existencia, '', 'tijuana'));
        $this->assertSame(0, SyscomHermosilloStock::forBranch($existencia, '', 'merida'));
    }

    public function test_existencia_with_states_inside_each_branch(): void
    {
        $existencia = [
            'hermosillo' => ['nuevo' => 3, 'asterisco' => 2],
            'mexico' => ['nuevo' => 0, 'asterisco' => 0],
        ];

        $this->assertSame(5, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
    }

    public function test_states_first_then_branches(): void
    {
        $existencia = [
            'nuevo' => ['hermosillo' => 4, 'tijuana' => 1],
            'asterisco' => ['hermosillo' => 1, 'tijuana' => 0],
        ];

        $this->assertSame(5, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
        $this->assertSame(1, SyscomHermosilloStock::forBranch($existencia, '', 'tijuana'));
    }

    public function test_branch_keyed_by_numeric_code(): void
    {
        $existencia = [
            '1' => 0,
            '8' => 5,
            '9' => 1,
        ];

        $this->assertSame(5, SyscomHermosilloStock::forBranch($existencia, '8', ''));
    }

    public function test_branch_keyed_by_numeric_code_with_states(): void
    {
        $existencia = [
            '1' => ['nuevo' => 1, 'asterisco' => 0],
            '8' => ['nuevo' => 4, 'asterisco' => 1],
        ];

        $this->assertSame(5, SyscomHermosilloStock::forBranch($existencia, '8', ''));
    }

    public function test_wrapped_in_sucursales_key(): void
    {
        $existencia = [
            'sucursales' => [
                'hermosillo' => 7,
            ],
            'total' => 7,
        ];

        $this->assertSame(7, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
    }

    public function test_list_of_rows(): void
    {
        $existencia = [
            ['codigo' => '1', 'nombre_sucursal' => 'CDMX', 'existencia' => 0],
            ['codigo' => '8', 'nombre_sucursal' => 'Hermosillo', 'existencia' => 5, 'caja_abierta' => 1],
        ];

        $this->assertSame(6, SyscomHermosilloStock::forBranch($existencia, '8', 'hermosillo'));
        $this->assertSame(6, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
    }

    public function test_empty_existencia_returns_zero(): void
    {
        $this->assertSame(0, SyscomHermosilloStock::forBranch([], '', 'hermosillo'));
        $this->assertSame(0, SyscomHermosilloStock::forBranch(null, '', 'hermosillo'));
    }

    public function test_partial_match_by_name(): void
    {
        $existencia = [
            'hermosillo centro' => 4,
        ];
        $this->assertSame(4, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
    }

    public function test_does_not_double_count_when_branch_appears_inside_state_and_outside(): void
    {
        // Solo debería contar una vez, no doble.
        $existencia = [
            'hermosillo' => ['nuevo' => 2],
        ];
        $this->assertSame(2, SyscomHermosilloStock::forBranch($existencia, '', 'hermosillo'));
    }

    public function test_real_response_with_total_existencia_aside(): void
    {
        // Estructura como la documentación: total_existencia es campo raíz, existencia es objeto.
        $detail = [
            'total_existencia' => 10,
            'existencia' => [
                'nuevo' => [
                    'hermosillo' => 5,
                    'tijuana' => 3,
                    'merida' => 0,
                ],
                'asterisco' => [
                    'hermosillo' => 2,
                ],
            ],
        ];

        $stock = SyscomHermosilloStock::forBranch($detail['existencia'], '', 'hermosillo');
        $this->assertSame(7, $stock);
    }
}
