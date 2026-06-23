<?php

namespace App\Services;

use App\Models\Llanta;
use App\Models\ProductoCompuesto;

class CompuestoService
{
    /**
     * Regenera SIEMPRE par y juego4
     * - No importa stock
     * - No toca MLM
     * - Respeta precios manuales
     */
    public function sync(Llanta $llanta): void
    {
        // =========================
        // PAR (2)
        // =========================
        $this->crearOActualizar(
            $llanta,
            'par',
            2,
            1.4
        );

        // =========================
        // JUEGO 4
        // =========================
        $this->crearOActualizar(
            $llanta,
            'juego4',
            4,
            1.35
        );
    }

    private function crearOActualizar(
        Llanta $llanta,
        string $tipo,
        int $piezas,
        float $factor
    ): void {

        $comp = ProductoCompuesto::where('llanta_id', $llanta->id)
            ->where('tipo', $tipo)
            ->first();

        $precioAuto = ($llanta->costo * $piezas) * $factor;

        $precioManual = $comp
            && !is_null($comp->precio_ML)
            && abs($comp->precio_ML - $precioAuto) > 0.01;

        ProductoCompuesto::updateOrCreate(
            [
                'llanta_id' => $llanta->id,
                'tipo'      => $tipo,
            ],
            [
                'sku'              => $llanta->sku . '-' . $piezas,
                'stock'            => $piezas,
                'descripcion'      => $llanta->descripcion,
                'title_familyname' => $llanta->title_familyname,
                'costo'            => $llanta->costo * $piezas,
                'precio_ML'        => $precioManual
                                        ? $comp->precio_ML
                                        : $precioAuto,
                // MLM NO SE TOCA
            ]
        );
    }
}
