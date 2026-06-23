<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Llanta;
use App\Models\ProductoCompuesto;

class RegenerarCompuestos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'llantas:regenerar-compuestos';

    /**
     * The console command description.
     */
    protected $description = 'Regenera productos compuestos (par y juego de 4) para todas las llantas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Regenerando productos compuestos...');

        Llanta::chunk(100, function ($llantas) {
            foreach ($llantas as $llanta) {
                $this->syncCompuestos($llanta);
            }
        });

        $this->info('✅ Proceso terminado correctamente.');
        return Command::SUCCESS;
    }

    /**
     * SIEMPRE crea PAR y JUEGO4
     * NO toca MLM
     * Respeta precio manual
     */
    private function syncCompuestos(Llanta $llanta): void
    {
        /* =========================
         | PAR (2)
         =========================*/
        $par = ProductoCompuesto::where('llanta_id', $llanta->id)
            ->where('tipo', 'par')
            ->first();

        $precioAutoPar = ($llanta->costo * 2) * 1.4;

        $precioParManual = $par &&
            !is_null($par->precio_ML) &&
            abs($par->precio_ML - $precioAutoPar) > 0.01;

        ProductoCompuesto::updateOrCreate(
            [
                'llanta_id' => $llanta->id,
                'tipo'      => 'par',
            ],
            [
                'sku'              => $llanta->sku . '-2',
                'stock'            => 2,
                'descripcion'      => $llanta->descripcion,
                'title_familyname' => $llanta->title_familyname,
                'costo'            => $llanta->costo * 2,
                'precio_ML'        => $precioParManual
                    ? $par->precio_ML
                    : $precioAutoPar,
                // ❗ MLM NO SE TOCA
            ]
        );

        /* =========================
         | JUEGO DE 4
         =========================*/
        $j4 = ProductoCompuesto::where('llanta_id', $llanta->id)
            ->where('tipo', 'juego4')
            ->first();

        $precioAutoJ4 = ($llanta->costo * 4) * 1.35;

        $precioJ4Manual = $j4 &&
            !is_null($j4->precio_ML) &&
            abs($j4->precio_ML - $precioAutoJ4) > 0.01;

        ProductoCompuesto::updateOrCreate(
            [
                'llanta_id' => $llanta->id,
                'tipo'      => 'juego4',
            ],
            [
                'sku'              => $llanta->sku . '-4',
                'stock'            => 4,
                'descripcion'      => $llanta->descripcion,
                'title_familyname' => $llanta->title_familyname,
                'costo'            => $llanta->costo * 4,
                'precio_ML'        => $precioJ4Manual
                    ? $j4->precio_ML
                    : $precioAutoJ4,
                // ❗ MLM NO SE TOCA
            ]
        );
    }
}
