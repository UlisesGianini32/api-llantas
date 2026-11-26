<?php

namespace App\Services\MercadoLibre\Orders;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
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
        try {
            $response = $this->api->getReadOnly(
                $account,
                '/orders/'.rawurlencode((string) $order->order_id).'/feedback',
                [],
                1,
            );
        } catch (MeliApiRequestException $error) {
            if ($this->isFeedbackNotFound($error)) {
                return ['sale' => null, 'purchase' => null];
            }

            throw $error;
        }
        $payload = $response->json();
        if (! is_array($payload) || (! array_key_exists('sale', $payload) && ! array_key_exists('purchase', $payload))) {
            throw new MeliOrderFeedbackVerificationException('Mercado Libre devolvió una respuesta de feedback inválida.');
        }

        return $payload;
    }

    private function isFeedbackNotFound(MeliApiRequestException $error): bool
    {
        if ($error->httpStatus() !== 404) return false;

        $message = $error->getMessage();
        $detail = preg_replace('/^Mercado Libre HTTP 404:\s*/i', '', $message) ?? $message;
        $detail = trim($detail);

        if (in_array(strtolower($detail), ["feedback doesn't exist.", "feedback doesn't exist", 'feedback_not_found'], true)) {
            return true;
        }

        return preg_match('/["\'](?:error|code|error_code)["\']\s*:\s*["\']feedback_not_found["\']/i', $detail) === 1;
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
        $guard = app(\App\Services\MeliOrderRemoteFreshnessGuard::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $remoteOrder, $shipment, $guard): void {
            $locked = MeliOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $orderDecision = $guard->evaluateOrderUpdate(
                $locked,
                $remoteOrder,
                $locked->meli_account_id !== null ? (int) $locked->meli_account_id : null,
            );

            $incomingShippingId = data_get($remoteOrder, 'shipping.id') ?? data_get($shipment, 'id');
            $shipmentDecision = $shipment !== null
                ? $guard->evaluateShipmentUpdate(
                    $locked,
                    $shipment,
                    filled($shipment['status'] ?? null) ? (string) $shipment['status'] : null,
                    $incomingShippingId !== null ? (string) $incomingShippingId : null,
                    orderAuthorizesNewShippingId: $orderDecision['accept'],
                )
                : ['accept' => false, 'reason' => 'no_shipment_payload', 'stored_timestamp_utc' => null, 'incoming_timestamp_utc' => null];

            if (! $orderDecision['accept'] && ! $shipmentDecision['accept']) {
                \Illuminate\Support\Facades\Log::warning('MELI ORDERS: persistRemote descartado por control temporal o integridad', [
                    'meli_order_id' => $locked->id,
                    'order_id' => $locked->order_id,
                    'order_reason' => $orderDecision['reason'],
                    'order_payload_kind' => $orderDecision['payload_kind'],
                    'shipment_reason' => $shipmentDecision['reason'],
                ]);

                return;
            }

            $updates = [];
            if ($orderDecision['accept']) {
                $shippingId = data_get($remoteOrder, 'shipping.id');
                $effectiveRaw = $orderDecision['advance_full_raw_timestamp']
                    ? $remoteOrder
                    : $guard->mergePartialOrderRaw(
                        is_array($locked->raw) ? $locked->raw : [],
                        $remoteOrder,
                    );

                $updates += array_filter([
                    'status' => filled($remoteOrder['status'] ?? null) ? (string) $remoteOrder['status'] : null,
                    'display_id' => filled($remoteOrder['display_id'] ?? null) ? (string) $remoteOrder['display_id'] : null,
                    'shipping_id' => filled($shippingId) ? (string) $shippingId : null,
                    'raw' => $effectiveRaw,
                ], static fn (mixed $value): bool => $value !== null);
            }

            if ($shipment !== null && $shipmentDecision['accept']) {
                $updates += array_filter([
                    'shipping_id' => filled($shipment['id'] ?? null) ? (string) $shipment['id'] : null,
                    'shipping_status' => filled($shipment['status'] ?? null) ? (string) $shipment['status'] : null,
                    'shipping_substatus' => filled($shipment['substatus'] ?? null) ? (string) $shipment['substatus'] : null,
                    'shipping_raw' => $shipment,
                ], static fn (mixed $value): bool => $value !== null);
            }

            if ($updates !== []) {
                $locked->forceFill($updates)->save();
                $order->setRawAttributes($locked->getAttributes(), true);
            }

            if ((bool) config('inventory.meli_order_reservations.automatic', false)) {
                app(\App\Services\InventoryMeliOrderReservationDispatcher::class)
                    ->dispatchAfterCommit($locked);
            }
        });
    }

    public function safeError(string $message): string
    {
        return $this->api->sanitizeMessage($message);
    }
}
