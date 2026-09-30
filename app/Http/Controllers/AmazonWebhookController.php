<?php

namespace App\Http\Controllers;

use App\Services\Amazon\InventoryAmazonOrderReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AmazonWebhookController extends Controller
{
    public function __construct(
        private readonly InventoryAmazonOrderReservationService $orderReservations,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.amazon.webhook_secret', env('AMAZON_WEBHOOK_SECRET', ''));
        if ($secret !== '') {
            $incomingHeader = (string) (
                $request->header('X-Amazon-Webhook-Secret')
                ?? $request->header('X-Webhook-Secret')
                ?? $request->bearerToken()
                ?? ''
            );
            if (! hash_equals($secret, $incomingHeader)) {
                Log::warning('Amazon Webhook: secreto de autorización inválido o ausente', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'No autorizado. Secreto de webhook inválido.'], 401);
            }
        }

        $payload = $request->json()->all();

        // Si viene envuelto en un sobre de AWS SNS
        if (($payload['Type'] ?? null) === 'Notification' && isset($payload['Message'])) {
            $decoded = json_decode($payload['Message'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $order = $payload['order'] ?? $payload['Order'] ?? $payload;
        $orderId = (string) ($order['AmazonOrderId'] ?? $order['id'] ?? '');

        if ($orderId === '') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'missing_order_id']);
        }

        $orderStatus = (string) ($order['OrderStatus'] ?? $order['status'] ?? 'Unshipped');

        Log::info('Amazon Webhook recibido', [
            'order_id' => $orderId,
            'status' => $orderStatus,
            'fulfillment_channel' => $order['FulfillmentChannel'] ?? null,
        ]);

        if (strtolower($orderStatus) === 'canceled') {
            $results = $this->orderReservations->cancelOrderPayload($order);
        } else {
            $results = $this->orderReservations->processOrderPayload($order);
        }

        return response()->json([
            'ok' => true,
            'order_id' => $orderId,
            'results' => $results,
        ]);
    }
}
