<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use Illuminate\Console\Command;

/**
 * Strictly read-only diagnostic audit of Mercado Libre product photos for a purchase order.
 */
class AuditPurchaseOrderPhotosCommand extends Command
{
    protected $signature = 'purchasing:audit-order-photos
        {order : Folio de la orden (ej. OC-20261113-0001) o ID numérico}
        {--json : Emitir el reporte estructurado en formato JSON}';

    protected $description = 'Audita en modo solo lectura las fotografías de Mercado Libre resueltas para una orden de compra.';

    public function handle(PurchaseOrderMeliImageResolver $resolver): int
    {
        $identifier = trim((string) $this->argument('order'));
        if ($identifier === '') {
            $this->error('Debes indicar el folio o ID de la orden de compra.');

            return self::FAILURE;
        }

        $order = PurchaseOrder::query()
            ->with(['items.product:id,sku,name,brand'])
            ->where('order_number', $identifier)
            ->when(ctype_digit($identifier), fn ($query) => $query->orWhere('id', (int) $identifier))
            ->first();

        if (! $order) {
            $this->error("No se encontró ninguna orden de compra con identificador [{$identifier}].");

            return self::FAILURE;
        }

        $resolvedByItemId = $resolver->resolveForOrder($order, false);
        $items = $order->items;

        $rows = [];
        $missing = [];
        $ambiguous = [];
        $foundCount = 0;

        foreach ($items as $index => $item) {
            $entry = $resolvedByItemId[(int) $item->id] ?? [
                'sku' => trim((string) ($item->product?->sku ?: $item->sku)),
                'url' => null,
                'source' => null,
                'mlm' => null,
                'variation_id' => null,
                'meli_account_id' => null,
                'listing_status' => null,
                'reason' => 'unresolved',
                'ambiguous' => false,
                'ambiguous_matches' => [],
            ];

            $hasPhoto = is_string($entry['url']) && $entry['url'] !== '';
            if ($hasPhoto) {
                $foundCount++;
            } else {
                $missing[] = [
                    'item_id' => (int) $item->id,
                    'sku' => $entry['sku'],
                    'product_name' => $item->product?->name ?? $item->product_name,
                    'mlm' => $entry['mlm'],
                    'variation_id' => $entry['variation_id'],
                    'reason' => $entry['reason'],
                ];
            }

            if (! empty($entry['ambiguous'])) {
                $ambiguous[] = [
                    'item_id' => (int) $item->id,
                    'sku' => $entry['sku'],
                    'reason' => $entry['reason'],
                    'matches' => $entry['ambiguous_matches'],
                ];
            }

            $rows[] = [
                'line' => $index + 1,
                'item_id' => (int) $item->id,
                'sku' => $entry['sku'],
                'supplier_sku' => $item->supplier_sku,
                'product_name' => $item->product?->name ?? $item->product_name,
                'quantity_ordered' => (int) $item->quantity_ordered,
                'quantity_received' => (int) $item->quantity_received,
                'has_photo' => $hasPhoto,
                'source' => $entry['source'],
                'mlm' => $entry['mlm'],
                'variation_id' => $entry['variation_id'],
                'meli_account_id' => $entry['meli_account_id'],
                'listing_status' => $entry['listing_status'],
                'reason' => $entry['reason'],
                'ambiguous' => (bool) $entry['ambiguous'],
            ];
        }

        $report = [
            'order' => [
                'id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'supplier_name' => (string) $order->supplier_name,
                'status' => (string) $order->status,
                'total_items' => $items->count(),
                'total_units_ordered' => (int) $items->sum('quantity_ordered'),
                'total_units_received' => (int) $items->sum('quantity_received'),
            ],
            'summary' => [
                'total_items' => $items->count(),
                'with_photo' => $foundCount,
                'without_photo' => $items->count() - $foundCount,
                'ambiguous_count' => count($ambiguous),
            ],
            'missing_skus' => $missing,
            'ambiguous_matches' => $ambiguous,
            'items' => $rows,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Auditoría de fotografías ML (solo lectura) — {$order->order_number} (ID #{$order->id})");
        $this->line("Proveedor: {$order->supplier_name} | Estado: {$order->status}");
        $this->line(sprintf(
            'Partidas actuales: %d | Piezas pedidas: %d | Piezas recibidas: %d',
            $report['order']['total_items'],
            $report['order']['total_units_ordered'],
            $report['order']['total_units_received'],
        ));
        $this->line(sprintf(
            'Con foto: %d | Sin foto: %d | Ambiguas: %d',
            $report['summary']['with_photo'],
            $report['summary']['without_photo'],
            $report['summary']['ambiguous_count'],
        ));

        $this->newLine();
        $this->table(
            ['#', 'SKU', 'Foto', 'Fuente', 'MLM', 'Variación', 'Cuenta', 'Diagnóstico', 'Ambigua'],
            array_map(static fn (array $r): array => [
                $r['line'],
                $r['sku'] ?: '—',
                $r['has_photo'] ? 'SÍ' : 'NO',
                $r['source'] ?? '—',
                $r['mlm'] ?? '—',
                $r['variation_id'] ?? '—',
                $r['meli_account_id'] ?? '—',
                $r['reason'],
                $r['ambiguous'] ? 'SÍ' : 'NO',
            ], $rows),
        );

        if ($missing !== []) {
            $this->newLine();
            $this->warn('SKUs sin fotografía encontrada:');
            $this->table(
                ['SKU', 'Producto', 'MLM', 'Variación', 'Motivo'],
                array_map(static fn (array $m): array => [
                    $m['sku'] ?: '—',
                    mb_substr((string) ($m['product_name'] ?? ''), 0, 50),
                    $m['mlm'] ?? '—',
                    $m['variation_id'] ?? '—',
                    $m['reason'],
                ], $missing),
            );
        }

        if ($ambiguous !== []) {
            $this->newLine();
            $this->warn('Coincidencias ambiguas detectadas:');
            foreach ($ambiguous as $entry) {
                $this->line(sprintf(
                    ' - SKU %s (%s): %d candidatos',
                    $entry['sku'] ?: '—',
                    $entry['reason'],
                    count($entry['matches']),
                ));
            }
        }

        return self::SUCCESS;
    }
}
