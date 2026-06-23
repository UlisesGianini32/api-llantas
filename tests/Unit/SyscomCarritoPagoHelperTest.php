<?php

namespace Tests\Unit;

use App\Support\SyscomCarritoPagoHelper;
use PHPUnit\Framework\TestCase;

class SyscomCarritoPagoHelperTest extends TestCase
{
    public function test_resolve_uses_forma_pue_not_codigo_sat(): void
    {
        $json = [
            [
                'nombre' => 'Transferencia',
                'metodo' => [[
                    'transferencia' => [[
                        'titulo' => 'Transferencia electrónica de fondos',
                        'codigo' => '03',
                        'forma' => [['pue' => '1', 'ppd' => '2']],
                    ]],
                ]],
            ],
            [
                'nombre' => 'Pago en Sucursal',
                'metodo' => [[
                    'tarjeta' => [[
                        'titulo' => 'Tarjeta de Crédito',
                        'codigo' => '04',
                        'forma' => [['pue' => '7', 'ppd' => '8']],
                    ]],
                ]],
            ],
        ];

        $hit = SyscomCarritoPagoHelper::resolvePaymentForOrder($json, 'pue', 'tarjeta+credito', '04');

        $this->assertSame('7', $hit['metodo_pago']);
        $this->assertSame('04', $hit['codigo_sat']);
    }

    public function test_falls_back_to_codigo_sat_when_forma_missing(): void
    {
        $json = [
            [
                'nombre' => 'Sucursal',
                'metodo' => [[
                    'tarjeta' => [[
                        'titulo' => 'Tarjeta de Crédito / Call Center',
                        'codigo' => '04',
                    ]],
                ]],
            ],
        ];

        $hit = SyscomCarritoPagoHelper::resolvePaymentForOrder($json, 'pue', 'tarjeta+credito', '04');

        $this->assertSame('04', $hit['metodo_pago']);
        $this->assertSame('04', $hit['codigo_sat']);
    }
}
