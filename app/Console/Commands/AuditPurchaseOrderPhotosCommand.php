<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strictly read-only diagnostic audit of Mercado Libre product photos for a purchase order.
 *
 * By default runs in `fast` mode: performs a single bulk resolution pass (using the normalized
 * `meli_listing_sku_index` when available, or a single bounded fallback scan) and skips expensive
 * per-item title/JSON discovery, explicitly flagging `deep_discovery_skipped = true`.
 *
 * Pass `--deep` (optionally with `--time-budget=20`) to run controlled title candidate discovery
 * without repeating raw variation scans per item.
 */
class AuditPurchaseOrderPhotosCommand extends Command
{
    protected $signature = 'purchasing:audit-order-photos
        {order : Folio de la orden (ej. OC-20261113-0001) o ID numérico}
        {--json : Emitir el reporte estructurado en formato JSON}
        {--deep : Ejecutar descubrimiento profundo por título/palabras clave para partidas pendientes (recomendado en base aislada)}
        {--time-budget=20 : Presupuesto máximo en segundos para el descubrimiento profundo cuando se usa --deep}';

    protected $description = 'Audita en modo solo lectura las fotografías de Mercado Libre resueltas para una orden de compra.';

    public function handle(PurchaseOrderMeliImageResolver $resolver): int
    {
        $identifier = trim((string) $this->argument('order'));
        if ($identifier === '') {
            $this->error('Debes indicar el folio o ID de la orden de compra.');

            return self::FAILURE;
        }

        $deepMode = (bool) $this->option('deep');
        $timeBudgetSeconds = max(1.0, (float) ($this->option('time-budget') ?? 20));
        $startedAt = microtime(true);

        $queriesCount = 0;
        $likeQueriesCount = 0;
        $countingActive = true;

        DB::listen(static function (QueryExecuted $query) use (&$queriesCount, &$likeQueriesCount, &$countingActive): void {
            if (! $countingActive) {
                return;
            }
            $queriesCount++;
            if (stripos($query->sql, ' like ') !== false) {
                $likeQueriesCount++;
            }
        });

        try {
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

            $preloadedAccountsById = null;
            if ($deepMode) {
                $preloadedAccountsById = Schema::hasTable('meli_accounts')
                    ? MeliAccount::query()->get(['id', 'meli_user_id', 'nickname', 'is_default'])->keyBy('id')
                    : collect();
            }

            $deepDeadline = $deepMode ? microtime(true) + $timeBudgetSeconds : null;
            $deepDiscoveryTruncated = false;
            $deepDiscoveryItemsChecked = 0;
            $deepDiscoveryItemsSkippedByBudget = 0;

            $rows = [];
            $missing = [];
            $ambiguous = [];
            $pendingApprovalItems = [];
            $foundCount = 0;
            $variationIndexUsed = false;
            $variationIndexGeneration = null;
            $variationScanTruncated = false;
            $scanDiagnostics = null;

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
                    'variation_index_used' => false,
                    'variation_scan_truncated' => false,
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
                    $deepMode
                    && ! $hasPhoto
                    && $auditCategory === PurchaseOrderMeliImageResolver::AUDIT_PENDING_NO_CANDIDATE
                    && ($entry['photo_source_mode'] ?? null) !== PurchaseOrderItem::PHOTO_MODE_NONE
                ) {
                    $remainingBudget = $deepDeadline !== null ? ($deepDeadline - microtime(true)) : 0.0;
                    if ($remainingBudget <= 0.1) {
                        $deepDiscoveryTruncated = true;
                        $deepDiscoveryItemsSkippedByBudget++;
                    } else {
                        $deepDiscoveryItemsChecked++;
                        // Skip redundant per-item raw variation scans: Pass 2 already resolved all
                        // variation SKUs in bulk for the entire order.
                        $discoveryCandidates = $resolver->discoverCandidatesForItem(
                            item: $item,
                            customQuery: null,
                            limit: 5,
                            skipVariationRawScan: true,
                            preloadedAccountsById: $preloadedAccountsById,
                            maxSeconds: min(3.0, $remainingBudget),
                        );
                        if ($discoveryCandidates !== []) {
                            $auditCategory = PurchaseOrderMeliImageResolver::AUDIT_PENDING_MANUAL_APPROVAL;
                            if ($reason === 'no_listing_found') {
                                $reason = 'title_candidates_found';
                            }
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

                if (! empty($entry['variation_index_used'])) {
                    $variationIndexUsed = true;
                    if (is_int($entry['variation_index_generation'] ?? null)) {
                        $variationIndexGeneration = $entry['variation_index_generation'];
                    }
                }

                if (! empty($entry['variation_scan_truncated'])) {
                    $variationScanTruncated = true;
                    $scanDiagnostics ??= $entry['scan_diagnostics'] ?? null;
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
        } finally {
            $countingActive = false;
        }

        $durationMs = round((microtime(true) - $startedAt) * 1000, 1);
        $peakMemoryMb = round(memory_get_peak_usage(true) / 1048576, 2);

        $deepDiscoverySkipped = ! $deepMode;
        $deepDiscoveryNote = $deepDiscoverySkipped
            ? 'Modo rápido activo: se omitió el descubrimiento profundo por título/JSON para evitar escaneos repetitivos. Ejecuta con --deep en base aislada o usa el selector web por partida para buscar candidatos por título.'
            : ($deepDiscoveryTruncated
                ? sprintf('El descubrimiento profundo alcanzó el presupuesto de %.1fs (%d partidas omitidas).', $timeBudgetSeconds, $deepDiscoveryItemsSkippedByBudget)
                : null);

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
                'audit_mode' => $deepMode ? 'deep' : 'fast',
                'deep_discovery_skipped' => $deepDiscoverySkipped,
                'deep_discovery_truncated' => $deepDiscoveryTruncated,
                'deep_discovery_note' => $deepDiscoveryNote,
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
                'variation_index_used' => $variationIndexUsed,
                'variation_index_generation' => $variationIndexGeneration,
                'variation_scan_truncated' => $variationScanTruncated,
            ],
            'performance' => [
                'audit_mode' => $deepMode ? 'deep' : 'fast',
                'duration_ms' => $durationMs,
                'queries_count' => $queriesCount,
                'like_queries_count' => $likeQueriesCount,
                'peak_memory_mb' => $peakMemoryMb,
                'variation_index_used' => $variationIndexUsed,
                'variation_index_generation' => $variationIndexGeneration,
                'variation_scan_truncated' => $variationScanTruncated,
                'deep_discovery_items_checked' => $deepDiscoveryItemsChecked,
                'deep_discovery_items_skipped_by_budget' => $deepDiscoveryItemsSkippedByBudget,
            ],
            'scan_diagnostics' => $scanDiagnostics,
            'missing_skus' => $missing,
            'ambiguous_matches' => $ambiguous,
            'pending_manual_approval_items' => $pendingApprovalItems,
            'items' => $rows,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $modeLabel = $deepMode ? 'EXHAUSTIVO (--deep)' : 'RÁPIDO (sin búsqueda profunda por título)';
        $this->info("Auditoría de fotografías ML (solo lectura, modo {$modeLabel}) — {$order->order_number} (ID #{$order->id})");
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
        $this->line(sprintf(
            'Rendimiento -> %.1f ms | %d consultas SQL (%d LIKE) | Memoria pico: %.2f MiB | Índice SKU variaciones: %s',
            $durationMs,
            $queriesCount,
            $likeQueriesCount,
            $peakMemoryMb,
            $variationIndexUsed ? 'ACTIVO' : 'FALLBACK RAW',
        ));

        if ($deepDiscoveryNote !== null) {
            $this->comment($deepDiscoveryNote);
        }

        if ($variationScanTruncated) {
            $this->warn('ADVERTENCIA: El escaneo paginado de variaciones en raw alcanzó el techo operativo y pudo truncar coincidencias. Revisa scan_diagnostics en --json.');
        }

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
