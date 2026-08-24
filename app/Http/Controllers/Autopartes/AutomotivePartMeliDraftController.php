<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\IndexAutomotivePartMeliDraftHistoryRequest;
use App\Http\Requests\Autopartes\IndexAutomotivePartMeliDraftRequest;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliDraft;
use App\Services\Autopartes\Drafts\AutomotivePartDraftBuilder;
use App\Services\Autopartes\Drafts\AutomotivePartDraftConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class AutomotivePartMeliDraftController extends Controller
{
    public function index(
        IndexAutomotivePartMeliDraftRequest $request,
        AutomotivePartDraftConfiguration $configuration,
    ): Response {
        $query = AutomotivePart::query()->with(['latestMeliDraft.reviewer']);
        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(fn (Builder $builder) => $builder
                ->where('item_number', 'like', $search)
                ->orWhere('manufacturer_part_number', 'like', $search)
                ->orWhere('vendor', 'like', $search)
                ->orWhereHas('meliDrafts', fn ($draft) => $draft->where('title', 'like', $search)));
        }
        if ($request->input('status') === 'not_generated') {
            $query->whereDoesntHave('meliDrafts');
        } elseif ($request->filled('status')) {
            $query->whereHas('latestMeliDraft', fn ($draft) => $draft->where('status', $request->input('status')));
        }
        if ($request->filled('error')) {
            $needle = '%"code":"'.$request->input('error').'"%';
            $query->whereHas('latestMeliDraft', fn ($draft) => $draft->where('blocking_errors', 'like', $needle));
        }

        $latestIds = AutomotivePartMeliDraft::query()
            ->selectRaw('MAX(id)')
            ->groupBy('automotive_part_id');
        $statusTotals = AutomotivePartMeliDraft::query()
            ->whereIn('id', $latestIds)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Autopartes/BorradoresMeli', [
            'parts' => $query->orderBy('id')->paginate((int) $request->input('per_page', 25))->withQueryString(),
            'filters' => $request->validated(),
            'statusTotals' => $statusTotals,
            'drafts' => $configuration->publicSettings(),
        ]);
    }

    public function show(
        AutomotivePart $automotivePart,
        AutomotivePartDraftBuilder $builder,
        AutomotivePartDraftConfiguration $configuration,
    ): Response {
        $automotivePart->load([
            'enrichmentReview',
            'meliReadiness.approvedCategoryCandidate',
            'meliDrafts' => fn ($query) => $query->with(['reviewer', 'events.user'])->orderByDesc('version'),
        ]);

        return Inertia::render('Autopartes/BorradorMeliDetalle', [
            'part' => $automotivePart,
            'preview' => $builder->preview($automotivePart),
            'drafts' => $configuration->publicSettings(),
        ]);
    }

    public function history(
        IndexAutomotivePartMeliDraftHistoryRequest $request,
        AutomotivePartMeliDraft $draft,
    ): JsonResponse {
        return response()->json($draft->events()->with('user:id,name')->paginate((int) $request->input('per_page', 25)));
    }
}
