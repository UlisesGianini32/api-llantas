<?php

namespace App\Http\Controllers\Restock;

use App\Http\Controllers\Controller;
use App\Services\Restock\InventoryDemandForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RestockForecastController extends Controller
{
    public function __construct(
        private readonly InventoryDemandForecastingService $forecastingService
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'brand' => $request->input('brand'),
            'supplier' => $request->input('supplier'),
            'status' => $request->input('status'),
            'cadence_preset' => $request->input('cadence_preset'),
            'coverage_days' => $request->input('coverage_days'),
            'search' => $request->input('search'),
        ];

        $report = $this->forecastingService->generateForecastReport($filters);

        return Inertia::render('Restock/Forecast', [
            'filters' => $filters,
            'summary' => $report['summary'],
            'items' => $report['items'],
            'brands' => $report['brands'],
            'brandConfigs' => $report['brand_configs'],
            'presets' => $report['presets'],
        ]);
    }

    public function saveConfiguration(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'cadence_preset' => ['required', 'string', 'in:WEEKLY_7_10,BIWEEKLY_15_20,MONTHLY_30_45,IMPORT_90_120,CUSTOM'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'target_coverage_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'safety_stock_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $config = $this->forecastingService->saveBrandConfiguration($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Configuración de reabastecimiento para la marca '{$config->brand}' guardada exitosamente.",
                'config' => $config,
            ]);
        }

        return back()->with('success', "Configuración de reabastecimiento para la marca '{$config->brand}' guardada exitosamente.");
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = [
            'brand' => $request->input('brand'),
            'supplier' => $request->input('supplier'),
            'status' => $request->input('status'),
            'cadence_preset' => $request->input('cadence_preset'),
            'coverage_days' => $request->input('coverage_days'),
            'search' => $request->input('search'),
        ];

        $report = $this->forecastingService->generateForecastReport($filters);
        $items = $report['items'];

        $filename = 'sugerencia-reabastecimiento-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($items): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 para Excel

            fputcsv($handle, [
                'SKU',
                'Producto',
                'Marca',
                'Proveedor',
                'Estado Semáforo',
                'Stock Físico',
                'Stock Reservado',
                'Stock Disponible',
                'Venta Diaria Ponderada',
                'Días de Cobertura Restante',
                'Cadencia Planificada',
                'Tiempo Entrega (Días)',
                'Cobertura Meta (Días)',
                'Stock Seguridad (Unidades)',
                'Punto de Reorden',
                'Stock Meta Total',
                'Sugerencia de Compra (Unidades)',
                'Costo Unitario ($)',
                'Inversión Estimada ($)',
            ]);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item['sku'],
                    $item['name'],
                    $item['brand'],
                    $item['supplier'],
                    $item['status'],
                    $item['physical_stock'],
                    $item['reserved_stock'],
                    $item['available_stock'],
                    $item['expected_daily_demand'],
                    $item['days_of_stock_remaining'],
                    $item['cadence_preset'],
                    $item['lead_time_days'],
                    $item['target_coverage_days'],
                    $item['safety_stock_units'],
                    $item['reorder_point'],
                    $item['target_stock_units'],
                    $item['suggested_quantity'],
                    $item['cost'],
                    $item['estimated_investment'],
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
