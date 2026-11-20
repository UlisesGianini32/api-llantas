<?php

namespace Tests\Unit\Purchasing;

use App\Models\MeliPublication;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use Tests\TestCase;

class PurchaseOrderMeliImageResolverTest extends TestCase
{
    public function test_returns_main_photo_when_seller_sku_matches(): void
    {
        $publication = new MeliPublication;
        $publication->raw = [
            'seller_custom_field' => 'KIT0015',
            'pictures' => [
                ['id' => 'photo-1', 'secure_url' => 'https://http2.mlstatic.com/kit.jpg'],
            ],
        ];

        $this->assertSame(
            'https://http2.mlstatic.com/kit.jpg',
            app(PurchaseOrderMeliImageResolver::class)->imageForPublication($publication, 'KIT0015'),
        );
    }

    public function test_uses_exact_variation_photo_instead_of_listing_cover(): void
    {
        $publication = new MeliPublication;
        $publication->raw = [
            'pictures' => [
                ['id' => 'blue', 'secure_url' => 'https://http2.mlstatic.com/blue.jpg'],
                ['id' => 'pink', 'secure_url' => 'https://http2.mlstatic.com/pink.jpg'],
            ],
            'variations' => [
                ['id' => 10, 'seller_custom_field' => 'ATOMIZADORBCOAF', 'picture_ids' => ['blue']],
                ['id' => 11, 'seller_custom_field' => 'ATOMIZADORBR', 'picture_ids' => ['pink']],
            ],
            'thumbnail' => 'https://http2.mlstatic.com/blue.jpg',
        ];

        $resolver = app(PurchaseOrderMeliImageResolver::class);

        $this->assertSame(
            'https://http2.mlstatic.com/pink.jpg',
            $resolver->imageForPublication($publication, 'ATOMIZADORBR', '11'),
        );
        $this->assertSame(
            'https://http2.mlstatic.com/pink.jpg',
            $resolver->imageForPublication($publication, 'ATOMIZADORBR'),
        );
        $this->assertNull($resolver->imageForPublication($publication, 'ATOMIZADORBR', '10'));
        $this->assertNull($resolver->imageForPublication($publication, 'UNKNOWN'));
    }

    public function test_does_not_use_untrusted_photo_hosts(): void
    {
        $publication = new MeliPublication;
        $publication->raw = [
            'seller_custom_field' => 'EXPRIMIDOR',
            'pictures' => [['url' => 'https://example.com/untrusted.jpg']],
        ];

        $this->assertNull(
            app(PurchaseOrderMeliImageResolver::class)->imageForPublication($publication, 'EXPRIMIDOR'),
        );
    }

    public function test_does_not_accept_a_photo_for_a_different_seller_sku(): void
    {
        $publication = new MeliPublication;
        $publication->raw = [
            'seller_custom_field' => 'DIFFERENT-SKU',
            'pictures' => [['secure_url' => 'https://http2.mlstatic.com/photo.jpg']],
        ];

        $this->assertNull(
            app(PurchaseOrderMeliImageResolver::class)->imageForPublication($publication, 'KIT0015'),
        );
    }
}
