<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\ApproveAutomotivePartMeliCategoryCandidateRequest;
use App\Http\Requests\Autopartes\BatchAutomotivePartMeliCategoriesRequest;
use App\Http\Requests\Autopartes\ConfirmAutomotivePartMeliReadinessRequest;
use App\Http\Requests\Autopartes\RefreshAutomotivePartMeliCategoryRequest;
use App\Http\Requests\Autopartes\RejectAutomotivePartMeliCategoryCandidateRequest;
use App\Http\Requests\Autopartes\SearchAutomotivePartMeliCategoriesRequest;
use App\Http\Requests\Autopartes\StoreManualAutomotivePartMeliCategoryRequest;
use App\Jobs\MapAutomotivePartToMeliCategoriesJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliCategoryCandidate;
use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySuggestionService;
use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySyncService;
use App\Services\Autopartes\Meli\AutomotivePartMeliConfiguration;
use App\Services\Autopartes\Meli\AutomotivePartMeliException;
use App\Services\Autopartes\Meli\AutomotivePartMeliReadinessService;
use App\Services\Autopartes\Meli\AutomotivePartMeliRequestBudget;
use App\Services\Autopartes\Meli\AutomotivePartMeliReviewService;
use App\Services\Autopartes\Meli\AutomotivePartMeliTokenProvider;
use Illuminate\Http\RedirectResponse;

class AutomotivePartMeliCategoryActionController extends Controller
{
    public function search(
        SearchAutomotivePartMeliCategoriesRequest $request,
        AutomotivePart $automotivePart,
        AutomotivePartMeliCategorySuggestionService $suggestions,
        AutomotivePartMeliConfiguration $configuration,
        AutomotivePartMeliTokenProvider $tokens,
    ): RedirectResponse {
        try {
            $configuration->assertReady();
            $tokens->token();
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        $preview = $suggestions->preview($automotivePart);
        $fingerprint = $this->fingerprint($automotivePart, $preview);
        MapAutomotivePartToMeliCategoriesJob::dispatch(
            $automotivePart->id,
            $fingerprint,
            $request->boolean('refresh_metadata'),
            $request->boolean('force'),
        );

        return back()->with('success', 'La búsqueda de categorías se encoló sin crear publicaciones.');
    }

    public function batch(
        BatchAutomotivePartMeliCategoriesRequest $request,
        AutomotivePartMeliCategorySuggestionService $suggestions,
        AutomotivePartMeliConfiguration $configuration,
        AutomotivePartMeliTokenProvider $tokens,
        AutomotivePartMeliRequestBudget $budget,
    ): RedirectResponse {
        $data = $request->validated();
        try {
            $configuration->assertReady();
            $tokens->token();
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }
        $parts = AutomotivePart::query()
            ->with('enrichmentReview')
            ->when(filled($data['internal_category'] ?? null), fn ($query) => $query->where('category', $data['internal_category']))
            ->when(! ($data['force'] ?? false), fn ($query) => $query->whereDoesntHave('meliCategoryCandidates', fn ($candidate) => $candidate->where('status', 'approved')))
            ->orderBy('id')->limit((int) $data['limit'])->get();

        $queued = 0;
        foreach ($parts as $part) {
            if ($budget->remaining() < 1) {
                break;
            }
            $preview = $suggestions->preview($part);
            $fingerprint = $this->fingerprint($part, $preview);
            MapAutomotivePartToMeliCategoriesJob::dispatch($part->id, $fingerprint, (bool) ($data['refresh_metadata'] ?? false), (bool) ($data['force'] ?? false));
            $queued++;
        }

        return back()->with('success', $queued.' autopartes encoladas para mapeo de categorías.');
    }

    public function approve(
        ApproveAutomotivePartMeliCategoryCandidateRequest $request,
        AutomotivePartMeliCategoryCandidate $candidate,
        AutomotivePartMeliReviewService $service,
    ): RedirectResponse {
        try {
            $service->approve($candidate, $request->user(), $request->validated('review_notes'), $request->boolean('refresh_metadata'));
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        return back()->with('success', 'Categoría aprobada y readiness recalculado; no se publicó ningún artículo.');
    }

    public function reject(
        RejectAutomotivePartMeliCategoryCandidateRequest $request,
        AutomotivePartMeliCategoryCandidate $candidate,
        AutomotivePartMeliReviewService $service,
    ): RedirectResponse {
        try {
            $service->reject($candidate, $request->user(), $request->validated('review_notes'));
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        return back()->with('success', 'Candidato rechazado y conservado en el historial.');
    }

    public function manual(
        StoreManualAutomotivePartMeliCategoryRequest $request,
        AutomotivePart $automotivePart,
        AutomotivePartMeliReviewService $service,
    ): RedirectResponse {
        try {
            $service->createManualCandidate(
                $automotivePart,
                $request->validated('category_id'),
                $request->user(),
                $request->validated('review_notes'),
                $request->boolean('refresh_metadata'),
            );
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        return back()->with('success', 'category_id validado y guardado como candidato manual pendiente.');
    }

    public function refresh(
        RefreshAutomotivePartMeliCategoryRequest $request,
        AutomotivePartMeliCategoryCandidate $candidate,
        AutomotivePartMeliCategorySyncService $service,
    ): RedirectResponse {
        try {
            $service->syncAttributes($candidate->category_id, true);
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        return back()->with('success', 'Metadatos oficiales actualizados sin publicar.');
    }

    public function recalculate(AutomotivePart $automotivePart, AutomotivePartMeliReadinessService $service): RedirectResponse
    {
        try {
            $service->evaluate($automotivePart);
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        return back()->with('success', 'Readiness recalculado.');
    }

    public function confirm(
        ConfirmAutomotivePartMeliReadinessRequest $request,
        AutomotivePart $automotivePart,
        AutomotivePartMeliReadinessService $service,
    ): RedirectResponse {
        try {
            $service->confirmReady($automotivePart, $request->user(), $request->validated('review_notes'));
        } catch (AutomotivePartMeliException $exception) {
            return back()->withErrors(['meli' => $exception->getMessage()]);
        }

        return back()->with('success', 'Preparación validada por una persona. Esto no publicó ningún artículo.');
    }

    private function fingerprint(AutomotivePart $part, array $preview): string
    {
        return hash('sha256', json_encode([
            'part_id' => $part->id,
            'part_updated_at' => $part->updated_at?->toJSON(),
            'review_updated_at' => $part->enrichmentReview?->updated_at?->toJSON(),
            'query' => $preview['query'],
            'rules_version' => $preview['rules_version'],
        ], JSON_THROW_ON_ERROR));
    }
}
