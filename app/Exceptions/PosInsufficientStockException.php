<?php

namespace App\Exceptions;

use App\Models\InventoryProduct;
use RuntimeException;

class PosInsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly InventoryProduct $product,
        public readonly int $requested,
        public readonly int $available,
        public readonly int $physical,
        public readonly int $reserved,
        public readonly bool $isComponent = false,
        public readonly ?InventoryProduct $kitProduct = null
    ) {
        if ($isComponent && $kitProduct) {
            $message = "Stock insuficiente para el componente '{$product->name}' (SKU: {$product->sku}) requerido por el kit '{$kitProduct->name}'. ".
                "Se requieren {$requested} unidades, pero solo hay {$available} disponibles ".
                "(Físico: {$physical}, Reservado en e-commerce: {$reserved}).";
        } else {
            $message = "Stock insuficiente para '{$product->name}' (SKU: {$product->sku}). ".
                "Solicitado: {$requested}, Disponible: {$available} ".
                "(Físico: {$physical}, Reservado en e-commerce: {$reserved}).";
        }

        parent::__construct($message);
    }
}
