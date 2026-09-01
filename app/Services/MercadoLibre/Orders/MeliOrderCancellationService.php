<?php

namespace App\Services\MercadoLibre\Orders;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Http\Client\Response;

class MeliOrderCancellationService
{
    public function __construct(private readonly MeliAccountApiClient $api) {}

    public function ensureFreshToken(MeliAccount $account): void
    {
        $this->api->ensureFreshAccessToken($account);
    }

    public function order(MeliAccount $account, MeliOrder $order): array
    {
        $response = $this->api->getReadOnly($account, '/orders/'.rawurlencode((string) $order->order_id), [], 1);

        return is_array($response->json()) ? $response->json() : [];
    }

    public function shipment(MeliAccount $account, string $shipmentId): array
    {
        $response = $this->api->getReadOnly($account, '/shipments/'.rawurlencode($shipmentId), [], 1);

        return is_array($response->json()) ? $response->json() : [];
    }

    public function feedback(MeliAccount $account, MeliOrder $order): array
    {
        $response = $this->api->getReadOnly(
            $account,
            '/orders/'.rawurlencode((string) $order->order_id).'/feedback',
            [],
            1,
        );
        $payload = $response->json();
        if (! is_array($payload) || (! array_key_exists('sale', $payload) && ! array_key_exists('purchase', $payload))) {
            throw new MeliOrderFeedbackVerificationException('Mercado Libre devolvió una respuesta de feedback inválida.');
        }

        return $payload;
    }

    public function cancel(MeliAccount $account, MeliOrder $order, array $payload): Response
    {
        return $this->api->request(
            $account,
            'post',
            '/orders/'.rawurlencode((string) $order->order_id).'/feedback',
            $payload,
            refreshAfterUnauthorized: false,
            maxAttempts: 1,
        );
    }

    public function persistRemote(MeliOrder $order, array $remoteOrder, ?array $shipment = null): void
    {
        $shippingId = data_get($remoteOrder, 'shipping.id');
        $order->forceFill(array_filter([
            'status' => filled($remoteOrder['status'] ?? null) ? (string) $remoteOrder['status'] : null,
            'display_id' => filled($remoteOrder['display_id'] ?? null) ? (string) $remoteOrder['display_id'] : null,
            'shipping_id' => filled($shippingId) ? (string) $shippingId : null,
            'shipping_status' => filled($shipment['status'] ?? null) ? (string) $shipment['status'] : null,
            'shipping_substatus' => filled($shipment['substatus'] ?? null) ? (string) $shipment['substatus'] : null,
            'shipping_raw' => $shipment,
            'raw' => $remoteOrder,
        ], fn (mixed $value): bool => $value !== null))->save();
    }

    public function safeError(string $message): string
    {
        return $this->api->sanitizeMessage($message);
    }
}
