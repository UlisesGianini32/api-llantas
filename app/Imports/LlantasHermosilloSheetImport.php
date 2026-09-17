<?php

namespace App\Imports;

use App\Models\Llanta;
use App\Models\ProductoCompuesto;
use App\Models\PriceRule;
use App\Services\FormulaEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class LlantasHermosilloSheetImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        Log::info('==============================');
        Log::info('IMPORT HMO: Inicio de importación (sheet MAYOREO HERMOSILLO)');
        Log::info('IMPORT HMO: Total filas crudas: ' . $rows->count());

        // Buscar encabezado
        while ($rows->count() > 0) {
            $v = mb_strtolower(trim((string)($rows->first()[0] ?? '')));
            if ($v === 'codigo' || $v === 'código') break;
            $rows->shift();
        }

        if ($rows->count() === 0) {
            Log::warning('IMPORT HMO: No se encontró encabezado "codigo/código". Abortando.');
            return;
        }

        $rows->shift(); // quitar encabezado

        // ==========================================
        // 1️⃣ Obtener todos los SKUs que vienen en Excel
        // ==========================================
        $skusExcel = [];

        foreach ($rows as $row) {
            $sku = trim((string)($row[0] ?? ''));
            if ($sku !== '') {
                $skusExcel[] = $sku;
            }
        }

        $skusExcel = array_values(array_unique($skusExcel));

        Log::info('IMPORT HMO: Total SKUs detectados en Excel: ' . count($skusExcel));

        // Log clave del SKU problema
        $testSku = '2055517MEMR182TD';
        if (!in_array($testSku, $skusExcel, true)) {
            Log::warning("IMPORT HMO: SKU {$testSku} NO viene en el Excel (esto es clave)");
        } else {
            Log::info("IMPORT HMO: SKU {$testSku} SÍ viene en el Excel");
        }

        if (count($skusExcel) === 0) {
            Log::warning('IMPORT HMO: No se detectaron SKUs. Abortando.');
            return;
        }

        DB::transaction(function () use ($rows, $skusExcel, $testSku) {

            $now = now();

            // ==========================================
            // 2️⃣ Poner en 0 los que NO vienen (con logs)
            // ==========================================
            $llantasAfectadas = Llanta::whereNotIn('sku', $skusExcel)
                ->where('stock', '!=', 0)
                ->get(['sku', 'stock']);

            Log::info('IMPORT HMO: Llantas que se pondrán en 0 (stock!=0 y no vienen): ' . $llantasAfectadas->count());

            // Log de las primeras 50 para no saturar
            $maxLog = 50;
            $i = 0;
            foreach ($llantasAfectadas as $l) {
                $i++;
                if ($i <= $maxLog) {
                    Log::info("IMPORT HMO: -> Se pondrá en 0 SKU: {$l->sku} | Stock actual: {$l->stock}");
                }
            }
            if ($llantasAfectadas->count() > $maxLog) {
                Log::info("IMPORT HMO: (Se omitieron logs de " . ($llantasAfectadas->count() - $maxLog) . " SKUs para no saturar)");
            }

            // Confirma si el SKU test entrará a ponerse en 0
            $testAntes = Llanta::where('sku', $testSku)->first(['sku', 'stock', 'last_import_at']);
            if ($testAntes) {
                Log::info("IMPORT HMO: TEST antes del update masivo -> SKU {$testSku} | stock={$testAntes->stock} | last_import_at={$testAntes->last_import_at}");
            } else {
                Log::warning("IMPORT HMO: TEST SKU {$testSku} no existe en BD (según aquí).");
            }

            // Update masivo + last_import_at para que quede marcado como “tocados”
            $updatedCount = Llanta::whereNotIn('sku', $skusExcel)
                ->update([
                    'stock' => 0,
                    'last_import_at' => $now,
                ]);

            Log::info("IMPORT HMO: Update masivo llantas (no vienen) -> filas afectadas: {$updatedCount}");

            // Productos compuestos (solo stock a 0, y last_import_at si existe la columna)
            // Si producto_compuestos NO tiene last_import_at, quita esa línea.
            $updatedComp = ProductoCompuesto::whereNotIn('llanta_id', function ($q) use ($skusExcel) {
                    $q->select('id')
                      ->from('llantas')
                      ->whereIn('sku', $skusExcel);
                })
                ->update([
                    'stock' => 0,
                    // 'last_import_at' => $now, // <- descomenta solo si existe columna
                ]);

            Log::info("IMPORT HMO: Update masivo compuestos (llanta no viene) -> filas afectadas: {$updatedComp}");

            $testDespuesMasivo = Llanta::where('sku', $testSku)->first(['sku', 'stock', 'last_import_at']);
            if ($testDespuesMasivo) {
                Log::info("IMPORT HMO: TEST después del update masivo -> SKU {$testSku} | stock={$testDespuesMasivo->stock} | last_import_at={$testDespuesMasivo->last_import_at}");
            }

            // ==========================================
            // 3️⃣ Procesar los que sí vienen (con logs)
            // ==========================================
            $created = 0;
            $updated = 0;
            $processed = 0;

            foreach ($rows as $row) {

                $sku   = trim((string)($row[0] ?? ''));
                $desc  = trim((string)($row[1] ?? ''));
                $stock = intval($row[2] ?? 0);
                $costo = floatval($row[3] ?? 0);

                if ($sku === '') continue;

                $processed++;

                $llanta = Llanta::where('sku', $sku)->first();

                if ($llanta) {

                    $llanta->update([
                        'stock' => $stock,
                        'costo' => $costo,
                        'last_import_at' => $now,
                    ]);

                    if (($llanta->price_mode ?? 'auto') === 'auto') {
                        $llanta->update([
                            'precio_ML' => $this->calcPrecio('llanta', [
                                'costo' => $costo,
                                'piezas' => 1,
                            ])
                        ]);
                    }

                    $updated++;

                    if ($sku === $testSku) {
                        Log::info("IMPORT HMO: TEST SKU procesado en loop -> stock={$stock} costo={$costo} precio_ML={$llanta->precio_ML}");
                    }

                } else {

                    [$marca, $medida] = $this->parseDescripcion($desc);

                    $llanta = Llanta::create([
                        'sku' => $sku,
                        'marca' => $marca,
                        'medida' => $medida,
                        'descripcion' => $desc,
                        'costo' => $costo,
                        'stock' => $stock,
                        'precio_ML' => $this->calcPrecio('llanta', [
                            'costo' => $costo,
                            'piezas' => 1,
                        ]),
                        'price_mode' => 'auto',
                        'last_import_at' => $now,
                    ]);

                    $created++;

                    if ($sku === $testSku) {
                        Log::info("IMPORT HMO: TEST SKU creado -> stock={$stock} costo={$costo} precio_ML={$llanta->precio_ML}");
                    }
                }
            }

            Log::info("IMPORT HMO: Resumen -> procesadas={$processed} | actualizadas={$updated} | creadas={$created}");
            Log::info('IMPORT HMO: Fin de importación');
            Log::info('==============================');
        });
    }

    private function calcPrecio(string $scope, array $vars): float
    {
        $rule = PriceRule::where('rule_set', 'llantas')->where('scope', $scope)->where('active', true)->first();

        if (!$rule) {
            return ((float)$vars['costo']) * 1.5;
        }

        try {
            return (float) app(FormulaEngine::class)
                ->evaluate($rule->formula, $vars);
        } catch (\Throwable $e) {
            return ((float)$vars['costo']) * 1.5;
        }
    }

    private function parseDescripcion(string $desc): array
    {
        $desc = strtoupper($desc);

        preg_match('/\d{3}\/\d{2}R\d{2}/', $desc, $m);
        $medida = $m[0] ?? 'N/A';

        $marca = 'GENERICA';

        return [$marca, $medida];
    }
}
