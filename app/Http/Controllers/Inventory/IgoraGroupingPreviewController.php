<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\MeliAccount;
use App\Services\Inventory\Igora\IgoraGroupingAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IgoraGroupingPreviewController extends Controller
{
    public const DEFAULT_PER_PAGE = 50;

    /** @var list<int> */
    public const ALLOWED_PER_PAGE = [25, 50, 100, 250];

    public function index(Request $request, IgoraGroupingAssistantService $assistant): Response
    {
        abort_unless($request->user()?->canOperate() ?? false, 403);

        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $filters = [
            ...$request->only([
                'search',
                'line',
                'tone',
                'presentation',
                'status',
                'account_key',
                'ambiguous_only',
            ]),
            'per_page' => $perPage,
            'page' => max(1, (int) $request->integer('page', 1)),
            'pagination_path' => $request->url(),
        ];

        $preview = $assistant->preview($filters);

        $accounts = Schema::hasTable('meli_accounts')
            ? MeliAccount::query()->orderBy('nickname')->get(['id', 'nickname', 'meli_user_id'])
            : collect();

        return Inertia::render('Inventory/Channels/IgoraRoyalPreview', [
            'preview' => $preview,
            'accounts' => $accounts,
            'lineLabels' => IgoraGroupingAssistantService::LINE_LABELS,
            'presentationLabels' => IgoraGroupingAssistantService::PRESENTATION_LABELS,
            'canManage' => $request->user()?->isAdmin() ?? false,
        ]);
    }

    public function export(Request $request, IgoraGroupingAssistantService $assistant): StreamedResponse
    {
        abort_unless($request->user()?->canOperate() ?? false, 403);

        $filters = $request->only([
            'search',
            'line',
            'tone',
            'presentation',
            'status',
            'account_key',
            'ambiguous_only',
        ]);

        $preview = $assistant->preview($filters);
        $rows = $preview['rows'];
        $filename = 'igora-royal-agrupador-preview-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, array_map([$this, 'sanitizeCsvCell'], [
                'Línea',
                'Tono Normalizado',
                'SKU',
                'Presentación',
                'Publicación',
                'Variación',
                'Cuenta MeLi',
                'Account ID',
                'MLM',
                'Variation ID',
                'User Product ID',
                'Es FULL',
                'Estado ML',
                'Moderación/Denuncia',
                'Último Snapshot',
                'Stock Local Físico (Inventory)',
                'Stock Local Disponible (Inventory)',
                'Stock Remoto Publicación',
                'Stock Remoto Price Manager',
                'Grupo Propuesto',
                'Coincidencia Propuesta',
                'Inventory Product ID Propuesto',
                'Componentes Candidatos',
                'Requiere Revisión Humana',
                'Confianza',
                'Conflictos',
                'Acción Recomendada',
            ]));

            foreach ($rows as $row) {
                $componentsText = collect((array) ($row['candidate_components'] ?? []))
                    ->map(function (array $c): string {
                        $qty = $c['quantity'] ?? '?';
                        $sku = $c['sku'] ?? 'sin-sku';

                        return "{$c['role']}: {$c['name']} ({$sku}) x{$qty}";
                    })
                    ->implode(' | ');

                fputcsv($handle, array_map([$this, 'sanitizeCsvCell'], [
                    $row['line_label'] ?? $row['line'],
                    $row['normalized_tone'] ?? '—',
                    $row['sku'] !== '' ? $row['sku'] : '—',
                    $row['presentation_label'] ?? $row['presentation_type'],
                    $row['title'] ?? '—',
                    $row['variation_label'] ?? '—',
                    $row['account_name'] ?? '—',
                    $row['account_id'] ?? '—',
                    $row['mlm'] ?? '—',
                    $row['variation_id'] ?? '—',
                    $row['user_product_id'] ?? '—',
                    ! empty($row['is_full']) ? 'SÍ' : 'NO',
                    $row['status'] ?? '—',
                    ! empty($row['is_moderated_or_denounced']) ? implode(',', (array) ($row['moderation_signals'] ?? [])) : 'NO',
                    $row['snapshot_at_human'] ?? '—',
                    $row['local_physical_stock'] !== null ? $row['local_physical_stock'] : 'Sin producto Inventory',
                    $row['local_available_stock'] !== null ? $row['local_available_stock'] : 'Sin producto Inventory',
                    $row['remote_publication_stock'] !== null ? $row['remote_publication_stock'] : '—',
                    $row['remote_price_manager_stock'] !== null ? $row['remote_price_manager_stock'] : '—',
                    $row['proposed_group_key'] ?? '—',
                    $row['proposed_match_label'] ?? '—',
                    $row['proposed_inventory_product_id'] ?? '—',
                    $componentsText !== '' ? $componentsText : '—',
                    ! empty($row['requires_human_review']) ? 'SÍ' : 'NO',
                    $row['confidence'] ?? '—',
                    implode(', ', (array) ($row['conflicts'] ?? [])) ?: '—',
                    $row['recommended_action'] ?? '—',
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Neutraliza inyección de fórmulas CSV (=, +, -, @, tabulador, retorno de carro)
     * proveniente de títulos, atributos o identificadores externos de Mercado Libre.
     */
    private function sanitizeCsvCell(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = str_replace(["\r\n", "\n"], ' ', (string) $value);
        if ($text !== '' && preg_match('/^(?:[=+\-@\t\r]|\s+[=+\-@])/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}
