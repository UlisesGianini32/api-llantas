<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliOrderActionLog;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Services\MercadoLibre\Orders\MeliOrderCancellationPolicy;
use App\Services\MercadoLibre\Orders\MeliOrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class MeliOrderCancellationController extends Controller
{
    private const COOLDOWN_SECONDS = 120;

    public function store(Request $request, MeliOrder $order, MeliOrderCancellationPolicy $policy, MeliOrderCancellationService $service): RedirectResponse
    {
        $account = $this->accountFor($request, $order);
        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(MeliOrderCancellationPolicy::REASONS))],
            'confirmed' => ['required', 'accepted'],
            'restock_item' => ['prohibited'],
            'fulfilled' => ['prohibited'],
            'rating' => ['prohibited'],
            'message' => ['prohibited'],
        ]);
        $remoteOrderId = trim((string) $order->order_id);
        abort_unless($remoteOrderId !== '' && ctype_digit($remoteOrderId), 422, 'El pedido no tiene un order_id real válido.');

        $lock = Cache::lock("meli-order-cancel:{$account->id}:{$remoteOrderId}", self::COOLDOWN_SECONDS);
        if (! $lock->get()) return back()->with('error', 'La cancelación de este pedido ya se está procesando.');

        try {
            if ($this->recentAttemptExists($account, $remoteOrderId)) {
                return back()->with('error', 'Este pedido tuvo un intento de cancelación reciente. Actualízalo antes de volver a intentar.');
            }

            $service->ensureFreshToken($account);
            $remoteOrder = $service->order($account, $order);
            abort_unless((string) ($remoteOrder['id'] ?? '') === $remoteOrderId, 409, 'Mercado Libre devolvió una orden distinta a la solicitada.');
            if ($policy->isAlreadyCancelled($remoteOrder)) {
                $service->persistRemote($order, $remoteOrder);
                return back()->with('error', 'Esta operación ya está cancelada o marcada como no concretada en Mercado Libre.');
            }
            try {
                $feedback = $service->feedback($account, $order);
            } catch (Throwable $error) {
                report($error);
                return back()->with('error', 'No fue posible verificar si esta venta ya tiene feedback. No se realizó la cancelación.');
            }
            if ($policy->hasSaleFeedback($feedback)) {
                $service->persistRemote($order, $remoteOrder);
                return back()->with('error', 'Esta operación ya tiene un feedback de venta registrado en Mercado Libre.');
            }

            $shipment = $this->shipmentFor($service, $account, $remoteOrder);
            $service->persistRemote($order, $remoteOrder, $shipment);
            if ($shipment !== null && $policy->shipmentBlocks($shipment)) {
                return back()->with('error', 'Este pedido ya avanzó en el proceso de envío. Revísalo en Mercado Libre o utiliza el flujo de reclamos/devoluciones correspondiente.');
            }

            $payload = $policy->payload($validated['reason']);
            $audit = MeliOrderActionLog::query()->create([
                'meli_order_id' => $order->id,
                'remote_order_id' => $remoteOrderId,
                'meli_account_id' => $account->id,
                'user_id' => $request->user()->id,
                'action' => 'cancel_sale',
                'reason' => $validated['reason'],
                'request_payload_sanitized' => $payload,
            ]);
            Cache::put("meli-order-cancel-cooldown:{$account->id}:{$remoteOrderId}", true, now()->addSeconds(self::COOLDOWN_SECONDS));

            $service->ensureFreshToken($account);
            try {
                $response = $service->cancel($account, $order, $payload);
                $audit->forceFill([
                    'remote_status' => $response->status(),
                    'remote_response_id' => data_get($response->json(), 'id'),
                    'success' => true,
                ])->save();
            } catch (Throwable $error) {
                $status = $error instanceof MeliApiRequestException ? $error->httpStatus() : 0;
                $audit->forceFill([
                    'remote_status' => $status ?: null,
                    'success' => $status === 0 ? null : false,
                    'error_code' => $status === 0 ? 'uncertain_delivery' : 'http_'.$status,
                    'error_message' => $service->safeError($error->getMessage()),
                ])->save();
                report($error);

                return back()->with('error', $status === 0
                    ? 'No fue posible confirmar si Mercado Libre canceló la venta. Actualiza el pedido antes de volver a intentar.'
                    : 'Mercado Libre no procesó la cancelación. No se realizó ningún reintento automático.');
            }

            try {
                $freshOrder = $service->order($account, $order);
                $freshShipment = $this->shipmentFor($service, $account, $freshOrder);
                $service->persistRemote($order, $freshOrder, $freshShipment);
            } catch (Throwable $error) {
                Log::warning('MELI ORDERS: cancelación confirmada pero refresh posterior falló', [
                    'meli_order_id' => $order->id,
                    'remote_order_id' => $remoteOrderId,
                    'error' => $service->safeError($error->getMessage()),
                ]);

                return back()->with('error', 'La cancelación fue enviada correctamente, pero no fue posible actualizar el pedido. Actualízalo manualmente.');
            }

            return back()->with('success', 'Cancelación enviada correctamente a Mercado Libre.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return back()->with('error', 'No fue posible verificar el pedido con Mercado Libre. No se realizó la cancelación.');
        } finally {
            $lock->release();
        }
    }

    private function accountFor(Request $request, MeliOrder $order): MeliAccount
    {
        return $request->user()->meliAccounts()->where('is_default', false)->findOrFail($order->meli_account_id);
    }

    private function shipmentFor(MeliOrderCancellationService $service, MeliAccount $account, array $remoteOrder): ?array
    {
        $shipmentId = trim((string) data_get($remoteOrder, 'shipping.id', ''));

        return $shipmentId !== '' ? $service->shipment($account, $shipmentId) : null;
    }

    private function recentAttemptExists(MeliAccount $account, string $remoteOrderId): bool
    {
        return Cache::has("meli-order-cancel-cooldown:{$account->id}:{$remoteOrderId}")
            || MeliOrderActionLog::query()->where('meli_account_id', $account->id)
                ->where('remote_order_id', $remoteOrderId)->where('action', 'cancel_sale')
                ->where('created_at', '>=', now()->subSeconds(self::COOLDOWN_SECONDS))->exists();
    }
}
