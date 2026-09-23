<?php

namespace App\Http\Controllers;

use App\Models\LlantaComparisonDecision;
use App\Services\Llantas\LlantaComparisonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LlantaComparisonController extends Controller
{
    private const STATUSES = ['pending', 'same', 'different', 'ignored'];

    public function index(Request $request, LlantaComparisonService $comparison): Response
    {
        $status = trim((string) $request->input('status', 'pending'));
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $search = trim((string) $request->input('search', ''));
        $marca = trim((string) $request->input('marca', ''));
        $medida = trim((string) $request->input('medida', ''));
        $minimumScore = max(0, min(100, (float) $request->input('min_score', 0)));
        $onlyDifferentSku = $request->boolean('different_sku');
        $sort = (string) $request->input('sort', 'score');
        $sort = in_array($sort, ['score', 'recent'], true) ? $sort : 'score';
        $perPage = (int) $request->input('per_page', 20);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;

        $query = LlantaComparisonDecision::query()
            ->join('llantas as llanta_a', 'llanta_a.id', '=', 'llanta_comparison_decisions.llanta_a_id')
            ->join('llantas as llanta_b', 'llanta_b.id', '=', 'llanta_comparison_decisions.llanta_b_id')
            ->with(['llantaA', 'llantaB'])
            ->select('llanta_comparison_decisions.*')
            ->when($status !== '', fn ($q) => $q->where('llanta_comparison_decisions.status', $status))
            ->when($minimumScore > 0, fn ($q) => $q->where('llanta_comparison_decisions.score', '>=', $minimumScore))
            ->when($marca !== '', function ($q) use ($marca): void {
                $q->where(function ($nested) use ($marca): void {
                    $nested->where('llanta_a.marca', 'like', '%'.$marca.'%')
                        ->orWhere('llanta_b.marca', 'like', '%'.$marca.'%');
                });
            })
            ->when($medida !== '', function ($q) use ($medida): void {
                $q->where(function ($nested) use ($medida): void {
                    $nested->where('llanta_a.medida', 'like', '%'.$medida.'%')
                        ->orWhere('llanta_b.medida', 'like', '%'.$medida.'%');
                });
            })
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($nested) use ($search): void {
                    foreach (['llanta_a', 'llanta_b'] as $alias) {
                        $nested->orWhere($alias.'.sku', 'like', '%'.$search.'%')
                            ->orWhere($alias.'.marca', 'like', '%'.$search.'%')
                            ->orWhere($alias.'.medida', 'like', '%'.$search.'%')
                            ->orWhere($alias.'.descripcion', 'like', '%'.$search.'%')
                            ->orWhere($alias.'.title_familyname', 'like', '%'.$search.'%');
                    }
                });
            })
            ->when($onlyDifferentSku, fn ($q) => $q->whereColumn('llanta_a.sku', '<>', 'llanta_b.sku'))
            ->orderByDesc('llanta_comparison_decisions.'.($sort === 'recent' ? 'last_detected_at' : 'score'))
            ->orderBy('llanta_comparison_decisions.id');

        $comparisons = $query->paginate($perPage)->withQueryString()->through(
            fn (LlantaComparisonDecision $decision): array => $this->present($decision, $comparison)
        );

        $visibleStats = fn () => LlantaComparisonDecision::query()
            ->join('llantas as stats_llanta_a', 'stats_llanta_a.id', '=', 'llanta_comparison_decisions.llanta_a_id')
            ->join('llantas as stats_llanta_b', 'stats_llanta_b.id', '=', 'llanta_comparison_decisions.llanta_b_id');

        $stats = [
            'pending' => $visibleStats()->where('llanta_comparison_decisions.status', 'pending')->count(),
            'high' => $visibleStats()->where('llanta_comparison_decisions.score', '>=', (float) config('llantas.duplicate_detector.exact_score', 94))->count(),
            'same' => $visibleStats()->where('llanta_comparison_decisions.status', 'same')->count(),
            'different' => $visibleStats()->where('llanta_comparison_decisions.status', 'different')->count(),
        ];

        return Inertia::render('Llantas/Comparador', [
            'comparisons' => $comparisons,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'marca' => $marca,
                'medida' => $medida,
                'min_score' => $minimumScore > 0 ? $minimumScore : '',
                'status' => $status,
                'different_sku' => $onlyDifferentSku,
                'sort' => $sort,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function decide(Request $request, LlantaComparisonDecision $comparison): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', self::STATUSES)],
        ]);

        $status = (string) $validated['status'];
        $comparison->forceFill([
            'status' => $status,
            'decided_by' => $status === 'pending' ? null : $request->user()->id,
            'decided_at' => $status === 'pending' ? null : now(),
        ])->save();

        return back()->with('success', 'Decisión guardada.');
    }

    private function present(LlantaComparisonDecision $decision, LlantaComparisonService $comparison): array
    {
        return [
            'id' => $decision->id,
            'score' => (float) $decision->score,
            'reasons' => $decision->reasons ?? [],
            'differences' => $decision->differences ?? [],
            'status' => $decision->status,
            'decided_at' => $decision->decided_at?->toIso8601String(),
            'last_detected_at' => $decision->last_detected_at?->toIso8601String(),
            'a' => $this->presentLlanta($decision->llantaA, $comparison),
            'b' => $this->presentLlanta($decision->llantaB, $comparison),
        ];
    }

    private function presentLlanta($llanta, LlantaComparisonService $comparison): array
    {
        if (! $llanta) {
            return [
                'id' => null,
                'sku' => null,
                'deleted' => true,
            ];
        }

        $parsed = $comparison->parse($llanta);

        return [
            'id' => $llanta->id,
            'sku' => $llanta->sku,
            'marca' => $llanta->marca,
            'medida' => $llanta->medida,
            'descripcion' => $llanta->descripcion,
            'title_familyname' => $llanta->title_familyname,
            'stock' => (int) $llanta->stock,
            'costo' => $llanta->costo !== null ? (float) $llanta->costo : null,
            'precio_ML' => $llanta->precio_ML !== null ? (float) $llanta->precio_ML : null,
            'MLM' => $llanta->MLM,
            'technical' => $comparison->technicalAttributes($parsed),
        ];
    }
}
