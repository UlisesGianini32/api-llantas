<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeliClaimMessageRequest;
use App\Models\MeliClaim;
use App\Services\MercadoLibre\Claims\MeliClaimMessageSender;
use Illuminate\Http\RedirectResponse;

class MeliClaimMessageController extends Controller
{
    public function store(StoreMeliClaimMessageRequest $request, MeliClaim $claim, MeliClaimMessageSender $sender): RedirectResponse
    {
        $result = $sender->send(
            $request->user(),
            $claim,
            (string) $request->validated('message'),
            collect($request->file('attachments', []))
        );

        if (! $result['ok']) {
            return redirect()->route('meli.claims.show', $claim)->with('err', $result['error']);
        }

        if ($result['refresh_failed']) {
            return redirect()->route('meli.claims.show', $claim)
                ->with('err', 'Mensaje enviado, pero no fue posible actualizar la conversación. Actualiza el reclamo.');
        }

        return redirect()->route('meli.claims.show', $claim)->with('ok', 'Mensaje enviado correctamente.');
    }
}
