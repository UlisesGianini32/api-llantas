<?php

namespace App\Imports;

use App\Models\Llanta;
use App\Models\ProductoCompuesto;
use App\Models\PriceRule;
use App\Services\FormulaEngine;
use App\Services\Llantas\LlantaDescriptionParser;
use App\Services\Llantas\LlantaResolverService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class LlantasHermosilloSheetImport implements ToCollection
{
    public function __construct(
        private ?LlantaResolverService $resolver = null,
        private ?LlantaDescriptionParser $parser = null,
    ) {
        $this->resolver ??= app(LlantaResolverService::class);
        $this->parser ??= app(LlantaDescriptionParser::class);
    }

    public function collection(Collection $rows)
    {
        while ($rows->count() > 0) {
            $header = mb_strtolower(trim((string) ($rows->first()[0] ?? '')));
            if (in_array($header, ['codigo', 'código'], true)) {
                break;
            }
            $rows->shift();
        }

        if ($rows->isEmpty()) {
            Log::warning('IMPORT HMO: No se encontró encabezado codigo/código.');
            return;
        }

        $rows->shift();
        $items = collect();

        foreach ($rows as $row) {
            $sku = trim((string) ($row[0] ?? ''));
            if ($sku === '') {
                continue;
            }

            $items->push([
                'sku' => $sku,
                'descripcion' => trim((string) ($row[1] ?? '')),
                'stock' => (int) ($row[2] ?? 0),
                'costo' => (float) ($row[3] ?? 0),
            ]);
        }

        if ($items->isEmpty()) {
            Log::warning('IMPORT HMO: Excel sin SKUs válidos.');
            return;
        }

        DB::transaction(function () use ($items) {
            $now = now();
            $presentLlantaIds = [];
            $stats = ['processed' => 0, 'updated' => 0, 'created' => 0, 'aliases' => 0, 'candidates' => 0];

            foreach ($items as $item) {
                $stats['processed']++;
                $resolved = $this->resolver->resolve($item['sku'], $item['descripcion']);
                $llanta = $resolved['llanta'];

                if ($resolved['type'] === 'candidate') {
                    $stats['candidates']++;
                }

                if (!$llanta) {
                    $parsed = $this->parser->parse($item['descripcion']);
                    $llanta = Llanta::create([
                        'sku' => $item['sku'],
                        'marca' => $parsed['marca'],
                        'medida' => $parsed['medida'],
                        'descripcion' => $item['descripcion'],
                        'costo' => $item['costo'],
                        'stock' => $item['stock'],
                        'precio_ML' => $this->calcPrecio('llanta', ['costo' => $item['costo'], 'piezas' => 1]),
                        'price_mode' => 'auto',
                        'last_import_at' => $now,
                    ]);
                    $stats['created']++;
                } else {
                    if (in_array($resolved['type'], ['alias', 'auto_alias'], true)) {
                        $stats['aliases']++;
                    }

                    $parsed = $this->parser->parse($item['descripcion']);
                    $updates = [
                        'stock' => $item['stock'],
                        'costo' => $item['costo'],
                        'descripcion' => $item['descripcion'],
                        'last_import_at' => $now,
                    ];

                    if (($llanta->marca ?? 'GENERICA') === 'GENERICA' && $parsed['marca'] !== 'GENERICA') {
                        $updates['marca'] = $parsed['marca'];
                    }
                    if (($llanta->medida ?? 'N/A') === 'N/A' && $parsed['medida'] !== 'N/A') {
                        $updates['medida'] = $parsed['medida'];
                    }
                    if (($llanta->price_mode ?? 'auto') === 'auto') {
                        $updates['precio_ML'] = $this->calcPrecio('llanta', ['costo' => $item['costo'], 'piezas' => 1]);
                    }

                    $llanta->update($updates);
                    $stats['updated']++;
                }

                $presentLlantaIds[] = $llanta->id;
                $this->syncCompuestos($llanta);
            }

            $presentLlantaIds = array_values(array_unique($presentLlantaIds));

            Llanta::whereNotIn('id', $presentLlantaIds)->update([
                'stock' => 0,
                'last_import_at' => $now,
            ]);

            ProductoCompuesto::whereNotIn('llanta_id', $presentLlantaIds)->update(['stock' => 0]);

            Log::info('IMPORT HMO INTELIGENTE: resumen', $stats);
        });
    }

    private function syncCompuestos(Llanta $llanta): void
    {
        foreach (['par' => 2, 'juego4' => 4] as $tipo => $piezas) {
            $compuesto = ProductoCompuesto::where('llanta_id', $llanta->id)->where('tipo', $tipo)->first();
            if (!$compuesto) {
                continue;
            }

            $updates = [
                'stock' => intdiv(max(0, (int) $llanta->stock), $piezas),
                'costo' => (float) $llanta->costo * $piezas,
                'descripcion' => $llanta->descripcion,
            ];

            if (($compuesto->price_mode ?? 'auto') === 'auto') {
                $updates['precio_ML'] = $this->calcPrecio($tipo, [
                    'costo' => (float) $llanta->costo,
                    'piezas' => $piezas,
                ]);
            }

            $compuesto->update($updates);
        }
    }

    private function calcPrecio(string $scope, array $vars): float
    {
        $rule = PriceRule::where('rule_set', 'llantas')->where('scope', $scope)->where('active', true)->first();
        if (!$rule) {
            return ((float) $vars['costo']) * (int) ($vars['piezas'] ?? 1) * ($scope === 'juego4' ? 1.45 : 1.5);
        }

        try {
            return (float) app(FormulaEngine::class)->evaluate($rule->formula, $vars);
        } catch (\Throwable) {
            return ((float) $vars['costo']) * (int) ($vars['piezas'] ?? 1) * ($scope === 'juego4' ? 1.45 : 1.5);
        }
    }
}
