<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Llanta;
use App\Models\ProductoCompuesto;
use App\Services\TitleGenerator;

class RegenerarTitulos extends Command
{
    protected $signature = 'titulos:regenerar';
    protected $description = 'Regenera todos los títulos automáticamente';

    public function handle()
    {
        $this->info('Actualizando llantas...');

        Llanta::chunk(200, function ($llantas) {
            foreach ($llantas as $llanta) {
                $llanta->title_familyname = TitleGenerator::llanta($llanta);
                $llanta->save();
            }
        });

        $this->info('Actualizando compuestos...');

        // Traemos la relación para usar marca/medida desde llantas
        ProductoCompuesto::with('llanta')->chunk(200, function ($compuestos) {
            foreach ($compuestos as $compuesto) {
                $compuesto->title_familyname = TitleGenerator::compuesto($compuesto);
                $compuesto->save();
            }
        });

        $this->info('Listo 🚀');
        return self::SUCCESS;
    }
}
