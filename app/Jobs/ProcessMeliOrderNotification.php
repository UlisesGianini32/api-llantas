<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Services\MeliApi;
use App\Services\StockService;
use App\Services\SyscomOrderFromMeliService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMeliOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;

    public function __construct(public array $payload) {}

    public function handle(MeliApi $meli, StockService $stock, SyscomOrderFromMeliService $syscomOrderFromMeli)
    {
        $resource = (string)($this->payload['resource'] ?? '');
        preg_match('#/orders/(\d+)#', $resource, $m);
        $orderId = isset($m[1]) ? (int)$m[1] : 0;

        if ($orderId <= 0) {
            return;
        }

        // Tu sistema usa 1 cuenta principal; ajusta si manejas multi-user
        /** @var User $user */
        $user = User::query()->whereNotNull('access_token')->firstOrFail();

        // 1) Traer la orden desde ML
        $order = $meli->getOrder($user, $orderId);

        $status = (string)($order['status'] ?? '');

        DB::transaction(function () use ($orderId, $resource, $status, $order, $meli, $user, $stock, $syscomOrderFromMeli) {

            $meliOrder = MeliOrder::updateOrCreate(
                ['order_id' => $orderId],
                [
                    'topic' => 'orders_v2',
                    'resource' => $resource,
                    'status' => $status,
                    'raw' => $order,
                ]
            );

            MeliOrderItem::where('meli_order_id', $meliOrder->id)->delete();

            $orderItems = (array)($order['order_items'] ?? []);
            foreach ($orderItems as $oi) {
                $item = $oi['item'] ?? [];
                $itemId = (string)($item['id'] ?? '');
                $qty = (int)($oi['quantity'] ?? 0);
                $unitPrice = $oi['unit_price'] ?? null;

                if ($itemId === '' || $qty <= 0) {
                    continue;
                }

                $sku = $meli->resolveSkuFromItem($user, $itemId);

                MeliOrderItem::create([
                    'meli_order_id' => $meliOrder->id,
                    'item_id' => $itemId,
                    'sku' => $sku,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                ]);

                if (!$sku) {
                    Log::warning('MELI WEBHOOK: No se pudo resolver SKU', [
                        'order_id' => $orderId,
                        'item_id' => $itemId,
                    ]);
                }
            }

            $meliOrder->load('items');
            $stock->applyStockFromMeliOrderIfNeeded($meliOrder);
            $syscomOrderFromMeli->handleAfterMeliSync($user, $meliOrder);

            $meliOrder->processed_at = now();
            $meliOrder->save();
        });
    }
}
