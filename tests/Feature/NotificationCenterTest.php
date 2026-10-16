<?php

namespace Tests\Feature;

use App\Models\Llanta;
use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\MeliClaim;
use App\Models\MeliPublication;
use App\Models\MeliQuestion;
use App\Models\SystemNotificationRead;
use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    private User $user;

    private MeliAccount $account;

    private NotificationCenterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->string('meli_id')->nullable();
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
            $table->string('sku')->index();
            $table->string('mlm')->index();
            $table->string('status')->nullable()->index();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('question_id')->index();
            $table->string('item_id')->nullable();
            $table->string('item_title')->nullable();
            $table->string('item_thumbnail')->nullable();
            $table->decimal('item_price', 12, 2)->nullable();
            $table->string('sku')->nullable();
            $table->string('status')->default('UNANSWERED');
            $table->text('text')->nullable();
            $table->timestamp('question_created_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('claim_id', 64);
            $table->string('order_id', 64)->nullable();
            $table->string('type', 80)->nullable();
            $table->string('stage', 80)->nullable();
            $table->string('status', 80)->nullable();
            $table->boolean('affects_reputation')->nullable();
            $table->timestamp('reputation_due_date')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->string('detail_title')->nullable();
            $table->text('problem')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_chat_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('buyer_id')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('requires_human')->default(false);
            $table->string('last_message_role')->nullable();
            $table->text('last_message_text')->nullable();
            $table->string('last_inbound_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('marca')->nullable();
            $table->text('descripcion')->nullable();
            $table->integer('stock')->default(0);
            $table->decimal('costo', 12, 2)->default(0);
            $table->timestamps();
        });

        (require database_path('migrations/2026_10_16_200000_create_system_notification_reads_table.php'))->up();

        $this->user = User::forceCreate([
            'name' => 'Admin SBS',
            'email' => 'admin@sbs.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->account = MeliAccount::forceCreate([
            'user_id' => $this->user->id,
            'meli_user_id' => '123456789',
            'nickname' => 'SBS_TIENDA_OFICIAL',
            'is_default' => true,
        ]);

        $this->service = app(NotificationCenterService::class);
    }

    public function test_can_collect_all_types_of_notifications_and_compute_summary(): void
    {
        // 1. Blocked publication
        MeliPublication::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'TINTE-ROJO-99',
            'mlm' => 'MLM999999',
            'status' => 'blocked',
            'raw' => ['moderations' => ['reason' => 'Infracción de marca']],
        ]);

        // 2. Unanswered Question
        MeliQuestion::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->account->id,
            'question_id' => 'Q1001',
            'item_id' => 'MLM123456',
            'item_title' => 'Shampoo Matizador 1L',
            'sku' => 'SHAMP-MAT-1L',
            'status' => 'UNANSWERED',
            'text' => '¿Sirve para cabello decolorado nivel 9?',
            'question_created_at' => now()->subMinutes(10),
        ]);

        // 3. Claim affecting reputation
        MeliClaim::create([
            'meli_account_id' => $this->account->id,
            'claim_id' => 'CLM-888',
            'order_id' => 'ORD-5555',
            'type' => 'mediations',
            'stage' => 'dispute',
            'status' => 'opened',
            'affects_reputation' => true,
            'reputation_due_date' => now()->addHours(6),
            'detail_title' => 'El producto llegó derramado',
        ]);

        // 4. Chat flow requiring human attention
        MeliChatFlow::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->account->id,
            'order_id' => 'ORD-7777',
            'buyer_id' => 'BUYER-123',
            'sku' => 'CERA-MATE-50',
            'requires_human' => true,
            'last_message_role' => 'buyer',
            'last_message_text' => 'Necesito hablar con un asesor porque me enviaron otro tono',
            'last_message_at' => now()->subMinutes(25),
        ]);

        // 5. Critical Stock (0 pieces)
        Llanta::create([
            'sku' => 'PEROX-30VOL-1L',
            'marca' => 'KUUL',
            'descripcion' => 'Peróxido en crema 30 volúmenes 1L',
            'stock' => 0,
            'costo' => 35.00,
        ]);

        $summary = $this->service->getSummary($this->user, true);

        $this->assertEquals(5, $summary['total_unread']);
        $this->assertTrue($summary['has_critical']);
        $this->assertEquals(1, $summary['counts']['publication']);
        $this->assertEquals(1, $summary['counts']['question']);
        $this->assertEquals(1, $summary['counts']['claim']);
        $this->assertEquals(1, $summary['counts']['message']);
        $this->assertEquals(1, $summary['counts']['stock']);

        $notifications = $this->service->getNotifications($this->user);
        $this->assertCount(5, $notifications);

        // Danger items should be sorted first (claim affecting reputation, blocked publication, requires human message, stock 0)
        $firstItem = $notifications->first();
        $this->assertEquals(NotificationCenterService::SEVERITY_DANGER, $firstItem['severity']);
    }

    public function test_can_mark_notification_as_read_and_dismiss(): void
    {
        $question = MeliQuestion::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->account->id,
            'question_id' => 'Q2002',
            'item_id' => 'MLM222222',
            'item_title' => 'Tinte Kuul Rubio Claro',
            'sku' => 'TINTE-8.1',
            'status' => 'UNANSWERED',
            'text' => '¿Viene con el peróxido incluido?',
            'question_created_at' => now(),
        ]);

        $key = "quest_{$question->id}";

        // Mark as read
        $this->actingAs($this->user)->postJson(route('notifications.read', ['key' => $key]))
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $readRecord = SystemNotificationRead::where('user_id', $this->user->id)
            ->where('notification_key', $key)
            ->first();

        $this->assertNotNull($readRecord);
        $this->assertNotNull($readRecord->read_at);
        $this->assertNull($readRecord->dismissed_at);

        // Check summary unread count is 0
        $summary = $this->service->getSummary($this->user, true);
        $this->assertEquals(0, $summary['total_unread']);

        // Check filter unread
        $unreadItems = $this->service->getNotifications($this->user, ['status' => 'unread']);
        $this->assertCount(0, $unreadItems);

        // Dismiss notification
        $this->actingAs($this->user)->postJson(route('notifications.dismiss', ['key' => $key]))
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        // Check dismissed item is completely removed from list
        $allItems = $this->service->getNotifications($this->user);
        $this->assertCount(0, $allItems);
    }

    public function test_can_mark_all_notifications_as_read(): void
    {
        MeliQuestion::create([
            'user_id' => $this->user->id,
            'question_id' => 'Q3001',
            'status' => 'UNANSWERED',
            'text' => 'Pregunta 1',
        ]);

        MeliQuestion::create([
            'user_id' => $this->user->id,
            'question_id' => 'Q3002',
            'status' => 'UNANSWERED',
            'text' => 'Pregunta 2',
        ]);

        $summaryBefore = $this->service->getSummary($this->user, true);
        $this->assertEquals(2, $summaryBefore['total_unread']);

        $response = $this->actingAs($this->user)->postJson(route('notifications.read-all'));
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $summaryAfter = $this->service->getSummary($this->user, true);
        $this->assertEquals(0, $summaryAfter['total_unread']);
    }

    public function test_can_access_notifications_index_and_feed_endpoints(): void
    {
        $response = $this->actingAs($this->user)->get(route('notifications.index'));
        $response->assertStatus(200);

        $feedResponse = $this->actingAs($this->user)->getJson(route('notifications.feed'));
        $feedResponse->assertStatus(200);
        $feedResponse->assertJsonPath('ok', true);
        $feedResponse->assertJsonStructure([
            'ok',
            'summary' => [
                'total_unread',
                'total_critical',
                'has_critical',
                'counts',
                'latest',
            ],
        ]);
    }
}
