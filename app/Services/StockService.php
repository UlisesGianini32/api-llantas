<?php

namespace App\Services;

use App\Models\Llanta;
use App\Models\MeliOrder;
use App\Models\ProductoCompuesto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockService
{
    /**
     * Estados ML en los que tiene sentido descontar inventario (venta con pago acreditado).
     */
    protected function shouldDeductStockForMeliStatus(?string $status): bool
    {
        $s = strtolower(trim((string) $status));

        return in_array($s, ['paid', 'partially_paid'], true);
    }

    /**
     * Descuenta inventario según SKU vendido en Mercado Libre.
     * - Llanta suelta: resta unidades de llantas.
     * - Par / juego (SKU termina en -2 o -4): resta piezas de la llanta base y recalcula compuestos.
     */
    public function applySaleBySku(string $sku, int $qty, array $meta = []): void
    {
        $sku = trim($sku);
        if ($sku === '' || $qty <= 0) {
            return;
        }

        if (preg_match('/^(.+)-(2|4)$/', $sku, $m)) {
            $piezas = (int) $m[2];
            $comp = ProductoCompuesto::where('sku', $sku)->first();
            $llanta = $comp?->llanta ?? Llanta::where('sku', $m[1])->first();
            if (!$llanta) {
                Log::warning('StockService: venta ML compuesto sin llanta base', array_merge($meta, ['sku' => $sku]));

                return;
            }
            $dec = $piezas * $qty;
            $llanta->stock = max(0, (int) $llanta->stock - $dec);
            $llanta->save();
            $this->syncCompoundStocksFromLlanta($llanta);

            return;
        }

        $llanta = Llanta::where('sku', $sku)->first();
        if (!$llanta) {
            Log::warning('StockService: venta ML sin llanta con ese SKU', array_merge($meta, ['sku' => $sku]));

            return;
        }

        $llanta->stock = max(0, (int) $llanta->stock - $qty);
        $llanta->save();
        $this->syncCompoundStocksFromLlanta($llanta);
    }

    /**
     * Aplica descuento de stock desde filas meli_order_items (sku + quantity).
     * Idempotente vía meli_orders.stock_applied_at.
     */
    public function applyStockFromMeliOrderIfNeeded(MeliOrder $order): void
    {
        $order->refresh();

        if ($order->stock_applied_at) {
            return;
        }

        if (!$this->shouldDeductStockForMeliStatus($order->status)) {
            return;
        }

        DB::transaction(function () use ($order) {
            $order->refresh();
            if ($order->stock_applied_at) {
                return;
            }

            $sinSkuConCantidad = false;

            foreach ($order->items as $item) {
                $sku = trim((string) ($item->sku ?? ''));
                $qty = (int) ($item->quantity ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                if ($sku === '') {
                    $sinSkuConCantidad = true;

                    continue;
                }
                $this->applySaleBySku($sku, $qty, [
                    'source' => 'meli_order',
                    'meli_order_id' => $order->id,
                    'order_id' => $order->order_id,
                ]);
            }

            if ($sinSkuConCantidad) {
                Log::warning('StockService: orden ML pagada con ítems sin SKU; no se marca stock_applied_at (reintentar)', [
                    'meli_order_id' => $order->id,
                    'order_id' => $order->order_id,
                ]);

                return;
            }

            $order->stock_applied_at = now();
            $order->save();
        });
    }

    /**
     * Stock ML de par/juego = unidades vendibles según llantas sueltas.
     */
    protected function syncCompoundStocksFromLlanta(Llanta $llanta): void
    {
        $s = max(0, (int) $llanta->stock);

        $map = [
            'par' => 2,
            'juego4' => 4,
        ];

        foreach ($map as $tipo => $piezas) {
            $comp = ProductoCompuesto::where('llanta_id', $llanta->id)
                ->where('tipo', $tipo)
                ->first();
            if ($comp) {
                $comp->stock = intdiv($s, $piezas);
                $comp->save();
            }
        }
    }
}
