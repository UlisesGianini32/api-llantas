<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\ApproveAutomotivePartMeliDraftRequest;
use App\Http\Requests\Autopartes\GenerateAutomotivePartMeliDraftRequest;
use App\Http\Requests\Autopartes\RejectAutomotivePartMeliDraftRequest;
use App\Http\Requests\Autopartes\ReturnAutomotivePartMeliDraftToPendingRequest;
use App\Jobs\GenerateAutomotivePartMeliDraftJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliDraft;
use App\Services\Autopartes\Drafts\AutomotivePartDraftBuilder;
use App\Services\Autopartes\Drafts\AutomotivePartDraftConfiguration;
use App\Services\Autopartes\Drafts\AutomotivePartDraftException;
use App\Services\Autopartes\Drafts\AutomotivePartDraftReviewService;
use Illuminate\Http\RedirectResponse;

class AutomotivePartMeliDraftActionController extends Controller
{
    public function generate(
        GenerateAutomotivePartMeliDraftRequest $request,
        AutomotivePart $automotivePart,
        AutomotivePartDraftConfiguration $configuration,
        AutomotivePartDraftBuilder $builder,
    ): RedirectResponse {
        try {
            $configuration->assertEnabled();
        } catch (AutomotivePartDraftException $exception) {
            return back()->withErrors(['draft' => $exception->getMessage()]);
        }
        $preview = $builder->preview($automotivePart);
        GenerateAutomotivePartMeliDraftJob::dispatch(
            $automotivePart->id,
            $preview['fingerprint'],
            $request->boolean('force'),
        );

        return back()->with('success', 'Generación encolada. Es un borrador interno y no se publicó en Mercado Libre.');
    }

    public function regenerate(
        GenerateAutomotivePartMeliDraftRequest $request,
        AutomotivePartMeliDraft $draft,
        AutomotivePartDraftConfiguration $configuration,
        AutomotivePartDraftBuilder $builder,
    ): RedirectResponse {
        try {
            $configuration->assertEnabled();
        } catch (AutomotivePartDraftException $exception) {
            return back()->withErrors(['draft' => $exception->getMessage()]);
        }
        $part = $draft->automotivePart()->firstOrFail();
        $preview = $builder->preview($part);
        GenerateAutomotivePartMeliDraftJob::dispatch($part->id, $preview['fingerprint'], true);

        return back()->with('success', 'Regeneración encolada sin realizar solicitudes externas.');
    }

    public function approve(
        ApproveAutomotivePartMeliDraftRequest $request,
        AutomotivePartMeliDraft $draft,
        AutomotivePartDraftReviewService $reviews,
    ): RedirectResponse {
        try {
            $reviews->approve($draft, $request->user(), $request->validated('review_notes'));
        } catch (AutomotivePartDraftException $exception) {
            return back()->withErrors(['draft' => $exception->getMessage()]);
        }

        return back()->with('success', 'Borrador aprobado internamente. No se publicó en Mercado Libre.');
    }

    public function reject(
        RejectAutomotivePartMeliDraftRequest $request,
        AutomotivePartMeliDraft $draft,
        AutomotivePartDraftReviewService $reviews,
    ): RedirectResponse {
        try {
            $reviews->reject($draft, $request->user(), $request->validated('review_notes'));
        } catch (AutomotivePartDraftException $exception) {
            return back()->withErrors(['draft' => $exception->getMessage()]);
        }

        return back()->with('success', 'Borrador rechazado; la decisión quedó en el historial.');
    }

    public function pending(
        ReturnAutomotivePartMeliDraftToPendingRequest $request,
        AutomotivePartMeliDraft $draft,
        AutomotivePartDraftReviewService $reviews,
    ): RedirectResponse {
        try {
            $reviews->returnToPending($draft, $request->user(), $request->validated('review_notes'));
        } catch (AutomotivePartDraftException $exception) {
            return back()->withErrors(['draft' => $exception->getMessage()]);
        }

        return back()->with('success', 'El borrador volvió al flujo de revisión interna.');
    }
}
