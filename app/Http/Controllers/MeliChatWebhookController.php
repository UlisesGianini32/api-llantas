<?php

namespace App\Http\Controllers;

use App\Services\MeliMenuAutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MeliChatWebhookController extends Controller
{
    public function __invoke(Request $request, MeliMenuAutomationService $service)
    {
        $payload = $request->all();

        Log::info('Webhook MeliChat recibido', [
            'payload' => $payload,
        ]);

        $userId = $request->integer('user_id') ?: null;

        $service->handleIncomingEvent($payload, $userId);

        return response()->json([
            'ok' => true,
        ]);
    }
}