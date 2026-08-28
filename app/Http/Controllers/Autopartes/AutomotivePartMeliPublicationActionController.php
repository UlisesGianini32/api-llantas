<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\ApproveAutomotivePartMeliPublicationRequest;
use App\Http\Requests\Autopartes\AutomotivePartMeliPublicationActionRequest;
use App\Http\Requests\Autopartes\CreateAutomotivePartMeliPreflightRequest;
use App\Http\Requests\Autopartes\EnqueueAutomotivePartMeliPublicationRequest;
use App\Http\Requests\Autopartes\NoteAutomotivePartMeliPublicationRequest;
use App\Http\Requests\Autopartes\ReconcileAutomotivePartMeliPublicationRequest;
use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliPublication;
use App\Models\MeliAccount;
use App\Services\Autopartes\Publisher\AutomotivePartMeliDescriptionService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliFinalApprovalService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPictureUploadService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublicationPreflight;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublicationWorkflow;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherException;
use App\Services\Autopartes\Publisher\AutomotivePartMeliReconciliationService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliRemoteValidationService;
use Illuminate\Http\RedirectResponse;

class AutomotivePartMeliPublicationActionController extends Controller
{
    public function preflight(CreateAutomotivePartMeliPreflightRequest $request, AutomotivePartMeliPublicationPreflight $service): RedirectResponse
    {
        return $this->run(function () use ($request, $service) {
            $publication = $service->create(AutomotivePartMeliDraft::query()->findOrFail($request->integer('draft_id')),
                MeliAccount::query()->findOrFail($request->integer('meli_account_id')), $request->user());
            return redirect()->route('autopartes.meli.publications.show', $publication)->with('success', 'Preflight local creado sin solicitudes HTTP.');
        });
    }
    public function regenerate(AutomotivePartMeliPublicationActionRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliPublicationPreflight $service): RedirectResponse
    { return $this->run(fn () => tap($service->create($publication->draft, $publication->account, $request->user()), fn () => null), 'Preflight regenerado sin HTTP.'); }
    public function upload(AutomotivePartMeliPublicationActionRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliPictureUploadService $service): RedirectResponse
    { return $this->run(fn () => $service->upload($publication, $request->user()), 'Imágenes cargadas; no se validó ni publicó.'); }
    public function validateRemote(AutomotivePartMeliPublicationActionRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliRemoteValidationService $service): RedirectResponse
    { return $this->run(fn () => $service->validate($publication, $request->user()), 'Validación remota aprobada; no se publicó.'); }
    public function approve(ApproveAutomotivePartMeliPublicationRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliFinalApprovalService $service): RedirectResponse
    { return $this->run(fn () => $service->approve($publication, $request->user(), $request->validated()), 'Aprobación final registrada localmente; no se realizó HTTP.'); }
    public function revoke(NoteAutomotivePartMeliPublicationRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliFinalApprovalService $service): RedirectResponse
    { return $this->run(fn () => $service->revoke($publication, $request->user(), $request->string('note')->toString()), 'Aprobación final revocada.'); }
    public function enqueue(EnqueueAutomotivePartMeliPublicationRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliPublicationWorkflow $service): RedirectResponse
    { return $this->run(fn () => $service->enqueue($publication, $request->user()), 'Publicación individual encolada.'); }
    public function retryDescription(AutomotivePartMeliPublicationActionRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliDescriptionService $service): RedirectResponse
    { return $this->run(fn () => $service->create($publication, true, $request->user()), 'Descripción reconciliada o creada sin repetir POST /items.'); }
    public function reconcile(ReconcileAutomotivePartMeliPublicationRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliReconciliationService $service): RedirectResponse
    { return $this->run(fn () => $service->resolve($publication, $request->user(), $request->string('resolution')->toString(), $request->validated('meli_item_id'), $request->string('note')->toString()), 'Reconciliación resuelta y auditada.'); }
    public function cancel(NoteAutomotivePartMeliPublicationRequest $request, AutomotivePartMeliPublication $publication, AutomotivePartMeliPublicationWorkflow $service): RedirectResponse
    { return $this->run(fn () => $service->cancel($publication, $request->user(), $request->string('note')->toString()), 'Preflight cancelado antes de publicar.'); }

    private function run(callable $callback, ?string $message = null): RedirectResponse
    {
        try { $result = $callback(); if ($result instanceof RedirectResponse) return $result; return back()->with('success', $message); }
        catch (AutomotivePartMeliPublisherException $exception) { return back()->withErrors(['publication' => $exception->getMessage()]); }
    }
}
