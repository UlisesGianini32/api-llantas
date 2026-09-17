<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMeliMessageNotification;
use App\Jobs\ProcessMeliOrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MeliWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        $topic = (string) ($payload['topic'] ?? '');
        $resource = (string) ($payload['resource'] ?? '');
        $appId = (string) ($payload['application_id'] ?? '');

        Log::info('MELI WEBHOOK: recibido', [
            'topic' => $topic,
            'resource' => $resource,
            'application_id' => $appId,
            'payload' => $payload,
        ]);

        $configuredAppId = (string) (config('services.meli.app_id') ?? '');

        if ($configuredAppId !== '' && $appId !== '' && $appId !== $configuredAppId) {
            Log::warning('MELI WEBHOOK: application_id no coincide', [
                'appId' => $appId,
                'expected' => $configuredAppId,
                'topic' => $topic,
                'resource' => $resource,
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'application_id_mismatch',
            ], 200);
        }

        if ($topic === 'items') {
            if (!preg_match('#^/items/(MLM\d+)#', $resource)) {
                Log::warning('MELI WEBHOOK: items ignorado por resource inválido', [
                    'topic' => $topic,
                    'resource' => $resource,
                ]);

                return response()->json([
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'invalid_items_resource',
                ], 200);
            }

            Log::info('MELI WEBHOOK: items recibido pero job desactivado', [
                'resource' => $resource,
            ]);

            // ProcessMeliItemNotification::dispatch($payload)->onQueue('meli');

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'items_job_disabled',
            ], 200);
        }

        if ($topic === 'messages') {
            $messageId = trim((string) $resource);
            if (str_contains($messageId, '/')) {
                $messageId = basename($messageId);
            }
            if ($messageId === '') {
                return response()->json([
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'empty_messages_resource',
                ], 200);
            }

            $payload['resource'] = $messageId;
            if (array_key_exists('user_id', $payload)) {
                $payload['user_id'] = (string) $payload['user_id'];
            }

            Log::info('MELI WEBHOOK: messages — cola menú posventa', [
                'resource' => $messageId,
            ]);

            ProcessMeliMessageNotification::dispatch($payload)->onQueue('meli');

            return response()->json(['ok' => true]);
        }

        if ($topic === 'orders_v2') {
            if (!preg_match('#^/orders/\d+$#', $resource)) {
                Log::warning('MELI WEBHOOK: orders_v2 ignorado por resource inválido', [
                    'topic' => $topic,
                    'resource' => $resource,
                ]);

                return response()->json([
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'invalid_order_resource',
                ], 200);
            }

            if (config('services.meli.webhook_dispatch_orders_v2')) {
                ProcessMeliOrderNotification::dispatch($payload)->onQueue('meli');
                Log::info('MELI WEBHOOK: orders_v2 encolado', ['resource' => $resource]);

                return response()->json(['ok' => true]);
            }

            Log::info('MELI WEBHOOK: orders_v2 recibido y reconocido (no encolado; sync por otro canal)', [
                'resource' => $resource,
                'hint' => 'MELI_WEBHOOK_PROCESS_ORDERS=true o MELI_WEBHOOK_DISPATCH_ORDERS_V2=true',
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'orders_v2_dispatch_disabled',
            ], 200);
        }

        if ($topic === 'post_purchase') {
            Log::info('MELI WEBHOOK: post_purchase recibido (reclamos/post-compra — sin integración)', [
                'resource' => $resource,
            ]);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'post_purchase_not_implemented',
            ], 200);
        }

        Log::warning('MELI WEBHOOK: topic no soportado', [
            'topic' => $topic,
            'resource' => $resource,
        ]);

        return response()->json([
            'ok' => true,
            'ignored' => true,
            'reason' => 'unsupported_topic',
        ], 200);
    }
}
