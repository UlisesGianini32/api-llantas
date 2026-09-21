<?php

namespace Tests\Unit;

use App\Services\MercadoLibre\Orders\MeliOrderDeliveryClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeliOrderDeliveryClassifierTest extends TestCase
{
    #[DataProvider('deliveryCases')]
    public function test_delivery_classification_is_conservative(
        array $order,
        ?string $shippingId,
        array $modes,
        string $expectedType,
        bool $needsReview
    ): void {
        $result = (new MeliOrderDeliveryClassifier)->classify($order, $shippingId, $modes);

        $this->assertSame($expectedType, $result['delivery_type']);
        $this->assertSame($needsReview, $result['needs_review']);
    }

    public static function deliveryCases(): array
    {
        return [
            'not specified without pack or shipment' => [
                ['status' => 'paid', 'pack_id' => null], null, ['not_specified'], 'agreed_with_buyer', false,
            ],
            'partially paid follows current operational policy' => [
                ['status' => 'partially_paid', 'pack_id' => null], null, ['not_specified'], 'agreed_with_buyer', false,
            ],
            'shipment always wins' => [
                ['status' => 'paid', 'pack_id' => null], '123456', ['not_specified'], 'mercado_envios', false,
            ],
            'me2 waits for shipment' => [
                ['status' => 'paid', 'pack_id' => null], null, ['me2'], 'pending_shipment', false,
            ],
            'pack prevents agreed false positive' => [
                ['status' => 'paid', 'pack_id' => '7788'], null, ['not_specified'], 'pending_shipment', false,
            ],
            'custom remains separate' => [
                ['status' => 'paid', 'pack_id' => null], null, ['custom'], 'custom_shipping', false,
            ],
            'missing item mode needs review' => [
                ['status' => 'paid', 'pack_id' => null], null, [null], 'unknown', true,
            ],
            'mixed modes need review' => [
                ['status' => 'paid', 'pack_id' => null], null, ['not_specified', 'me2'], 'unknown', true,
            ],
        ];
    }
}
