<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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
        $pendingApprovalItems = [];
        $foundCount = 0;

        $categoryCounts = [
            PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_SKU_LINK => 0,
            PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_VARIATION => 0,
            PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL => 0,
            PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE => 0,
            PurchaseOrderMeliImageResolver::AUDIT_MANUAL_OVERRIDE => 0,
        ];

        foreach ($items as $index => $item) {
            $entry = $resolvedByItemId[(int) $item->id] ?? [
                'sku' => trim((string) ($item->product?->sku ?: $item->sku)),
                'url' => null,
                'source' => null,
                'mlm' => null,
                'variation_id' => null,
                'variation_label' => null,
                'listing_title' => null,
                'meli_account_id' => null,
                'listing_status' => null,
                'photo_source_mode' => $item->getAttribute('photo_source_mode'),
                'reason' => 'unresolved',
                'audit_category' => PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE,
                'ambiguous' => false,
                'ambiguous_matches' => [],
            ];

            $hasPhoto = is_string($entry['url']) && $entry['url'] !== '';
            $auditCategory = (string) ($entry['audit_category'] ?? (
                $hasPhoto
                    ? PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_SKU_LINK
                    : PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE
            ));
            $reason = (string) ($entry['reason'] ?? 'unresolved');
            $discoveryCandidates = [];

            if (
                ! $hasPhoto
                && $auditCategory === PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE
                && ($entry['photo_source_mode'] ?? null) !== PurchaseOrderItem::PHOTO_MODE_NONE
            ) {
                $discoveryCandidates = $resolver->discoverCandidatesForItem($item, null, 5);
                if ($discoveryCandidates !== []) {
                    $auditCategory = PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL;
                    if ($reason === 'no_listing_found') {
                        $reason = 'title_candidates_found';
                    }
                }
            }

            if (! array_key_exists($auditCategory, $categoryCounts)) {
                $auditCategory = $hasPhoto
                    ? PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_SKU_LINK
                    : PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE;
            }
            $categoryCounts[$auditCategory]++;

            if ($hasPhoto) {
                $foundCount++;
            } else {
                $missing[] = [
                    'item_id' => (int) $item->id,
                    'sku' => $entry['sku'],
                    'product_name' => $item->product?->name ?? $item->product_name,
                    'mlm' => $entry['mlm'],
                    'variation_id' => $entry['variation_id'],
                    'audit_category' => $auditCategory,
                    'reason' => $reason,
                ];
            }

            if (! empty($entry['ambiguous'])) {
                $ambiguous[] = [
                    'item_id' => (int) $item->id,
                    'sku' => $entry['sku'],
                    'reason' => $reason,
                    'matches' => $entry['ambiguous_matches'],
                ];
            }

            if ($auditCategory === PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL) {
                $candidateMatches = ! empty($entry['ambiguous_matches'])
                    ? $entry['ambiguous_matches']
                    : $discoveryCandidates;

                $pendingApprovalItems[] = [
                    'item_id' => (int) $item->id,
                    'sku' => $entry['sku'],
                    'product_name' => $item->product?->name ?? $item->product_name,
                    'reason' => $reason,
                    'candidates' => $candidateMatches,
                ];
            }

            $categoryLabel = $this->categoryLabel($auditCategory);

            $rows[] = [
                'line' => $index + 1,
                'item_id' => (int) $item->id,
                'sku' => $entry['sku'],
                'supplier_sku' => $item->supplier_sku,
                'product_name' => $item->product?->name ?? $item->product_name,
                'quantity_ordered' => (int) $item->quantity_ordered,
                'quantity_received' => (int) $item->quantity_received,
                'has_photo' => $hasPhoto,
                'audit_category' => $auditCategory,
                'audit_category_label' => $categoryLabel,
                'source' => $entry['source'],
                'mlm' => $entry['mlm'],
                'variation_id' => $entry['variation_id'],
                'variation_label' => $entry['variation_label'] ?? null,
                'listing_title' => $entry['listing_title'] ?? null,
                'meli_account_id' => $entry['meli_account_id'],
                'listing_status' => $entry['listing_status'],
                'photo_source_mode' => $entry['photo_source_mode'] ?? null,
                'reason' => $reason,
                'ambiguous' => (bool) $entry['ambiguous'],
                'discovery_candidates_count' => count($discoveryCandidates),
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
                'resolved_by_sku_or_link' => $categoryCounts[PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_SKU_LINK],
                'resolved_by_variation' => $categoryCounts[PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_VARIATION],
                'pending_manual_approval' => $categoryCounts[PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL],
                'pending_without_candidate' => $categoryCounts[PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE],
                'manual_override' => $categoryCounts[PurchaseOrderMeliImageResolver::AUDIT_MANUAL_OVERRIDE],
                'by_category' => $categoryCounts,
            ],
            'missing_skus' => $missing,
            'ambiguous_matches' => $ambiguous,
            'pending_manual_approval_items' => $pendingApprovalItems,
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
        $this->line(sprintf(
            'Desglose -> SKU/vínculo: %d | Variación: %d | Manual/override: %d | Pendiente aprobación: %d | Pendiente sin candidato: %d',
            $report['summary']['resolved_by_sku_or_link'],
            $report['summary']['resolved_by_variation'],
            $report['summary']['manual_override'],
            $report['summary']['pending_manual_approval'],
            $report['summary']['pending_without_candidate'],
        ));

        $this->newLine();
        $this->table(
            ['#', 'SKU', 'Foto', 'Categoría', 'Fuente', 'MLM', 'Variación', 'Diagnóstico'],
            array_map(static fn (array $r): array => [
                $r['line'],
                $r['sku'] ?: '—',
                $r['has_photo'] ? 'SÍ' : 'NO',
                $r['audit_category_label'],
                $r['source'] ?? '—',
                $r['mlm'] ?? '—',
                $r['variation_id'] ? ($r['variation_id'].($r['variation_label'] ? " ({$r['variation_label']})" : '')) : '—',
                $r['reason'],
            ], $rows),
        );

        if ($missing !== []) {
            $this->newLine();
            $this->warn('Partidas sin fotografía asignada:');
            $this->table(
                ['SKU', 'Producto', 'Categoría', 'Motivo'],
                array_map(fn (array $m): array => [
                    $m['sku'] ?: '—',
                    mb_substr((string) ($m['product_name'] ?? ''), 0, 50),
                    $this->categoryLabel($m['audit_category']),
                    $m['reason'],
                ], $missing),
            );
        }

        if ($pendingApprovalItems !== []) {
            $this->newLine();
            $this->warn('Partidas pendientes de aprobación manual (candidatos propuestos):');
            foreach ($pendingApprovalItems as $entry) {
                $this->line(sprintf(
                    ' - SKU %s (%s): %d candidato(s)',
                    $entry['sku'] ?: '—',
                    $entry['reason'],
                    count($entry['candidates']),
                ));
            }
        }

        return self::SUCCESS;
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_SKU_LINK => 'Resuelto por SKU/vínculo',
            PurchaseOrderMeliImageResolver::AUDIT_RESOLVED_VARIATION => 'Resuelto por variación',
            PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL => 'Pendiente por aprobación manual',
            PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE => 'Pendiente sin candidato',
            PurchaseOrderMeliImageResolver::AUDIT_MANUAL_OVERRIDE => 'Imagen manual/override',
            default => $category,
        };
    }
}
