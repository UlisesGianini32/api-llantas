<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\LlantasImport;
use Inertia\Inertia;

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
    public function importar(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        try {
            Excel::import(new LlantasImport, $request->file('archivo'));

            return redirect()
                ->route('excel.vista')
                ->with('success', 'Archivo importado correctamente.');
        } catch (\Throwable $e) {
            return redirect()
                ->route('excel.vista')
                ->with('error', 'Error al importar: ' . $e->getMessage());
        }
    }
}
