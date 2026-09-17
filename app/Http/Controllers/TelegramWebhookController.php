<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramOperationsService $operations): JsonResponse
    {
        $secret = (string) env('TELEGRAM_WEBHOOK_SECRET');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $operations->handle($request->all());

        return response()->json(['ok' => true]);
    }
}
