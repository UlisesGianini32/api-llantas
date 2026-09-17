<?php

namespace App\Services;

use App\Models\Llanta;
use App\Models\MeliOrder;
use App\Models\Product;
use App\Models\ProductoCompuesto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockService
{
    /**
     * ID de la cuenta secundaria de Mercado Libre.
     */
    protected const SECONDARY_MELI_ACCOUNT_ID = 2;

    /**
     * Estados de Mercado Libre en los que la venta ya debe descontar stock.
     */
    protected function shouldDeductStockForMeliStatus(?string $status): bool
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, ['paid', 'partially_paid'], true);
    }

    /**
     * Determina si la orden pertenece a la cuenta secundaria.
     */
    protected function isSecondaryMeliOrder(MeliOrder $order): bool
    {
        return (int) $order->meli_account_id === self::SECONDARY_MELI_ACCOUNT_ID;
    }

    /**
     * Descuenta inventario local según el SKU vendido.
     *
     * Orden de búsqueda:
     *
     * 1. Producto compuesto de llantas.
     * 2. Llanta individual.
     * 3. Producto normal de la tabla products.
     *
     * Retorna true cuando pudo localizar y descontar el producto.
     * Retorna false cuando no encontró el SKU en el sistema.
     */
    public function applySaleBySku(
        string $sku,
        int $quantity,
        array $meta = []
    ): bool {
        $sku = trim($sku);
        $quantity = max(0, $quantity);

        if ($sku === '' || $quantity <= 0) {
            return false;
        }

        /*
         * Primero buscamos un producto compuesto real.
         *
         * No asumimos automáticamente que cualquier SKU terminado en -2 o -4
         * es una llanta, porque un producto normal también podría terminar así.
         */
        $compound = ProductoCompuesto::query()
            ->where('sku', $sku)
            ->lockForUpdate()
            ->first();

        if ($compound) {
            $piecesPerSale = match ((string) $compound->tipo) {
                'par' => 2,
                'juego4' => 4,
                default => $this->resolveCompoundPiecesFromSku($sku),
            };

            if ($piecesPerSale <= 0) {
                Log::warning(
                    'StockService: no se pudo determinar cuántas piezas contiene el compuesto',
                    array_merge($meta, [
                        'sku' => $sku,
                        'tipo' => $compound->tipo,
                    ])
                );

                return false;
            }

            $llanta = Llanta::query()
                ->where('id', $compound->llanta_id)
                ->lockForUpdate()
                ->first();

            if (! $llanta) {
                Log::warning(
                    'StockService: compuesto vendido sin llanta base',
                    array_merge($meta, [
                        'sku' => $sku,
                        'producto_compuesto_id' => $compound->id,
                        'llanta_id' => $compound->llanta_id,
                    ])
                );

                return false;
            }

            $unitsToSubtract = $piecesPerSale * $quantity;
            $previousStock = max(0, (int) $llanta->stock);
            $newStock = max(0, $previousStock - $unitsToSubtract);

            $llanta->stock = $newStock;
            $llanta->save();

            $this->syncCompoundStocksFromLlanta($llanta);

            Log::info(
                'StockService: stock de llanta descontado por venta secundaria de compuesto',
                array_merge($meta, [
                    'sku' => $sku,
                    'quantity_sold' => $quantity,
                    'pieces_per_sale' => $piecesPerSale,
                    'units_subtracted' => $unitsToSubtract,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                ])
            );

            return true;
        }

        /*
         * Después buscamos una llanta individual.
         */
        $llanta = Llanta::query()
            ->where('sku', $sku)
            ->lockForUpdate()
            ->first();

        if ($llanta) {
            $previousStock = max(0, (int) $llanta->stock);
            $newStock = max(0, $previousStock - $quantity);

            $llanta->stock = $newStock;
            $llanta->save();

            $this->syncCompoundStocksFromLlanta($llanta);

            Log::info(
                'StockService: stock de llanta descontado por venta secundaria',
                array_merge($meta, [
                    'sku' => $sku,
                    'quantity_sold' => $quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                ])
            );

            return true;
        }

        /*
         * Finalmente buscamos el producto normal.
         *
         * Esta parte es la que faltaba en tu servicio anterior.
         */
        $product = Product::query()
            ->where('sku', $sku)
            ->lockForUpdate()
            ->first();

        if ($product) {
            $previousStock = max(0, (int) $product->stock);
            $newStock = max(0, $previousStock - $quantity);

            $product->stock = $newStock;
            $product->save();

            Log::info(
                'StockService: stock de producto descontado por venta secundaria',
                array_merge($meta, [
                    'product_id' => $product->id,
                    'sku' => $sku,
                    'quantity_sold' => $quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                ])
            );

            return true;
        }

        Log::warning(
            'StockService: venta secundaria con SKU no encontrado en el inventario local',
            array_merge($meta, [
                'sku' => $sku,
                'quantity_sold' => $quantity,
            ])
        );

        return false;
    }

    /**
     * Descuenta stock local de una orden pagada de la cuenta secundaria.
     *
     * La columna stock_applied_at evita que el webhook y la sincronización
     * programada descuenten dos veces la misma orden.
     */
    public function applyStockFromMeliOrderIfNeeded(MeliOrder $order): void
    {
        $order->refresh();

        /*
         * No tocar ventas de la cuenta principal.
         */
        if (! $this->isSecondaryMeliOrder($order)) {
            return;
        }

        if ($order->stock_applied_at) {
            return;
        }

        if (! $this->shouldDeductStockForMeliStatus($order->status)) {
            return;
        }

        try {
            DB::transaction(function () use ($order) {
                /*
                 * Bloqueamos la orden para impedir que dos procesos
                 * intenten descontarla simultáneamente.
                 */
                $lockedOrder = MeliOrder::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOrder) {
                    throw new \RuntimeException(
                        'No se encontró la orden local durante el descuento de stock.'
                    );
                }

                if (! $this->isSecondaryMeliOrder($lockedOrder)) {
                    return;
                }

                if ($lockedOrder->stock_applied_at) {
                    return;
                }

                if (! $this->shouldDeductStockForMeliStatus($lockedOrder->status)) {
                    return;
                }

                $lockedOrder->load('items');

                if ($lockedOrder->items->isEmpty()) {
                    throw new \RuntimeException(
                        'La orden no tiene artículos guardados para descontar.'
                    );
                }

                foreach ($lockedOrder->items as $item) {
                    $sku = trim((string) ($item->sku ?? ''));
                    $quantity = (int) ($item->quantity ?? 0);

                    if ($quantity <= 0) {
                        continue;
                    }

                    if ($sku === '') {
                        throw new \RuntimeException(
                            'La orden contiene un artículo sin SKU.'
                        );
                    }

                    $applied = $this->applySaleBySku(
                        $sku,
                        $quantity,
                        [
                            'source' => 'meli_secondary_order',
                            'meli_account_id' => $lockedOrder->meli_account_id,
                            'meli_order_id' => $lockedOrder->id,
                            'order_id' => $lockedOrder->order_id,
                            'meli_order_item_id' => $item->id,
                        ]
                    );

                    /*
                     * Si un SKU no existe, lanzamos una excepción.
                     *
                     * Como estamos dentro de una transacción, Laravel revierte
                     * cualquier descuento aplicado a los otros artículos de
                     * esa misma orden. Así evitamos descuentos parciales.
                     */
                    if (! $applied) {
                        throw new \RuntimeException(
                            "No se encontró el SKU {$sku} en products, llantas ni producto_compuestos."
                        );
                    }
                }

                $lockedOrder->stock_applied_at = now();
                $lockedOrder->save();

                Log::info(
                    'StockService: stock aplicado correctamente desde venta secundaria',
                    [
                        'meli_account_id' => $lockedOrder->meli_account_id,
                        'meli_order_id' => $lockedOrder->id,
                        'order_id' => $lockedOrder->order_id,
                        'stock_applied_at' => $lockedOrder->stock_applied_at,
                    ]
                );
            }, 3);
        } catch (\Throwable $exception) {
            /*
             * No marcamos stock_applied_at cuando algo falla.
             * La orden podrá volver a intentarse después de corregir el SKU.
             */
            Log::error(
                'StockService: no se pudo descontar el stock de la venta secundaria',
                [
                    'meli_account_id' => $order->meli_account_id,
                    'meli_order_id' => $order->id,
                    'order_id' => $order->order_id,
                    'error' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * Obtiene la cantidad de piezas de un compuesto usando el sufijo del SKU.
     */
    protected function resolveCompoundPiecesFromSku(string $sku): int
    {
        if (! preg_match('/-(2|4)$/', $sku, $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * Recalcula el stock vendible de par y juego de cuatro
     * usando el stock de la llanta individual.
     */
    protected function syncCompoundStocksFromLlanta(Llanta $llanta): void
    {
        $availableUnits = max(0, (int) $llanta->stock);

        $compoundTypes = [
            'par' => 2,
            'juego4' => 4,
        ];

        foreach ($compoundTypes as $type => $pieces) {
            $compound = ProductoCompuesto::query()
                ->where('llanta_id', $llanta->id)
                ->where('tipo', $type)
                ->lockForUpdate()
                ->first();

            if (! $compound) {
                continue;
            }

            $compound->stock = intdiv($availableUnits, $pieces);
            $compound->save();
        }
    }
}