<?php

namespace Tests\Unit;

use App\Services\MercadoLibre\PriceManager\MeliTaxDetailsNormalizer;
use PHPUnit\Framework\TestCase;

class MeliTaxDetailsNormalizerTest extends TestCase
{
    public function test_official_billing_tax_details_are_normalized_without_pii_or_invented_rates(): void
    {
        $result = (new MeliTaxDetailsNormalizer)->normalize([
            'results' => [[
                'order_id' => 1234567890000,
                'payment_info' => [[
                    'payment_id' => 99999999999,
                    'date_approved' => '2024-04-23T03:11:47',
                    'status' => 'approved',
                    'payer_id' => 12345678,
                    'payment_method_id' => 'visa',
                    'tax_details' => [[
                        'from' => 'collector',
                        'to' => 'mp',
                        'original_amount' => 2018.99,
                        'refunded_amount' => 0,
                        'mov_detail' => 'tax_withholding',
                        'mov_financial_entity' => 'retencion_ganancias',
                        'tax_id' => 9999999997,
                        'tax_status' => 'applied',
                    ], [
                        'from' => 'collector',
                        'to' => 'mp',
                        'original_amount' => 6056.97,
                        'refunded_amount' => 0,
                        'mov_detail' => 'tax_withholding',
                        'mov_financial_entity' => 'retencion_iva',
                        'tax_id' => 9999999998,
                        'tax_status' => 'applied',
                    ]],
                ]],
                'details' => [[
                    'items_info' => [
                        'item_id' => 'MLM123456',
                        'item_title' => 'No debe conservarse',
                    ],
                ]],
            ]],
        ]);

        $this->assertTrue($result['available']);
        $this->assertSame('mercadolibre_billing', $result['source']);
        $this->assertSame('exact', $result['confidence']);
        $this->assertSame(2, $result['tax_details_count']);
        $this->assertSame(1234567890000, data_get($result, 'orders.0.order_id'));
        $this->assertSame(['MLM123456'], data_get($result, 'orders.0.item_ids'));
        $this->assertSame('order_payment', data_get($result, 'orders.0.attribution_scope'));
        $this->assertSame('retencion_ganancias', data_get($result, 'orders.0.payments.0.taxes.0.mov_financial_entity'));
        $this->assertSame(2018.99, data_get($result, 'orders.0.payments.0.taxes.0.original_amount'));
        $this->assertSame(0.0, data_get($result, 'orders.0.payments.0.taxes.0.refunded_amount'));

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('payer_id', $serialized);
        $this->assertStringNotContainsString('payment_method_id', $serialized);
        $this->assertStringNotContainsString('item_title', $serialized);
        $this->assertStringNotContainsString('rate', $serialized);
        $this->assertStringNotContainsString('taxable_base', $serialized);
    }

    public function test_missing_tax_fields_are_not_created_by_the_normalizer(): void
    {
        $result = (new MeliTaxDetailsNormalizer)->normalize([
            'results' => [[
                'order_id' => '100',
                'payment_info' => [[
                    'payment_id' => '200',
                    'tax_details' => [['mov_detail' => 'tax_withholding']],
                ]],
            ]],
        ]);

        $tax = data_get($result, 'orders.0.payments.0.taxes.0');
        $this->assertSame(['mov_detail' => 'tax_withholding'], $tax);
        $this->assertArrayNotHasKey('original_amount', $tax);
        $this->assertArrayNotHasKey('refunded_amount', $tax);
    }

    public function test_empty_or_invalid_billing_payload_is_unavailable(): void
    {
        foreach ([null, [], ['results' => []], ['results' => ['invalid']]] as $payload) {
            $result = (new MeliTaxDetailsNormalizer)->normalize($payload);
            $this->assertFalse($result['available']);
            $this->assertNull($result['source']);
            $this->assertSame('unknown', $result['confidence']);
            $this->assertSame([], $result['orders']);
            $this->assertSame(0, $result['tax_details_count']);
        }
    }
}
