<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\RejectAutomotivePartMediaRequest;
use App\Http\Requests\Autopartes\ReorderAutomotivePartMediaRequest;
use App\Http\Requests\Autopartes\StoreAutomotivePartMediaRequest;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMedia;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutomotivePartMediaActionController extends Controller
{
    public function store(StoreAutomotivePartMediaRequest $request, AutomotivePart $automotivePart, AutomotivePartMediaService $service): RedirectResponse
    {
        $service->upload($automotivePart, $request->file('image'), $request->string('provenance_type')->toString(), $request->input('provenance_reference'), $request->input('notes'), $request->user());
        return back()->with('success', 'Imagen respaldada y pendiente de aprobación.');
    }
    public function approve(Request $request, AutomotivePartMedia $media, AutomotivePartMediaService $service): RedirectResponse { $service->approve($media, $request->user()); return back()->with('success', 'Imagen aprobada.'); }
    public function reject(RejectAutomotivePartMediaRequest $request, AutomotivePartMedia $media, AutomotivePartMediaService $service): RedirectResponse { $service->reject($media, $request->user(), $request->string('notes')->toString()); return back()->with('success', 'Imagen rechazada sin eliminar el archivo.'); }
    public function archive(Request $request, AutomotivePartMedia $media, AutomotivePartMediaService $service): RedirectResponse { $service->archive($media, $request->user(), $request->input('notes')); return back()->with('success', 'Imagen archivada sin eliminar el archivo.'); }
    public function primary(Request $request, AutomotivePartMedia $media, AutomotivePartMediaService $service): RedirectResponse { $service->setPrimary($media, $request->user()); return back()->with('success', 'Imagen principal actualizada.'); }
    public function reorder(ReorderAutomotivePartMediaRequest $request, AutomotivePart $automotivePart, AutomotivePartMediaService $service): RedirectResponse { $service->reorder($automotivePart, $request->validated('media_ids'), $request->user()); return back()->with('success', 'Orden actualizado.'); }
}
