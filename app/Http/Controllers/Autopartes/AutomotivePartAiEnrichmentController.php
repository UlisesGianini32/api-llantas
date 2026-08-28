<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\BatchAutomotivePartAiEnrichmentRequest;
use App\Http\Requests\Autopartes\GenerateAutomotivePartAiEnrichmentRequest;
use App\Http\Requests\Autopartes\IndexAutomotivePartAiHistoryRequest;
use App\Http\Requests\Autopartes\RegenerateAutomotivePartAiEnrichmentRequest;
use App\Models\AutomotivePartEnrichmentReview;
use App\Services\Autopartes\Ai\AutomotivePartAiDispatchService;
use App\Services\Autopartes\Ai\AutomotivePartAiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AutomotivePartAiEnrichmentController extends Controller
{
    public function generate(
        GenerateAutomotivePartAiEnrichmentRequest $request,
        AutomotivePartEnrichmentReview $review,
        AutomotivePartAiDispatchService $dispatcher,
    ): RedirectResponse {
        return $this->dispatchOne($review, $dispatcher, false);
    }

    public function regenerate(
        RegenerateAutomotivePartAiEnrichmentRequest $request,
        AutomotivePartEnrichmentReview $review,
        AutomotivePartAiDispatchService $dispatcher,
    ): RedirectResponse {
        return $this->dispatchOne($review, $dispatcher, true);
    }

    public function batch(
        BatchAutomotivePartAiEnrichmentRequest $request,
        AutomotivePartAiDispatchService $dispatcher,
    ): RedirectResponse {
        $data = $request->validated();

        try {
            $stats = $dispatcher->dispatchBatch(
                (int) $data['limit'],
                issue: $data['issue'] ?? null,
                force: (bool) ($data['force'] ?? false),
            );
        } catch (AutomotivePartAiException $exception) {
            return back()->withErrors(['ai' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Lote de IA preparado: %d encolados, %d omitidos y %d errores.',
            $stats['queued'],
            $stats['skipped'],
            $stats['errors'],
        ));
    }

    public function history(
        IndexAutomotivePartAiHistoryRequest $request,
        AutomotivePartEnrichmentReview $review,
    ): JsonResponse {
        $runs = $review->aiRuns()
            ->latest()
            ->paginate((int) $request->validated('per_page', 20));

        return response()->json($runs);
    }

    private function dispatchOne(
        AutomotivePartEnrichmentReview $review,
        AutomotivePartAiDispatchService $dispatcher,
        bool $force,
    ): RedirectResponse {
        try {
            $result = $dispatcher->dispatchReview($review, $force);
        } catch (AutomotivePartAiException $exception) {
            return back()->withErrors(['ai' => $exception->getMessage()]);
        }

        if (! $result['queued']) {
            return back()->withErrors(['ai' => $result['message']]);
        }

        return back()->with('success', 'La propuesta fue encolada; permanecerá pendiente de aprobación humana.');
    }
}
