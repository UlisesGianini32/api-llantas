<?php

namespace App\Http\Controllers;

use App\Services\Shopify\InventoryShopifyClient;
use App\Services\Shopify\InventoryShopifyOrderReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function __construct(
        private readonly InventoryShopifyClient $client,
        private readonly InventoryShopifyOrderReservationService $orderReservations,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');

        if (! $this->client->verifyHmac($rawBody, $hmacHeader)) {
            Log::warning('Shopify Webhook: firma HMAC inválida', [
                'header' => $hmacHeader,
                'topic' => $request->header('X-Shopify-Topic'),
                'shop' => $request->header('X-Shopify-Shop-Domain'),
            ]);

            return response()->json(['error' => 'Firma HMAC inválida.'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain', '');
        $payload = $request->json()->all();

        Log::info('Shopify Webhook recibido y autenticado', [
            'topic' => $topic,
            'shop' => $shopDomain,
            'order_id' => $payload['id'] ?? null,
            'order_name' => $payload['name'] ?? null,
        ]);

        return match ($topic) {
            'orders/create', 'orders/updated', 'orders/paid' => response()->json([
                'ok' => true,
                'topic' => $topic,
                'results' => $this->orderReservations->processOrderPayload($payload, $shopDomain),
            ]),
            'orders/cancelled' => response()->json([
                'ok' => true,
                'topic' => $topic,
                'results' => $this->orderReservations->cancelOrderPayload($payload, $shopDomain),
            ]),
            default => response()->json([
                'ok' => true,
                'ignored' => true,
                'topic' => $topic,
            ]),
        };
    }
}
