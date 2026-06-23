<?php

namespace App\Http\Controllers;

use App\Jobs\ImportExcelFromTelegramJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1) Validar secret (seguridad)
        $secretHeader = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        $secretEnv    = (string) env('TELEGRAM_WEBHOOK_SECRET');

        if ($secretEnv === '' || $secretHeader !== $secretEnv) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        $update = $request->all();

        // 2) message o channel_post
        $message = $update['message'] ?? $update['channel_post'] ?? null;
        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) data_get($message, 'chat.id', '');

        // 3) permitir solo ciertos chat_ids (tú + 2 personas)
        $allowed = array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_ALLOWED_CHAT_IDS', ''))));

        if (!in_array($chatId, $allowed, true)) {
            Log::warning('Telegram webhook: chat no autorizado', ['chat_id' => $chatId]);
            return response()->json(['ok' => true]);
        }

        // 4) validar documento excel
        $doc = $message['document'] ?? null;
        if (!$doc) {
            return response()->json(['ok' => true]);
        }

        $fileId   = (string) ($doc['file_id'] ?? '');
        $fileName = (string) ($doc['file_name'] ?? 'archivo.xlsx');

        if ($fileId === '') {
            return response()->json(['ok' => true]);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return response()->json(['ok' => true]);
        }

        // ✅ 5) IMPORTANTÍSIMO: aquí NO importamos directo.
        // Mandamos a cola y respondemos rápido para evitar reintentos/bucle.
        ImportExcelFromTelegramJob::dispatch($chatId, $fileId, $fileName);

        return response()->json(['ok' => true]);
    }
}
