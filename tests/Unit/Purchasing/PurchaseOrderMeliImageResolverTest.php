<?php

namespace Tests\Unit\Purchasing;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderMeliImageResolverTest extends TestCase
{
    private PurchaseOrderMeliImageResolver $resolver;

    private User $user;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        $this->createTestSchema();

        $this->user = User::forceCreate([
            'name' => 'Buyer SBS',
            'email' => 'buyer@sbs.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'BOD-1',
            'name' => 'Bodega Principal',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->resolver = app(PurchaseOrderMeliImageResolver::class);
    }

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
            $this->resolver->imageForPublication($publication, 'KIT0015'),
        );
    }

    public function test_matches_seller_sku_from_attributes_values_and_sku_attribute(): void
    {
        $pubWithValues = new MeliPublication;
        $pubWithValues->raw = [
            'attributes' => [
                ['id' => 'SELLER_SKU', 'values' => [['name' => 'CAPA-NEGRA-01']]],
            ],
            'pictures' => [
                ['secure_url' => 'https://http2.mlstatic.com/D_NQ_NP_capa.jpg'],
            ],
        ];

        $pubWithSkuAttr = new MeliPublication;
        $pubWithSkuAttr->raw = [
            'attributes' => [
                ['id' => 'SKU', 'value_name' => 'TIJERA-6P'],
            ],
            'pictures' => [
                ['url' => 'http://http2.mlstatic.com/D_NQ_NP_tijera.jpg'],
            ],
        ];

        $this->assertSame(
            'https://http2.mlstatic.com/D_NQ_NP_capa.jpg',
            $this->resolver->imageForPublication($pubWithValues, 'capa-negra-01'),
        );
        $this->assertSame(
            'https://http2.mlstatic.com/D_NQ_NP_tijera.jpg',
            $this->resolver->imageForPublication($pubWithSkuAttr, 'TIJERA-6P'),
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

        $this->assertSame(
            'https://http2.mlstatic.com/pink.jpg',
            $this->resolver->imageForPublication($publication, 'ATOMIZADORBR', '11'),
        );
        $this->assertSame(
            'https://http2.mlstatic.com/pink.jpg',
            $this->resolver->imageForPublication($publication, 'ATOMIZADORBR'),
        );
        $this->assertNull($this->resolver->imageForPublication($publication, 'ATOMIZADORBR', '10'));
        $this->assertNull($this->resolver->imageForPublication($publication, 'UNKNOWN'));
    }

    public function test_rejects_untrusted_and_malformed_external_photo_urls(): void
    {
        $unsafeUrls = [
            'https://example.com/untrusted.jpg',
            'https://evilmlstatic.com/fake.jpg',
            'https://mlstatic.com.evil.com/fake.jpg',
            'https://user:pass@http2.mlstatic.com/auth.jpg',
            'https://http2.mlstatic.com:8443/port.jpg',
            'javascript:alert(1)',
            'data:image/png;base64,iVBORw0KGgo=',
            'ftp://http2.mlstatic.com/pic.jpg',
            "https://http2.mlstatic.com/pic.jpg\r\nX-Injected: 1",
            'https://http2.mlstatic.com/',
            '',
            null,
            ['nested' => 'bad'],
        ];

        foreach ($unsafeUrls as $candidate) {
            $this->assertNull($this->resolver->trustedUrl($candidate), 'Expected unsafe URL to be rejected: '.json_encode($candidate));
        }

        $this->assertSame(
            'https://http2.mlstatic.com/D_NQ_NP_123-O.jpg',
            $this->resolver->trustedUrl('http://http2.mlstatic.com/D_NQ_NP_123-O.jpg'),
        );
    }

    public function test_rejects_publications_without_sku_and_conflicting_skus(): void
    {
        $noSkuPub = new MeliPublication;
        $noSkuPub->sku = '';
        $noSkuPub->raw = [
            'pictures' => [['secure_url' => 'https://http2.mlstatic.com/nosku.jpg']],
        ];

        $differentSkuPub = new MeliPublication;
        $differentSkuPub->sku = 'DIFFERENT-SKU';
        $differentSkuPub->raw = [
            'seller_custom_field' => 'DIFFERENT-SKU',
            'pictures' => [['secure_url' => 'https://http2.mlstatic.com/photo.jpg']],
        ];

        $this->assertNull($this->resolver->imageForPublication($noSkuPub, 'KIT0015'));
        $this->assertNull($this->resolver->imageForPublication($differentSkuPub, 'KIT0015'));
    }

    public function test_handles_invalid_image_data_gracefully(): void
    {
        $pub = new MeliPublication;
        $pub->raw = [
            'seller_custom_field' => 'BADIMG-1',
            'pictures' => [
                null,
                [],
                ['id' => 'broken', 'secure_url' => 'not-a-url'],
                ['id' => 'untrusted', 'url' => 'https://attacker.invalid/x.jpg'],
            ],
            'thumbnail' => 'invalid-thumb',
        ];

        $this->assertNull($this->resolver->imageForPublication($pub, 'BADIMG-1'));
    }

    public function test_prioritizes_explicit_channel_link_respecting_account_and_variation(): void
    {
        $acc1 = $this->createMeliAccount('111111', 'SBS_PRINCIPAL', true);
        $acc2 = $this->createMeliAccount('222222', 'SBS_CHINA', false);

        $product = $this->createProduct('ATOM-ROSA', 'Atomizador Rosa');
        $order = $this->createOrderWithItems([$product]);
        $item = $order->items->firstOrFail();

        // Explicit link points to account 2 (by nickname!), MLM200, variation 22
        InventoryChannelLink::create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => 'SBS_CHINA',
            'external_listing_id' => 'MLM200',
            'external_variant_id' => '22',
            'is_active' => true,
            'identity_key' => 'ml:SBS_CHINA:MLM200:22',
        ]);

        // Unlinked active publication on account 1 with same SKU (should lose to explicit link)
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc1->id,
            'sku' => 'ATOM-ROSA',
            'mlm' => 'MLM100',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'ATOM-ROSA',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/acc1-fallback.jpg']],
                ],
            ],
        ]);

        // Linked publication on account 2 with color variations
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc2->id,
            'sku' => 'ATOM-AZUL', // Top-level column has 1st variation's SKU
            'mlm' => 'MLM200',
            'status' => 'active',
            'last_sync_at' => now()->subHour(),
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'pic-azul', 'secure_url' => 'https://http2.mlstatic.com/atom-azul.jpg'],
                        ['id' => 'pic-rosa', 'secure_url' => 'https://http2.mlstatic.com/atom-rosa.jpg'],
                    ],
                    'variations' => [
                        ['id' => 21, 'seller_custom_field' => 'ATOM-AZUL', 'picture_ids' => ['pic-azul']],
                        ['id' => 22, 'seller_custom_field' => 'ATOM-ROSA', 'picture_ids' => ['pic-rosa']],
                    ],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);
        $entry = $resolved[$item->id];

        $this->assertSame('https://http2.mlstatic.com/atom-rosa.jpg', $entry['url']);
        $this->assertSame(PurchaseOrderMeliImageResolver::SOURCE_CHANNEL_LINK_PUBLICATION, $entry['source']);
        $this->assertSame('MLM200', $entry['mlm']);
        $this->assertSame('22', $entry['variation_id']);
        $this->assertSame($acc2->id, $entry['meli_account_id']);
        $this->assertFalse($entry['ambiguous']);
    }

    public function test_account_key_matching_supports_meli_user_id_and_blocks_wrong_account(): void
    {
        $acc1 = $this->createMeliAccount('555000111', 'TIENDA_A', true);
        $acc2 = $this->createMeliAccount('555000222', 'TIENDA_B', false);

        $product = $this->createProduct('CEPILLO-PRO', 'Cepillo Pro');
        $order = $this->createOrderWithItems([$product]);
        $item = $order->items->firstOrFail();

        // Link uses meli_user_id (numeric string) pointing to acc2
        InventoryChannelLink::create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '555000222',
            'external_listing_id' => 'MLM900',
            'is_active' => true,
            'identity_key' => 'ml:555000222:MLM900',
        ]);

        // Only acc1 has MLM900 in publications (wrong account!) -> should not match acc1 via link,
        // while acc2 has MLM900 in price manager!
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc1->id,
            'sku' => 'OTHER-SKU',
            'mlm' => 'MLM900',
            'status' => 'active',
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'CEPILLO-PRO',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/wrong-acc.jpg']],
                ],
            ],
        ]);

        MeliPriceManagerItem::create([
            'meli_account_id' => $acc2->id,
            'meli_item_id' => 'MLM900',
            'sku' => 'CEPILLO-PRO',
            'title' => 'Cepillo Pro',
            'current_price' => 199.00,
            'status' => 'active',
            'raw_item' => [
                'id' => 'MLM900',
                'seller_custom_field' => 'CEPILLO-PRO',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/acc2-pm.jpg']],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);
        $this->assertSame('https://http2.mlstatic.com/acc2-pm.jpg', $resolved[$item->id]['url']);
        $this->assertSame(PurchaseOrderMeliImageResolver::SOURCE_CHANNEL_LINK_PRICE_MANAGER, $resolved[$item->id]['source']);
        $this->assertSame($acc2->id, $resolved[$item->id]['meli_account_id']);
    }

    public function test_finds_secondary_variation_sku_embedded_in_raw_json_without_channel_link(): void
    {
        $acc = $this->createMeliAccount('333333', 'SBS_MAIN', true);
        $productPink = $this->createProduct('ATOM-BR', 'Atomizador Rosa');
        $order = $this->createOrderWithItems([$productPink]);
        $item = $order->items->firstOrFail();

        // Top-level sku column ONLY has the first variation's SKU (ATOM-AZ), just like MeliAccountPublicationSyncService saves it!
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'ATOM-AZ',
            'mlm' => 'MLM777',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'p-az', 'secure_url' => 'https://http2.mlstatic.com/az.jpg'],
                        ['id' => 'p-br', 'secure_url' => 'https://http2.mlstatic.com/br.jpg'],
                    ],
                    'variations' => [
                        ['id' => 701, 'seller_custom_field' => 'ATOM-AZ', 'picture_ids' => ['p-az']],
                        ['id' => 702, 'seller_custom_field' => 'ATOM-BR', 'picture_ids' => ['p-br']],
                    ],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);
        $entry = $resolved[$item->id];

        $this->assertSame('https://http2.mlstatic.com/br.jpg', $entry['url']);
        $this->assertSame(PurchaseOrderMeliImageResolver::SOURCE_MELI_PUBLICATION_VARIATION_SKU, $entry['source']);
        $this->assertSame('MLM777', $entry['mlm']);
        $this->assertSame('702', $entry['variation_id']);
    }

    public function test_prefers_active_over_paused_when_photos_agree_and_falls_back_to_historical_publications(): void
    {
        $acc = $this->createMeliAccount('444444', 'SBS_MAIN', true);
        $prodActive = $this->createProduct('SKU-MULTI', 'Producto Multi');
        $prodHistorical = $this->createProduct('SKU-HIST', 'Producto Histórico');

        $order = $this->createOrderWithItems([$prodActive, $prodHistorical]);
        $itemActive = $order->items->firstWhere('inventory_product_id', $prodActive->id);
        $itemHist = $order->items->firstWhere('inventory_product_id', $prodHistorical->id);

        // Closed/newer vs active/older for SKU-MULTI with the SAME agreed photo -> must pick active!
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-MULTI',
            'mlm' => 'MLM-CLOSED',
            'status' => 'closed',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-MULTI',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/agreed.jpg']],
                ],
            ],
        ]);

        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-MULTI',
            'mlm' => 'MLM-ACTIVE',
            'status' => 'active',
            'last_sync_at' => now()->subDay(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-MULTI',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/agreed.jpg']],
                ],
            ],
        ]);

        // Only paused/historical publication for SKU-HIST -> still resolves cleanly!
        MeliPublication::forceCreate([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-HIST',
            'mlm' => 'MLM-PAUSED',
            'status' => 'paused',
            'is_current' => false,
            'last_sync_at' => now()->subMonth(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-HIST',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/paused.jpg']],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);

        $this->assertSame('https://http2.mlstatic.com/agreed.jpg', $resolved[$itemActive->id]['url']);
        $this->assertSame('MLM-ACTIVE', $resolved[$itemActive->id]['mlm']);
        $this->assertFalse($resolved[$itemActive->id]['ambiguous']);

        $this->assertSame('https://http2.mlstatic.com/paused.jpg', $resolved[$itemHist->id]['url']);
        $this->assertSame('paused', $resolved[$itemHist->id]['listing_status']);
    }

    public function test_account_key_matching_rejects_unknown_ambiguous_and_invalid_default_accounts(): void
    {
        $acc1 = $this->createMeliAccount('100001', 'TIENDA_UNO', false);
        $acc2 = $this->createMeliAccount('100002', 'TIENDA_DOS', false);
        $accountsWithoutDefault = collect([$acc1, $acc2]);

        // Unknown identifiers (string or numeric) must NEVER be accepted silently
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('CUENTA_DESCONOCIDA', $acc1->id, $accountsWithoutDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('999999', $acc1->id, $accountsWithoutDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('CUENTA_DESCONOCIDA', $acc1->id, collect()));

        // Publication without meli_account_id cannot match a link requiring a specific account
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('TIENDA_UNO', null, $accountsWithoutDefault));
        $this->assertTrue($this->resolver->accountKeyMatchesAccount('', null, $accountsWithoutDefault));

        // 'default' without any account marked is_default=true must be rejected
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('default', $acc1->id, $accountsWithoutDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('default', $acc2->id, $accountsWithoutDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('default', $acc1->id, collect()));

        // 'default' with MULTIPLE accounts marked is_default=true is ambiguous and must be rejected
        $acc1->update(['is_default' => true]);
        $acc2->update(['is_default' => true]);
        $accountsWithMultipleDefaults = collect([$acc1->fresh(), $acc2->fresh()]);
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('default', $acc1->id, $accountsWithMultipleDefaults));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('default', $acc2->id, $accountsWithMultipleDefaults));

        // 'default' with EXACTLY ONE valid default account accepts only that account
        $acc2->update(['is_default' => false]);
        $accountsWithSingleDefault = collect([$acc1->fresh(), $acc2->fresh()]);
        $this->assertTrue($this->resolver->accountKeyMatchesAccount('default', $acc1->id, $accountsWithSingleDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('default', $acc2->id, $accountsWithSingleDefault));

        // Multi-account exact matches by ID, meli_user_id, and nickname (case-insensitive)
        $this->assertTrue($this->resolver->accountKeyMatchesAccount((string) $acc2->id, $acc2->id, $accountsWithSingleDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount((string) $acc2->id, $acc1->id, $accountsWithSingleDefault));
        $this->assertTrue($this->resolver->accountKeyMatchesAccount('100002', $acc2->id, $accountsWithSingleDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('100002', $acc1->id, $accountsWithSingleDefault));
        $this->assertTrue($this->resolver->accountKeyMatchesAccount('tienda_dos', $acc2->id, $accountsWithSingleDefault));
        $this->assertFalse($this->resolver->accountKeyMatchesAccount('tienda_dos', $acc1->id, $accountsWithSingleDefault));

        // End-to-end check: link with unknown account_key or invalid 'default' does not resolve to another account's listing
        $acc1->update(['is_default' => false]);
        $prodUnknown = $this->createProduct('LINK-UNK-ACC', 'Producto Cuenta Desconocida');
        $prodBadDefault = $this->createProduct('LINK-BAD-DEF', 'Producto Default Inválido');
        $order = $this->createOrderWithItems([$prodUnknown, $prodBadDefault]);
        $itemUnknown = $order->items->firstWhere('inventory_product_id', $prodUnknown->id);
        $itemBadDefault = $order->items->firstWhere('inventory_product_id', $prodBadDefault->id);

        InventoryChannelLink::create([
            'inventory_product_id' => $prodUnknown->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => 'CUENTA_INEXISTENTE',
            'external_listing_id' => 'MLM-UNK-1',
            'is_active' => true,
            'identity_key' => 'ml:CUENTA_INEXISTENTE:MLM-UNK-1',
        ]);

        InventoryChannelLink::create([
            'inventory_product_id' => $prodBadDefault->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => 'default',
            'external_listing_id' => 'MLM-DEF-1',
            'is_active' => true,
            'identity_key' => 'ml:default:MLM-DEF-1',
        ]);

        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc1->id,
            'sku' => 'OTHER-1',
            'mlm' => 'MLM-UNK-1',
            'status' => 'active',
            'raw' => [
                'item' => [
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/should-not-match-1.jpg']],
                ],
            ],
        ]);

        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc2->id,
            'sku' => 'OTHER-2',
            'mlm' => 'MLM-DEF-1',
            'status' => 'active',
            'raw' => [
                'item' => [
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/should-not-match-2.jpg']],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);
        $this->assertNull($resolved[$itemUnknown->id]['url']);
        $this->assertSame('account_mismatch', $resolved[$itemUnknown->id]['reason']);
        $this->assertNull($resolved[$itemBadDefault->id]['url']);
        $this->assertSame('account_mismatch', $resolved[$itemBadDefault->id]['reason']);
    }

    public function test_shared_sku_across_multiple_publications_with_different_photos_requires_review_without_explicit_link(): void
    {
        Log::spy();

        $acc1 = $this->createMeliAccount('300001', 'CUENTA_A', true);
        $acc2 = $this->createMeliAccount('300002', 'CUENTA_B', false);

        $prodPubConflict = $this->createProduct('SKU-CONFLICT-PUB', 'Producto SKU Compartido Pubs');
        $prodPmConflict = $this->createProduct('SKU-CONFLICT-PM', 'Producto SKU Compartido PM');
        $order = $this->createOrderWithItems([$prodPubConflict, $prodPmConflict]);
        $itemPub = $order->items->firstWhere('inventory_product_id', $prodPubConflict->id);
        $itemPm = $order->items->firstWhere('inventory_product_id', $prodPmConflict->id);

        // Two publications with the SAME SKU but DIFFERENT photos (no explicit channel link)
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc1->id,
            'sku' => 'SKU-CONFLICT-PUB',
            'mlm' => 'MLM-CONF-1',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-CONFLICT-PUB',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/conflict-a.jpg']],
                ],
            ],
        ]);

        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc2->id,
            'sku' => 'SKU-CONFLICT-PUB',
            'mlm' => 'MLM-CONF-2',
            'status' => 'active',
            'last_sync_at' => now()->subHour(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-CONFLICT-PUB',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/conflict-b.jpg']],
                ],
            ],
        ]);

        // Even if price manager has one entry for SKU-CONFLICT-PUB, ambiguity in meli_publications must NOT be bypassed
        MeliPriceManagerItem::create([
            'meli_account_id' => $acc1->id,
            'meli_item_id' => 'MLM-CONF-1',
            'sku' => 'SKU-CONFLICT-PUB',
            'title' => 'Producto Conflicto',
            'current_price' => 150,
            'status' => 'active',
            'raw_item' => [
                'id' => 'MLM-CONF-1',
                'seller_custom_field' => 'SKU-CONFLICT-PUB',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/conflict-a.jpg']],
            ],
        ]);

        // Two price manager items with the SAME SKU and DIFFERENT photos
        MeliPriceManagerItem::create([
            'meli_account_id' => $acc1->id,
            'meli_item_id' => 'MLM-PM-CONF-1',
            'sku' => 'SKU-CONFLICT-PM',
            'title' => 'PM Conflicto 1',
            'current_price' => 120,
            'status' => 'active',
            'raw_item' => [
                'id' => 'MLM-PM-CONF-1',
                'seller_custom_field' => 'SKU-CONFLICT-PM',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/pm-conflict-1.jpg']],
            ],
        ]);

        MeliPriceManagerItem::create([
            'meli_account_id' => $acc2->id,
            'meli_item_id' => 'MLM-PM-CONF-2',
            'sku' => 'SKU-CONFLICT-PM',
            'title' => 'PM Conflicto 2',
            'current_price' => 125,
            'status' => 'active',
            'raw_item' => [
                'id' => 'MLM-PM-CONF-2',
                'seller_custom_field' => 'SKU-CONFLICT-PM',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/pm-conflict-2.jpg']],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, true);

        // Neither item auto-selects the first photo
        $this->assertNull($resolved[$itemPub->id]['url']);
        $this->assertTrue($resolved[$itemPub->id]['ambiguous']);
        $this->assertSame('multiple_publications_conflicting_photos', $resolved[$itemPub->id]['reason']);
        $this->assertCount(2, $resolved[$itemPub->id]['ambiguous_matches']);

        $this->assertNull($resolved[$itemPm->id]['url']);
        $this->assertTrue($resolved[$itemPm->id]['ambiguous']);
        $this->assertSame('multiple_publications_conflicting_photos', $resolved[$itemPm->id]['reason']);
        $this->assertCount(2, $resolved[$itemPm->id]['ambiguous_matches']);

        $this->assertSame([], $this->resolver->forOrder($order));
        Log::shouldHaveReceived('info')->atLeast()->once();

        // Adding an explicit channel link disambiguates cleanly
        InventoryChannelLink::create([
            'inventory_product_id' => $prodPubConflict->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $acc2->id,
            'external_listing_id' => 'MLM-CONF-2',
            'is_active' => true,
            'identity_key' => "ml:{$acc2->id}:MLM-CONF-2",
        ]);

        $disambiguated = $this->resolver->resolveForOrder($order, false);
        $this->assertSame('https://http2.mlstatic.com/conflict-b.jpg', $disambiguated[$itemPub->id]['url']);
        $this->assertFalse($disambiguated[$itemPub->id]['ambiguous']);
        $this->assertSame(PurchaseOrderMeliImageResolver::SOURCE_CHANNEL_LINK_PUBLICATION, $disambiguated[$itemPub->id]['source']);
    }

    public function test_cross_table_conflict_between_meli_publications_and_price_manager_for_different_mlms_requires_review(): void
    {
        Log::spy();

        $acc1 = $this->createMeliAccount('310001', 'CUENTA_PUB', true);
        $acc2 = $this->createMeliAccount('310002', 'CUENTA_PM', false);

        $product = $this->createProduct('SKU-CROSS-TABLE', 'Producto En Dos Tablas Con Distinto MLM');
        $order = $this->createOrderWithItems([$product]);
        $item = $order->items->firstOrFail();

        // Account 1 synced into meli_publications under MLM-CROSS-A
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc1->id,
            'sku' => 'SKU-CROSS-TABLE',
            'mlm' => 'MLM-CROSS-A',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-CROSS-TABLE',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/cross-a.jpg']],
                ],
            ],
        ]);

        // Account 2 synced into meli_price_manager_items under a DIFFERENT listing MLM-CROSS-B with a different photo
        MeliPriceManagerItem::create([
            'meli_account_id' => $acc2->id,
            'meli_item_id' => 'MLM-CROSS-B',
            'sku' => 'SKU-CROSS-TABLE',
            'title' => 'Producto En Price Manager',
            'current_price' => 210,
            'status' => 'active',
            'last_synced_at' => now(),
            'raw_item' => [
                'id' => 'MLM-CROSS-B',
                'seller_custom_field' => 'SKU-CROSS-TABLE',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/cross-b.jpg']],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, true);
        $entry = $resolved[$item->id];

        $this->assertNull($entry['url']);
        $this->assertTrue($entry['ambiguous']);
        $this->assertSame('multiple_publications_conflicting_photos', $entry['reason']);
        $this->assertCount(2, $entry['ambiguous_matches']);
        $this->assertEqualsCanonicalizing(
            ['MLM-CROSS-A', 'MLM-CROSS-B'],
            array_column($entry['ambiguous_matches'], 'mlm'),
        );
        $this->assertSame([], $this->resolver->forOrder($order));
        Log::shouldHaveReceived('info')->atLeast()->once();

        // Explicit channel link to MLM-CROSS-B resolves cleanly without ambiguity
        InventoryChannelLink::create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $acc2->id,
            'external_listing_id' => 'MLM-CROSS-B',
            'is_active' => true,
            'identity_key' => "ml:{$acc2->id}:MLM-CROSS-B",
        ]);

        $disambiguated = $this->resolver->resolveForOrder($order, false);
        $this->assertSame('https://http2.mlstatic.com/cross-b.jpg', $disambiguated[$item->id]['url']);
        $this->assertFalse($disambiguated[$item->id]['ambiguous']);
        $this->assertSame(PurchaseOrderMeliImageResolver::SOURCE_CHANNEL_LINK_PRICE_MANAGER, $disambiguated[$item->id]['source']);
    }

    public function test_same_mlm_snapshots_across_tables_or_history_are_reconciled_by_vigencia_without_false_conflict(): void
    {
        $acc = $this->createMeliAccount('320001', 'CUENTA_SNAP', true);

        $prodPmFresher = $this->createProduct('SKU-SNAP-1', 'Snapshot PM Más Reciente');
        $prodPubFresher = $this->createProduct('SKU-SNAP-2', 'Snapshot Pub Más Reciente');
        $prodHistCurrent = $this->createProduct('SKU-SNAP-3', 'Snapshot Vigente vs Histórico');
        $prodTiedConflict = $this->createProduct('SKU-SNAP-TIE', 'Snapshots Empatados Con Fotos Distintas');

        $order = $this->createOrderWithItems([$prodPmFresher, $prodPubFresher, $prodHistCurrent, $prodTiedConflict]);
        $itemPmFresher = $order->items->firstWhere('inventory_product_id', $prodPmFresher->id);
        $itemPubFresher = $order->items->firstWhere('inventory_product_id', $prodPubFresher->id);
        $itemHistCurrent = $order->items->firstWhere('inventory_product_id', $prodHistCurrent->id);
        $itemTied = $order->items->firstWhere('inventory_product_id', $prodTiedConflict->id);

        // Case 1: Same MLM-SNAP-1, older in meli_publications, fresher in meli_price_manager_items -> picks fresher PM photo
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SNAP-1',
            'mlm' => 'MLM-SNAP-1',
            'status' => 'active',
            'is_current' => true,
            'last_sync_at' => now()->subDays(5),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SNAP-1',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/snap1-old.jpg']],
                ],
            ],
        ]);
        MeliPriceManagerItem::create([
            'meli_account_id' => $acc->id,
            'meli_item_id' => 'MLM-SNAP-1',
            'sku' => 'SKU-SNAP-1',
            'title' => 'Snap 1',
            'current_price' => 100,
            'status' => 'active',
            'last_synced_at' => now(),
            'raw_item' => [
                'id' => 'MLM-SNAP-1',
                'seller_custom_field' => 'SKU-SNAP-1',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/snap1-fresh.jpg']],
            ],
        ]);

        // Case 2: Same MLM-SNAP-2, fresher in meli_publications, older in meli_price_manager_items -> picks fresher Pub photo
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SNAP-2',
            'mlm' => 'MLM-SNAP-2',
            'status' => 'active',
            'is_current' => true,
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SNAP-2',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/snap2-fresh.jpg']],
                ],
            ],
        ]);
        MeliPriceManagerItem::create([
            'meli_account_id' => $acc->id,
            'meli_item_id' => 'MLM-SNAP-2',
            'sku' => 'SKU-SNAP-2',
            'title' => 'Snap 2',
            'current_price' => 100,
            'status' => 'active',
            'last_synced_at' => now()->subDays(3),
            'raw_item' => [
                'id' => 'MLM-SNAP-2',
                'seller_custom_field' => 'SKU-SNAP-2',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/snap2-old.jpg']],
            ],
        ]);

        // Case 3: Same MLM-SNAP-3 twice in meli_publications (historical is_current=false vs is_current=true) -> picks current
        MeliPublication::forceCreate([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SNAP-3',
            'mlm' => 'MLM-SNAP-3',
            'status' => 'active',
            'is_current' => false,
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SNAP-3',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/snap3-historical.jpg']],
                ],
            ],
        ]);
        MeliPublication::forceCreate([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SNAP-3',
            'mlm' => 'MLM-SNAP-3',
            'status' => 'active',
            'is_current' => true,
            'last_sync_at' => now()->subHour(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SNAP-3',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/snap3-current.jpg']],
                ],
            ],
        ]);

        // Case 4: Same MLM-SNAP-TIE with IDENTICAL vigencia (same timestamp, both current & active) and DIFFERENT photos -> ambiguous
        $exactSameTimestamp = now()->startOfMinute();
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SNAP-TIE',
            'mlm' => 'MLM-SNAP-TIE',
            'status' => 'active',
            'is_current' => true,
            'last_sync_at' => $exactSameTimestamp,
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SNAP-TIE',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/tie-pub.jpg']],
                ],
            ],
        ]);
        MeliPriceManagerItem::create([
            'meli_account_id' => $acc->id,
            'meli_item_id' => 'MLM-SNAP-TIE',
            'sku' => 'SKU-SNAP-TIE',
            'title' => 'Snap Tie',
            'current_price' => 100,
            'status' => 'active',
            'last_synced_at' => $exactSameTimestamp,
            'raw_item' => [
                'id' => 'MLM-SNAP-TIE',
                'seller_custom_field' => 'SKU-SNAP-TIE',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/tie-pm.jpg']],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);

        $this->assertSame('https://http2.mlstatic.com/snap1-fresh.jpg', $resolved[$itemPmFresher->id]['url']);
        $this->assertFalse($resolved[$itemPmFresher->id]['ambiguous']);
        $this->assertSame('MLM-SNAP-1', $resolved[$itemPmFresher->id]['mlm']);

        $this->assertSame('https://http2.mlstatic.com/snap2-fresh.jpg', $resolved[$itemPubFresher->id]['url']);
        $this->assertFalse($resolved[$itemPubFresher->id]['ambiguous']);
        $this->assertSame('MLM-SNAP-2', $resolved[$itemPubFresher->id]['mlm']);

        $this->assertSame('https://http2.mlstatic.com/snap3-current.jpg', $resolved[$itemHistCurrent->id]['url']);
        $this->assertFalse($resolved[$itemHistCurrent->id]['ambiguous']);

        $this->assertNull($resolved[$itemTied->id]['url']);
        $this->assertTrue($resolved[$itemTied->id]['ambiguous']);
        $this->assertSame('conflicting_listing_snapshots', $resolved[$itemTied->id]['reason']);
    }

    public function test_same_sku_as_simple_publication_and_secondary_variation_of_another_publication_prevents_wrong_assignment(): void
    {
        $acc = $this->createMeliAccount('330001', 'CUENTA_MIX', true);

        $prodConflict = $this->createProduct('SKU-SIMPLE-VAR-CONF', 'SKU En Simple Y Variación Secundaria Conflicto');
        $prodAgreed = $this->createProduct('SKU-SIMPLE-VAR-OK', 'SKU En Simple Y Variación Secundaria Misma Foto');
        $order = $this->createOrderWithItems([$prodConflict, $prodAgreed]);
        $itemConflict = $order->items->firstWhere('inventory_product_id', $prodConflict->id);
        $itemAgreed = $order->items->firstWhere('inventory_product_id', $prodAgreed->id);

        // Simple publication with SKU-SIMPLE-VAR-CONF
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SIMPLE-VAR-CONF',
            'mlm' => 'MLM-SIMPLE-1',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SIMPLE-VAR-CONF',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/simple-photo.jpg']],
                ],
            ],
        ]);

        // Separate multi-variation listing whose top-level `sku` column holds Variation 1,
        // while Variation 2 shares SKU-SIMPLE-VAR-CONF with a DIFFERENT photo!
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'OTHER-PRIMARY-SKU',
            'mlm' => 'MLM-MULTI-VAR-1',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'v1', 'secure_url' => 'https://http2.mlstatic.com/other-var.jpg'],
                        ['id' => 'v2', 'secure_url' => 'https://http2.mlstatic.com/secondary-var-photo.jpg'],
                    ],
                    'variations' => [
                        ['id' => 901, 'seller_custom_field' => 'OTHER-PRIMARY-SKU', 'picture_ids' => ['v1']],
                        ['id' => 902, 'seller_custom_field' => 'SKU-SIMPLE-VAR-CONF', 'picture_ids' => ['v2']],
                    ],
                ],
            ],
        ]);

        // Simple + secondary variation with the EXACT SAME agreed photo -> resolves cleanly!
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'SKU-SIMPLE-VAR-OK',
            'mlm' => 'MLM-SIMPLE-2',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-SIMPLE-VAR-OK',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/same-simple-var.jpg']],
                ],
            ],
        ]);
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'OTHER-PRIMARY-SKU-2',
            'mlm' => 'MLM-MULTI-VAR-2',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'v1', 'secure_url' => 'https://http2.mlstatic.com/other-var-2.jpg'],
                        ['id' => 'v2', 'secure_url' => 'https://http2.mlstatic.com/same-simple-var.jpg'],
                    ],
                    'variations' => [
                        ['id' => 911, 'seller_custom_field' => 'OTHER-PRIMARY-SKU-2', 'picture_ids' => ['v1']],
                        ['id' => 912, 'seller_custom_field' => 'SKU-SIMPLE-VAR-OK', 'picture_ids' => ['v2']],
                    ],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);

        // Conflicting simple vs secondary variation must NOT auto-select
        $this->assertNull($resolved[$itemConflict->id]['url']);
        $this->assertTrue($resolved[$itemConflict->id]['ambiguous']);
        $this->assertSame('multiple_publications_conflicting_photos', $resolved[$itemConflict->id]['reason']);
        $this->assertCount(2, $resolved[$itemConflict->id]['ambiguous_matches']);
        $this->assertEqualsCanonicalizing(
            ['MLM-SIMPLE-1', 'MLM-MULTI-VAR-1'],
            array_column($resolved[$itemConflict->id]['ambiguous_matches'], 'mlm'),
        );

        // Identical photo across simple and secondary variation resolves without conflict
        $this->assertSame('https://http2.mlstatic.com/same-simple-var.jpg', $resolved[$itemAgreed->id]['url']);
        $this->assertFalse($resolved[$itemAgreed->id]['ambiguous']);

        // Adding explicit channel link to the secondary variation disambiguates cleanly
        InventoryChannelLink::create([
            'inventory_product_id' => $prodConflict->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $acc->id,
            'external_listing_id' => 'MLM-MULTI-VAR-1',
            'external_variant_id' => '902',
            'is_active' => true,
            'identity_key' => "ml:{$acc->id}:MLM-MULTI-VAR-1:902",
        ]);

        $disambiguated = $this->resolver->resolveForOrder($order, false);
        $this->assertSame('https://http2.mlstatic.com/secondary-var-photo.jpg', $disambiguated[$itemConflict->id]['url']);
        $this->assertFalse($disambiguated[$itemConflict->id]['ambiguous']);
        $this->assertSame('902', $disambiguated[$itemConflict->id]['variation_id']);
    }

    public function test_price_manager_fallback_requires_verified_identity_and_rejects_truncated_snapshots(): void
    {
        $acc = $this->createMeliAccount('666666', 'SBS_MAIN', true);
        $prodOk = $this->createProduct('PM-OK', 'PM Verificado');
        $prodUnverified = $this->createProduct('PM-NO-SKU', 'PM Sin SKU En Raw');
        $prodTruncated = $this->createProduct('PM-TRUNC', 'PM Truncado');

        $order = $this->createOrderWithItems([$prodOk, $prodUnverified, $prodTruncated]);
        $itemOk = $order->items->firstWhere('inventory_product_id', $prodOk->id);
        $itemUnverified = $order->items->firstWhere('inventory_product_id', $prodUnverified->id);
        $itemTruncated = $order->items->firstWhere('inventory_product_id', $prodTruncated->id);

        MeliPriceManagerItem::create([
            'meli_account_id' => $acc->id,
            'meli_item_id' => 'MLM-PM-1',
            'sku' => 'PM-OK',
            'title' => 'PM Verificado',
            'current_price' => 100,
            'status' => 'active',
            'raw_item' => [
                'id' => 'MLM-PM-1',
                'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'PM-OK']],
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/pm-ok.jpg']],
            ],
        ]);

        MeliPriceManagerItem::create([
            'meli_account_id' => $acc->id,
            'meli_item_id' => 'MLM-PM-2',
            'sku' => 'PM-NO-SKU',
            'title' => 'Sin verificación en raw_item',
            'current_price' => 100,
            'status' => 'active',
            'thumbnail' => 'https://http2.mlstatic.com/generic-thumb.jpg',
            'raw_item' => [
                'id' => 'MLM-PM-2',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/unverified.jpg']],
            ],
        ]);

        MeliPriceManagerItem::create([
            'meli_account_id' => $acc->id,
            'meli_item_id' => 'MLM-PM-3',
            'sku' => 'PM-TRUNC',
            'title' => 'Truncado',
            'current_price' => 100,
            'status' => 'active',
            'thumbnail' => 'https://http2.mlstatic.com/truncated-thumb.jpg',
            'raw_item' => ['truncated' => true, 'id' => 'MLM-PM-3'],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);

        $this->assertSame('https://http2.mlstatic.com/pm-ok.jpg', $resolved[$itemOk->id]['url']);
        $this->assertSame(PurchaseOrderMeliImageResolver::SOURCE_MELI_PRICE_MANAGER_SKU, $resolved[$itemOk->id]['source']);

        $this->assertNull($resolved[$itemUnverified->id]['url']);
        $this->assertSame('publication_sku_unverified', $resolved[$itemUnverified->id]['reason']);

        $this->assertNull($resolved[$itemTruncated->id]['url']);
        $this->assertSame('price_manager_snapshot_incomplete', $resolved[$itemTruncated->id]['reason']);
    }

    public function test_does_not_guess_when_multiple_variations_in_same_listing_share_sku_and_logs_diagnostics(): void
    {
        Log::spy();

        $acc = $this->createMeliAccount('777777', 'SBS_MAIN', true);
        $product = $this->createProduct('DUP-VAR', 'Producto Duplicado');
        $order = $this->createOrderWithItems([$product]);
        $item = $order->items->firstOrFail();

        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $acc->id,
            'sku' => 'DUP-VAR',
            'mlm' => 'MLM-DUP',
            'status' => 'active',
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'c1', 'secure_url' => 'https://http2.mlstatic.com/c1.jpg'],
                        ['id' => 'c2', 'secure_url' => 'https://http2.mlstatic.com/c2.jpg'],
                    ],
                    'variations' => [
                        ['id' => 1, 'seller_custom_field' => 'DUP-VAR', 'picture_ids' => ['c1']],
                        ['id' => 2, 'seller_custom_field' => 'DUP-VAR', 'picture_ids' => ['c2']],
                    ],
                ],
            ],
        ]);

        $resolved = $this->resolver->resolveForOrder($order, true);

        $this->assertNull($resolved[$item->id]['url']);
        $this->assertTrue($resolved[$item->id]['ambiguous']);
        $this->assertSame('variation_ambiguous', $resolved[$item->id]['reason']);

        Log::shouldHaveReceived('info')->once();
    }

    public function test_large_order_resolution_avoids_n_plus_one_queries(): void
    {
        $acc = $this->createMeliAccount('888888', 'SBS_BULK', true);
        $products = [];

        for ($i = 1; $i <= 50; $i++) {
            $sku = sprintf('CHINA-SKU-%03d', $i);
            $prod = $this->createProduct($sku, "Producto China {$i}");
            $products[] = $prod;

            MeliPublication::create([
                'user_id' => $this->user->id,
                'meli_account_id' => $acc->id,
                'sku' => $sku,
                'mlm' => sprintf('MLM9000%03d', $i),
                'status' => 'active',
                'last_sync_at' => now(),
                'raw' => [
                    'item' => [
                        'seller_custom_field' => $sku,
                        'pictures' => [['secure_url' => "https://http2.mlstatic.com/p{$i}.jpg"]],
                    ],
                ],
            ]);
        }

        // Fetch fresh orders (5 items vs 50 items) WITHOUT preloading items.product to verify O(1) query count!
        $smallOrder = $this->createOrderWithItems(array_slice($products, 0, 5))->fresh();
        $largeOrder = $this->createOrderWithItems($products)->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $smallUrls = $this->resolver->forOrder($smallOrder);
        $smallQueries = DB::getQueryLog();

        DB::flushQueryLog();
        $largeUrls = $this->resolver->forOrder($largeOrder);
        $largeQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(5, $smallUrls);
        $this->assertCount(50, $largeUrls);
        $this->assertSame(
            count($smallQueries),
            count($largeQueries),
            'Query count must stay strictly O(1) between 5-item and 50-item orders (no N+1).'
        );
        $this->assertLessThanOrEqual(16, count($largeQueries), 'Expected constant bulk query count without N+1, got '.count($largeQueries));
    }

    private function createMeliAccount(string $meliUserId, string $nickname, bool $isDefault): MeliAccount
    {
        return MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => $meliUserId,
            'nickname' => $nickname,
            'is_default' => $isDefault,
        ]);
    }

    private function createProduct(string $sku, string $name): InventoryProduct
    {
        return InventoryProduct::create([
            'sku' => $sku,
            'name' => $name,
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 50,
            'brand' => 'CHINA',
        ]);
    }

    /** @param list<InventoryProduct> $products */
    private function createOrderWithItems(array $products): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261113-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'total_items_count' => count($products),
            'total_units_ordered' => count($products) * 10,
            'total_units_received' => 0,
            'subtotal' => 0,
            'total_cost' => 0,
        ]);

        foreach ($products as $product) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'inventory_product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity_ordered' => 10,
                'quantity_received' => 0,
                'unit_cost' => 50,
                'subtotal' => 500,
                'status' => 'PENDING',
            ]);
        }

        return $order->fresh(['items.product']);
    }

    private function createTestSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('source_mlm')->nullable();
            $table->string('status')->nullable();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('pictures')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });

        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id');
            $table->string('meli_item_id', 64);
            $table->string('sku', 191)->nullable();
            $table->string('title');
            $table->decimal('current_price', 15, 2)->default(0);
            $table->string('status', 64)->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
    }
}
