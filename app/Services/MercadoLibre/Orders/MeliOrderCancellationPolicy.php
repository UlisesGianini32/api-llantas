<?php

namespace App\Services\MercadoLibre\Orders;

class MeliOrderCancellationPolicy
{
    public const REASONS = [
        'OUT_OF_STOCK' => 'Sin stock',
        'BUYER_NOT_ENOUGH_MONEY' => 'El comprador no tiene fondos suficientes',
        'BUYER_REGRETS' => 'El comprador se arrepintió',
        'SELLER_REGRETS' => 'El vendedor decidió no concretar',
        'BUYER_DID_NOT_ANSWER' => 'El comprador no respondió',
        'THEY_NOT_HONORING_POLICIES' => 'El comprador no respetó las políticas',
        'OTHER_MY_RESPONSIBILITY' => 'Otro motivo responsabilidad del vendedor',
        'OTHER_THEIR_RESPONSIBILITY' => 'Otro motivo responsabilidad del comprador',
        'DUBIOUS_BUYER' => 'Comprador dudoso',
    ];

    private const MESSAGES = [
        'OUT_OF_STOCK' => 'No podemos completar la venta porque el producto no está disponible.',
        'BUYER_NOT_ENOUGH_MONEY' => 'La compra no puede concretarse por falta de fondos del comprador.',
        'BUYER_REGRETS' => 'La compra se cancela a solicitud del comprador.',
        'SELLER_REGRETS' => 'El vendedor decidió no concretar esta venta.',
        'BUYER_DID_NOT_ANSWER' => 'No fue posible continuar porque el comprador no respondió.',
        'THEY_NOT_HONORING_POLICIES' => 'La compra no puede continuar porque no se respetaron las políticas aplicables.',
        'OTHER_MY_RESPONSIBILITY' => 'La venta no pudo concretarse por un motivo responsabilidad del vendedor.',
        'OTHER_THEIR_RESPONSIBILITY' => 'La venta no pudo concretarse por un motivo responsabilidad del comprador.',
        'DUBIOUS_BUYER' => 'La venta no pudo concretarse por validaciones relacionadas con el comprador.',
    ];

    public function payload(string $reason): array
    {
        if (! isset(self::REASONS[$reason])) throw new \InvalidArgumentException('Motivo de cancelación no permitido.');

        return ['fulfilled' => false, 'rating' => 'neutral', 'message' => self::MESSAGES[$reason], 'reason' => $reason, 'restock_item' => false];
    }

    public function isAlreadyCancelled(array $order): bool
    {
        $tags = array_map(fn (mixed $tag): string => strtolower((string) $tag), (array) ($order['tags'] ?? []));

        return (array_key_exists('cancel_detail', $order) && $order['cancel_detail'] !== null)
            || in_array('unfulfilled', $tags, true)
            || in_array(strtolower((string) ($order['status'] ?? '')), ['cancelled', 'canceled'], true);
    }

    public function hasSaleFeedback(array $feedback): bool
    {
        return array_key_exists('sale', $feedback) && $feedback['sale'] !== null;
    }

    public function shipmentBlocks(array $shipment): bool
    {
        return in_array(strtolower((string) ($shipment['status'] ?? '')), ['shipped', 'in_transit', 'delivered'], true);
    }
}
