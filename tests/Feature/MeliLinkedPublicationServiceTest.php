<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\LinkedPublications\MeliLinkedPublicationService;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Mockery;
use Tests\TestCase;

class MeliLinkedPublicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_user_product_keeps_two_independent_price_pairs(): void
    {
        $account = MeliAccount::factory()->create();
        [$a, $b, $c, $d] = collect([
            ['MLM1400075673', 399, ['MLM1910233204']],
            ['MLM1910233204', 399, ['MLM1400075673']],
            ['MLM1904073073', 361, ['MLM2237586680']],
            ['MLM2237586680', 361, ['MLM1904073073']],
        ])->map(fn (array $data): MeliPriceManagerItem => MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $account->id,
            'meli_item_id' => $data[0],
            'user_product_id' => 'MLMU881417340',
            'inventory_id' => 'ZIIL82640',
            'current_price' => $data[1],
            'price_sync_status' => 'SYNC',
            'price_relation_ids' => $data[2],
        ]))->all();

        $service = $this->service();

        $this->assertSame([$a->meli_item_id, $b->meli_item_id], collect($service->priceRelations($a)['items'])->pluck('meli_item_id')->all());
        $this->assertSame([$c->meli_item_id, $d->meli_item_id], collect($service->priceRelations($c)['items'])->pluck('meli_item_id')->all());
        $this->assertCount(4, $service->stockRelations($a)['items']);
    }

    public function test_inventory_and_relation_ids_never_cross_accounts(): void
    {
        $first = MeliAccount::factory()->create();
        $second = MeliAccount::factory()->create();
        $item = MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $first->id,
            'meli_item_id' => 'MLM100',
            'inventory_id' => 'INV-SHARED',
            'price_sync_status' => 'SYNC',
            'price_relation_ids' => ['MLM200'],
        ]);
        MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $second->id,
            'meli_item_id' => 'MLM200',
            'inventory_id' => 'INV-SHARED',
        ]);

        $service = $this->service();

        $this->assertFalse($service->priceRelations($item)['linked']);
        $this->assertFalse($service->stockRelations($item)['shared']);
    }

    public function test_stock_divergence_is_independent_from_price_relations(): void
    {
        $account = MeliAccount::factory()->create();
        $item = MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $account->id,
            'meli_item_id' => 'MLM100',
            'inventory_id' => 'INV-1',
            'available_quantity' => 10,
        ]);
        MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $account->id,
            'meli_item_id' => 'MLM200',
            'inventory_id' => 'INV-1',
            'available_quantity' => 8,
        ]);

        $relations = $this->service()->stockRelations($item);

        $this->assertTrue($relations['shared']);
        $this->assertTrue($relations['stock_divergence']);
    }

    public function test_remote_price_relation_requires_sync_and_declared_same_account_member(): void
    {
        $account = MeliAccount::factory()->create();
        $item = MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $account->id,
            'meli_item_id' => 'MLM100',
            'raw_item' => ['item_relations' => [['id' => 'MLM200']]],
        ]);
        MeliPriceManagerItem::factory()->create([
            'meli_account_id' => $account->id,
            'meli_item_id' => 'MLM200',
        ]);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->andReturn([
            'status' => 'SYNC',
            'relations' => [['id' => 'MLM200']],
        ]);
        $api = Mockery::mock(MeliAccountApiClient::class);
        $api->shouldReceive('request')->once()->with(
            $account,
            'get',
            '/public/buybox/sync/MLM100',
            [],
            true,
            ['x-public' => 'True'],
        )->andReturn($response);

        $relation = (new MeliLinkedPublicationService($api))->refreshPriceRelations($account, $item);

        $this->assertTrue($relation['linked']);
        $this->assertSame('SYNC', $relation['status']);
        $this->assertSame(['MLM100', 'MLM200'], collect($relation['items'])->pluck('meli_item_id')->all());
    }

    private function service(): MeliLinkedPublicationService
    {
        return new MeliLinkedPublicationService(Mockery::mock(MeliAccountApiClient::class));
    }
}
