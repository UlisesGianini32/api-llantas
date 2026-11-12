<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveSeller;
use App\Services\Autopartes\AutomotivePosSaleService;
use App\Services\Autopartes\BranchCatalogAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotivePosSaleService $saleService,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $filters = [
            'date_from' => (string) $request->query('date_from', now()->toDateString()),
            'date_to' => (string) $request->query('date_to', now()->toDateString()),
            'seller_id' => (string) $request->query('seller_id', ''),
        ];

        $report = $this->saleService->buildSellerReport($branch, $filters, $user);
        $sellers = AutomotiveSeller::query()
            ->where('branch_id', $branch->getKey())
            ->orderBy('full_name')
            ->get(['id', 'code', 'full_name', 'is_active']);

        return Inertia::render('Autopartes/Reports', [
            'branch' => $branch->only(['id', 'code', 'name']),
            'capabilities' => $this->access->capabilities($user, $branch),
            'filters' => $filters,
            'report' => $report,
            'sellers' => $sellers,
        ]);
    }

    public function exportCsv(Request $request, AutomotiveBranch $branch): StreamedResponse
    {
        $user = $request->user();
        $this->access->authorize($user, $branch, BranchCatalogAccess::REPORTS_EXPORT);

        $filters = [
            'date_from' => (string) $request->query('date_from', now()->toDateString()),
            'date_to' => (string) $request->query('date_to', now()->toDateString()),
            'seller_id' => (string) $request->query('seller_id', ''),
        ];

        $report = $this->saleService->buildSellerReport($branch, $filters, $user);
        $filename = sprintf(
            'reporte-vendedores-autopartes-%s-%s-a-%s.csv',
            strtolower($branch->code),
            $report['summary']['date_from'],
            $report['summary']['date_to'],
        );

        return response()->streamDownload(function () use ($report, $branch): void {
            $out = fopen('php://output', 'wb');
            if (! $out) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Sucursal',
                'Codigo Vendedor',
                'Nombre Vendedor',
                'Ventas Completadas',
                'Canceladas',
                'Con Devolucion',
                'Unidades Vendidas',
                'Unidades Devueltas',
                'Unidades Netas',
                'Venta Bruta MXN',
                'Desc Mayoreo MXN',
                'Desc Manual MXN',
                'Descuento Total MXN',
                'Reembolsos MXN',
                'Venta Neta MXN',
            ]);

            foreach ($report['by_seller'] as $row) {
                fputcsv($out, [
                    $branch->code,
                    $row['seller_code'],
                    $row['seller_name'],
                    $row['sales_count'],
                    $row['cancelled_count'],
                    $row['returned_count'],
                    $row['units_sold'],
                    $row['units_returned'],
                    $row['net_units'],
                    $row['gross_sales'],
                    $row['wholesale_discounts'],
                    $row['manual_discounts'],
                    $row['total_discounts'],
                    $row['refunded_amount'],
                    $row['net_sales'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
