<?php

namespace App\Http\Controllers;

use App\Imports\LlantasImport;
use App\Services\Llantas\LlantaComparisonScannerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ExcelImportController extends Controller
{
    /**
     * Mostrar vista de importación
     */
    public function vista()
    {
        return Inertia::render('Excel/Importar');
    }

    /**
     * Procesar archivo Excel
     */
    public function importar(Request $request, LlantaComparisonScannerService $scanner)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        try {
            Excel::import(new LlantasImport, $request->file('archivo'));
        } catch (\Throwable $e) {
            return redirect()
                ->route('excel.vista')
                ->with('error', 'Error al importar: '.$e->getMessage());
        }

        try {
            $metrics = $scanner->scan();
            $response = redirect()
                ->route('excel.vista')
                ->with('success', 'Archivo importado correctamente.');

            if (($metrics['new_candidates'] ?? 0) > 0) {
                $response->with(
                    'warning',
                    sprintf(
                        'Importación correcta. Se detectaron %d candidatos nuevos de posibles duplicados. Revisar en /llantas/comparador.',
                        $metrics['new_candidates']
                    )
                );
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('Comparador de llantas falló después de importar Excel', [
                'exception' => $e,
            ]);

            return redirect()
                ->route('excel.vista')
                ->with('success', 'Archivo importado correctamente.')
                ->with('warning', 'La importación terminó, pero no fue posible analizar posibles duplicados.');
        }
    }
}
