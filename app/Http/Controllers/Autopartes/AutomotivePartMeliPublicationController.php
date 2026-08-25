<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\IndexAutomotivePartMeliPublicationRequest;
use App\Models\AutomotivePartMeliPublication;
use App\Models\AutomotivePartMeliDraft;
use App\Models\MeliAccount;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class AutomotivePartMeliPublicationController extends Controller
{
    public function index(IndexAutomotivePartMeliPublicationRequest $request, AutomotivePartMeliPublisherConfiguration $configuration): Response
    {
        $query = AutomotivePartMeliPublication::query()->with(['automotivePart:id,item_number,manufacturer_part_number,vendor',
            'draft:id,title,version,status', 'account:id,meli_user_id,nickname', 'finalApprover:id,name']);
        if ($request->filled('q')) { $search = '%'.$request->string('q').'%'; $query->where(fn (Builder $builder) => $builder
            ->where('meli_item_id', 'like', $search)->orWhere('request_fingerprint', 'like', $search)
            ->orWhereHas('draft', fn ($draft) => $draft->where('title', 'like', $search))); }
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        return Inertia::render('Autopartes/PublicacionesMeli', ['publications' => $query->latest('id')->paginate((int) $request->input('per_page', 25))->withQueryString(),
            'filters' => $request->validated(), 'publisher' => $configuration->publicSettings(),
            'accounts' => MeliAccount::query()->orderByDesc('is_default')->orderBy('id')->get(['id', 'meli_user_id', 'nickname']),
            'approvedDrafts' => AutomotivePartMeliDraft::query()->where('status', 'approved')->latest('id')->limit(100)->get(['id', 'automotive_part_id', 'title', 'version', 'fingerprint'])]);
    }

    public function show(AutomotivePartMeliPublication $publication, AutomotivePartMeliPublisherConfiguration $configuration): Response
    {
        $publication->load(['automotivePart', 'draft', 'account:id,meli_user_id,nickname', 'finalApprover:id,name',
            'pictureUploads.media:id,original_name,sha256,position,is_primary,status', 'attempts', 'events.user:id,name']);
        return Inertia::render('Autopartes/PublicacionMeliDetalle', ['publication' => $publication, 'publisher' => $configuration->publicSettings()]);
    }
}
