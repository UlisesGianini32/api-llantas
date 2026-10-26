<?php

namespace App\Http\Controllers;

use App\Services\Profitability\ProfitabilityService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfitabilityController extends Controller
{
    public function __construct(
        protected ProfitabilityService $profitabilityService
    ) {}

    /**
     * Muestra el panel interactivo de rentabilidad y simulador de comisiones.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only([
            'period',
            'from',
            'to',
            'channel',
            'status',
            'search',
            'commission_pct',
            'commission_base',
            'fallback_fee_pct',
            'page',
            'per_page',
        ]);

        try {
            $summary = $this->profitabilityService->getSummary($filters);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error cargando módulo de Rentabilidad y Comisiones: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $summary = $this->profitabilityService->getEmptySummary($filters, $e->getMessage());
        }

        return Inertia::render('Profitability/Index', [
            'metrics' => $summary['metrics'],
            'timeline' => $summary['timeline'],
            'orders' => $summary['orders'],
            'filters' => $summary['filters'],
            'error' => $summary['error'] ?? null,
        ]);
    }

    /**
     * Exporta todas las órdenes con desglose financiero a un archivo CSV estructurado.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $request->only([
            'period',
            'from',
            'to',
            'channel',
            'status',
            'search',
            'commission_pct',
            'commission_base',
            'fallback_fee_pct',
        ]);

        $data = $this->profitabilityService->getProcessedOrders($filters);
        $summary = $this->profitabilityService->getSummary($filters);
        $metrics = $summary['metrics'];
        $orders = $data['orders'];

        $filename = 'rentabilidad_comisiones_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($orders, $metrics, $data) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM para apertura correcta con acentos en Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Encabezados ejecutivos
            fputcsv($handle, ['REPORTE EJECUTIVO DE RENTABILIDAD Y PROPUESTA DE COMISIONES']);
            fputcsv($handle, ['Periodo:', $data['periodLabel']]);
            fputcsv($handle, ['Fecha de emision:', now()->format('d/m/Y H:i:s')]);
            fputcsv($handle, ['Canal seleccionado:', strtoupper($data['channelFilter'])]);
            fputcsv($handle, ['Total Ordenes:', $metrics['total_orders_count']]);
            fputcsv($handle, ['Ordenes Entregadas/Efectivas:', $metrics['delivered_orders_count']]);
            fputcsv($handle, ['Ordenes Canceladas/Devueltas:', $metrics['cancelled_returned_count']]);
            fputcsv($handle, ['Venta Bruta Total:', '$' . number_format($metrics['total_sales_gross'], 2)]);
            fputcsv($handle, ['Devoluciones / Cancelaciones:', '-$' . number_format($metrics['total_returns_amount'], 2)]);
            fputcsv($handle, ['Venta Neta:', '$' . number_format($metrics['total_sales_net'], 2)]);
            fputcsv($handle, ['Comisiones Mercado Libre (Cargo por venta):', '-$' . number_format($metrics['total_meli_fees'], 2)]);
            fputcsv($handle, ['Envios a Cargo del Vendedor ML:', '-$' . number_format($metrics['total_shipping_fees'], 2)]);
            fputcsv($handle, ['ISR Retenido ML (2.5%):', '-$' . number_format($metrics['total_isr_fees'] ?? 0, 2)]);
            fputcsv($handle, ['IVA Retenido ML (8.0%):', '-$' . number_format($metrics['total_iva_fees'] ?? 0, 2)]);
            fputcsv($handle, ['Total Impuestos Retenidos ML:', '-$' . number_format($metrics['total_taxes_fees'] ?? 0, 2)]);
            fputcsv($handle, ['TOTAL A RECIBIR EN MERCADO LIBRE:', '$' . number_format($metrics['total_meli_net_received'] ?? 0, 2)]);
            fputcsv($handle, ['Costo Mercancia (SYSCOM/Llantas):', '-$' . number_format($metrics['total_product_cost'], 2)]);
            fputcsv($handle, ['Total Deducciones (ML + Impuestos + Costo):', '-$' . number_format($metrics['total_deductions'], 2)]);
            fputcsv($handle, ['GANANCIA REAL NETA LIBRE:', '$' . number_format($metrics['total_net_profit'], 2)]);
            fputcsv($handle, ['Margen de Rentabilidad Neta:', $metrics['profit_margin_pct'] . '%']);
            fputcsv($handle, []);
            fputcsv($handle, ['SIMULACION DE COMISION:']);
            fputcsv($handle, ['Base de calculo:', $metrics['commission']['base_label']]);
            fputcsv($handle, ['Porcentaje aplicado:', $metrics['commission']['rate_pct'] . '%']);
            fputcsv($handle, ['Comision estimada usuario:', '$' . number_format($metrics['commission']['user_amount'], 2)]);
            fputcsv($handle, ['Ganancia libre para el Jefe / Empresa:', '$' . number_format($metrics['commission']['company_amount'], 2)]);
            fputcsv($handle, []);
            fputcsv($handle, [
                'ID Orden ML',
                'ID Venta / Pack ML',
                'Fecha',
                'Canal',
                'Folio Syscom',
                'Producto / Publicacion',
                'SKU',
                'Cantidad',
                'Estado ML',
                'Estado Envio',
                'Cancelada/Devuelta',
                'Motivo Cancelacion',
                'Venta Bruta (MXN)',
                'Comision ML (MXN)',
                'Envio Vendedor ML (MXN)',
                'ISR Retenido ML (MXN)',
                'IVA Retenido ML (MXN)',
                'Total Impuestos ML (MXN)',
                'Total a Recibir ML (MXN)',
                'Costo Compra (MXN)',
                'Total Deducciones (MXN)',
                'Venta Neta (MXN)',
                'Ganancia Neta Real (MXN)',
                'Margen %',
            ]);

            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order['order_id'],
                    $order['pack_id'] ?? $order['display_id'] ?? $order['order_id'],
                    $order['created_at'],
                    $order['channel_label'],
                    $order['syscom_order_folio'] ?? '—',
                    $order['title'],
                    $order['sku'],
                    $order['quantity'],
                    $order['status'],
                    $order['shipping_status'],
                    $order['is_cancelled_or_returned'] ? 'SI (Devuelta/Cancelada)' : 'NO (Efectiva)',
                    $order['cancellation_reason'] ?? '',
                    number_format($order['gross_sale'], 2, '.', ''),
                    number_format($order['meli_fee'], 2, '.', ''),
                    number_format($order['shipping_fee'], 2, '.', ''),
                    number_format($order['isr_fee'] ?? 0, 2, '.', ''),
                    number_format($order['iva_fee'] ?? 0, 2, '.', ''),
                    number_format($order['taxes_fee'] ?? 0, 2, '.', ''),
                    number_format($order['meli_net_received'] ?? 0, 2, '.', ''),
                    number_format($order['product_cost'], 2, '.', ''),
                    number_format($order['total_deductions'], 2, '.', ''),
                    number_format($order['net_sale'], 2, '.', ''),
                    number_format($order['net_profit'], 2, '.', ''),
                    number_format($order['profit_margin_pct'], 2, '.', '') . '%',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
