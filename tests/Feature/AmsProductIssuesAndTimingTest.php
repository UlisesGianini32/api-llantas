<?php

namespace Tests\Feature;

use App\Models\AmsProductIssue;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\MeliPublication;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmsProductIssuesAndTimingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    public function test_can_view_incidencias_page(): void
    {
        $response = $this->actingAs($this->user)->get('/ams/incidencias');
        $response->assertStatus(200);
    }

    public function test_can_report_product_issue(): void
    {
        $payload = [
            'item_id' => 'MLM1234567890',
            'sku' => 'TEST-SKU-1',
            'title' => 'Producto de prueba',
            'current_image_url' => 'https://http2.mlstatic.com/image.jpg',
            'issue_type' => 'imagen_incorrecta',
            'notes' => 'La foto muestra un producto diferente al empaque',
            'order_id' => '2000019999999',
        ];

        $response = $this->actingAs($this->user)->postJson('/ams/incidencias', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Incidencia reportada correctamente.',
        ]);

        $this->assertDatabaseHas('ams_product_issues', [
            'item_id' => 'MLM1234567890',
            'issue_type' => 'imagen_incorrecta',
            'status' => 'pending',
            'reported_by_user_id' => $this->user->id,
        ]);
    }

    public function test_can_correct_product_issue_and_updates_catalog_and_orders(): void
    {
        // 1. Crear producto previo
        $product = Product::create([
            'ml' => 'MLM9876543210',
            'sku' => 'OLD-SKU',
            'name' => 'Nombre Viejo',
            'thumbnail' => 'https://old-image.jpg',
            'status' => 'active',
        ]);

        // 2. Crear item en pedido previo
        $meliAccount = \App\Models\MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => '123456789',
            'nickname' => 'TEST_ACCOUNT',
            'is_default' => true,
        ]);

        $order = MeliOrder::create([
            'order_id' => '2000018888888',
            'meli_account_id' => $meliAccount->id,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItem = MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM9876543210',
            'sku' => 'OLD-SKU',
            'title' => 'Nombre Viejo',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // 3. Crear publicación ML previa
        $pub = MeliPublication::create([
            'mlm' => 'MLM9876543210',
            'user_id' => $this->user->id,
            'title' => 'Nombre Viejo',
            'sku' => 'OLD-SKU',
            'raw' => [
                'thumbnail' => 'https://old-image.jpg',
                'pictures' => [['url' => 'https://old-image.jpg', 'secure_url' => 'https://old-image.jpg']],
            ],
            'status' => 'active',
        ]);

        // 4. Crear incidencia
        $issue = AmsProductIssue::create([
            'item_id' => 'MLM9876543210',
            'sku' => 'OLD-SKU',
            'title' => 'Nombre Viejo',
            'current_image_url' => 'https://old-image.jpg',
            'issue_type' => 'sku_incorrecto',
            'status' => 'pending',
            'reported_by_user_id' => $this->user->id,
        ]);

        // 5. Aplicar corrección
        $updatePayload = [
            'new_sku' => 'NEW-CORRECT-SKU',
            'new_title' => 'Nombre Corregido y Completo',
            'new_image_url' => 'https://new-correct-image.jpg',
            'resolution_notes' => 'Se corrigió el SKU y la imagen oficial',
            'mark_resolved' => '1',
        ];

        $response = $this->actingAs($this->user)->putJson("/ams/incidencias/{$issue->id}", $updatePayload);
        $response->assertStatus(200);

        // Verificar que la incidencia se marcó como resuelta
        $issue->refresh();
        $this->assertEquals('resolved', $issue->status);
        $this->assertEquals('NEW-CORRECT-SKU', $issue->sku);
        $this->assertEquals('Nombre Corregido y Completo', $issue->title);
        $this->assertEquals('https://new-correct-image.jpg', $issue->current_image_url);
        $this->assertNotNull($issue->resolved_at);
        $this->assertEquals($this->user->id, $issue->resolved_by_user_id);

        // Verificar que la tabla products se actualizó
        $product->refresh();
        $this->assertEquals('NEW-CORRECT-SKU', $product->sku);
        $this->assertEquals('Nombre Corregido y Completo', $product->name);
        $this->assertEquals('https://new-correct-image.jpg', $product->thumbnail);

        // Verificar que meli_order_items se actualizó (para que el empaque de AMS lo vea bien)
        $orderItem->refresh();
        $this->assertEquals('NEW-CORRECT-SKU', $orderItem->sku);
        $this->assertEquals('Nombre Corregido y Completo', $orderItem->title);

        // Verificar que meli_publications se actualizó
        $pub->refresh();
        $this->assertEquals('NEW-CORRECT-SKU', $pub->sku);
        $this->assertEquals('Nombre Corregido y Completo', data_get($pub->raw, 'title') ?? $pub->title);
    }

    public function test_can_resolve_and_reopen_issue(): void
    {
        $issue = AmsProductIssue::create([
            'item_id' => 'MLM5555555555',
            'issue_type' => 'sin_imagen',
            'status' => 'pending',
            'reported_by_user_id' => $this->user->id,
        ]);

        // Resolver
        $resResolve = $this->actingAs($this->user)->postJson("/ams/incidencias/{$issue->id}/resolve", [
            'notes' => 'Resuelto manualmente',
        ]);
        $resResolve->assertStatus(200);
        $issue->refresh();
        $this->assertEquals('resolved', $issue->status);
        $this->assertNotNull($issue->resolved_at);

        // Reabrir
        $resReopen = $this->actingAs($this->user)->postJson("/ams/incidencias/{$issue->id}/reopen");
        $resReopen->assertStatus(200);
        $issue->refresh();
        $this->assertEquals('pending', $issue->status);
        $this->assertNull($issue->resolved_at);
    }

    public function test_hermosillo_timezone_formatting(): void
    {
        // Una orden creada a las 20:50:00 UTC (13:50:00 en Hermosillo UTC-7)
        $utcCreated = Carbon::create(2026, 10, 2, 20, 50, 0, 'UTC');

        $formattedHermosillo = Carbon::parse($utcCreated->toDateTimeString(), 'UTC')
            ->timezone('America/Hermosillo')
            ->format('d/m/Y H:i');

        $this->assertEquals('02/10/2026 13:50', $formattedHermosillo);
    }
}
