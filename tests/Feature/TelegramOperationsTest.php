<?php

namespace Tests\Feature;

use App\Jobs\ImportExcelFromTelegramJob;
use App\Jobs\SyncMeliOpenClaimsForTelegramJob;
use App\Jobs\SyncMeliPostSaleForTelegramJob;
use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\MeliClaim;
use App\Models\MeliClaimActionLog;
use App\Models\TelegramConversationState;
use App\Models\TelegramOperatorIdentity;
use App\Models\TelegramProcessedUpdate;
use App\Models\User;
use App\Services\MeliApi;
use App\Services\MeliMessageService;
use App\Services\MercadoLibre\Claims\MeliClaimMessagePolicy;
use App\Services\MercadoLibre\Claims\MeliClaimMessageSender;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramClaimService;
use App\Services\Telegram\TelegramPostSaleService;
use App\Services\Telegram\TelegramPostSaleSyncCoordinator;
use App\Services\Telegram\TelegramText;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramOperationsTest extends TestCase
{
    private object $claimsMigration;

    private object $detailsMigration;

    private object $actionsMigration;

    private object $actionSourceMigration;

    private object $actionSecurityMigration;

    private object $attachmentsMigration;

    private object $claimTelegramMigration;

    private object $telegramMigration;

    private User $user;

    private MeliAccount $account;

    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        $this->setEnv('TELEGRAM_WEBHOOK_SECRET', 'webhook-secret');
        $this->setEnv('TELEGRAM_BOT_TOKEN', 'bot-token');
        $this->setEnv('TELEGRAM_ALLOWED_CHAT_IDS', '100,200,300');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32)->default('operations');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('meli_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('order_id')->unique();
            $table->string('status')->nullable();
            $table->string('display_id')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id');
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('variation_text')->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('meli_chat_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('conversation_id')->nullable();
            $table->string('message_id')->nullable();
            $table->string('last_inbound_message_id')->nullable();
            $table->string('buyer_id')->nullable();
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('menu_sent')->default(false);
            $table->timestamp('menu_sent_at')->nullable();
            $table->string('last_option_selected')->nullable();
            $table->timestamp('last_option_selected_at')->nullable();
            $table->boolean('requires_human')->default(false);
            $table->timestamp('requires_human_at')->nullable();
            $table->string('product_pdf_url')->nullable();
            $table->string('catalog_pdf_url')->nullable();
            $table->string('invoice_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $this->claimsMigration = require database_path('migrations/2026_08_29_000001_create_meli_claim_tables.php');
        $this->claimsMigration->up();
        $this->detailsMigration = require database_path('migrations/2026_08_31_000001_add_detail_snapshots_to_meli_claims.php');
        $this->detailsMigration->up();
        $this->actionsMigration = require database_path('migrations/2026_09_01_000001_create_meli_claim_action_logs_table.php');
        $this->actionsMigration->up();
        $this->actionSourceMigration = require database_path('migrations/2026_09_18_000001_add_source_to_meli_claim_action_logs_table.php');
        $this->actionSourceMigration->up();
        $this->actionSecurityMigration = require database_path('migrations/2026_09_18_000002_secure_telegram_claim_actions.php');
        $this->actionSecurityMigration->up();
        $this->attachmentsMigration = require database_path('migrations/2026_09_02_000001_create_meli_claim_attachment_uploads_table.php');
        $this->attachmentsMigration->up();
        $this->claimTelegramMigration = require database_path('migrations/2026_09_15_000001_add_telegram_notified_at_to_meli_claims.php');
        $this->claimTelegramMigration->up();
        $this->telegramMigration = require database_path('migrations/2026_09_17_000001_create_telegram_operations_tables.php');
        $this->telegramMigration->up();

        $this->user = User::factory()->create();
        $this->account = MeliAccount::factory()->create([
            'user_id' => $this->user->id, 'meli_user_id' => '900', 'nickname' => 'Principal',
            'access_token' => 'meli-token', 'expires_at' => now()->addHour(), 'is_default' => true,
        ]);
        TelegramOperatorIdentity::query()->create(['chat_id' => '100', 'user_id' => $this->user->id]);
        TelegramOperatorIdentity::query()->create(['chat_id' => '200', 'user_id' => $this->user->id]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    protected function tearDown(): void
    {
        $this->telegramMigration->down();
        $this->claimTelegramMigration->down();
        $this->attachmentsMigration->down();
        $this->actionSecurityMigration->down();
        $this->actionSourceMigration->down();
        $this->actionsMigration->down();
        $this->detailsMigration->down();
        $this->claimsMigration->down();
        foreach (['meli_chat_flows', 'meli_order_items', 'meli_orders', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    #[DataProvider('menuCommandProvider')]
    public function test_authorized_menu_commands_show_operations_menu(string $command): void
    {
        $this->sendMessage(1, '100', $command)->assertOk();
        $request = $this->telegramRequests('sendMessage')->sole();
        $this->assertStringContainsString('Panel de operaciones', $request->data()['text']);
        $this->assertSame('x', data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data'));
        $this->assertSame('p', data_get($request->data(), 'reply_markup.inline_keyboard.1.0.callback_data'));
        $this->assertSame('c', data_get($request->data(), 'reply_markup.inline_keyboard.2.0.callback_data'));
    }

    public static function menuCommandProvider(): array
    {
        return [['/start'], ['/menu'], ['hola'], ['Hola']];
    }

    public function test_unauthorized_chat_and_repeated_update_do_not_execute_actions(): void
    {
        $this->sendMessage(10, '999', '/start')->assertOk();
        $this->assertCount(0, $this->telegramRequests());
        $this->sendMessage(11, '100', '/start')->assertOk();
        $this->sendMessage(11, '100', '/start')->assertOk();
        $this->assertCount(1, $this->telegramRequests('sendMessage'));
    }

    public function test_processed_updates_have_cleanup_index_and_duplicate_update_is_ignored(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('telegram_processed_updates')"));
        $this->assertTrue($indexes->contains(fn (object $index): bool => $index->name === 'telegram_processed_updates_processed_at_index'));

        $this->sendMessage(12, '100', '/start')->assertOk();
        $this->sendMessage(12, '100', '/start')->assertOk();

        $this->assertSame(1, TelegramProcessedUpdate::query()->where('update_key', 'u:12')->count());
        $this->assertCount(1, $this->telegramRequests('sendMessage'));
    }

    public function test_non_unique_database_error_is_not_silently_treated_as_duplicate(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_telegram_processed_update
            BEFORE INSERT ON telegram_processed_updates
            WHEN NEW.update_key = 'u:13'
            BEGIN
                SELECT RAISE(FAIL, 'forced database failure');
            END
        SQL);

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        $this->sendMessage(13, '100', '/start');
    }

    public function test_excel_button_and_direct_excel_keep_existing_job(): void
    {
        Queue::fake();
        $this->sendCallback(20, '100', 'x')->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', ['chat_id' => '100', 'mode' => 'awaiting_excel']);
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 21,
            'message' => ['message_id' => 2, 'chat' => ['id' => 100], 'document' => ['file_id' => 'FILE', 'file_name' => 'stock.xlsx']],
        ], $this->headers())->assertOk();
        Queue::assertPushed(ImportExcelFromTelegramJob::class, fn (ImportExcelFromTelegramJob $job): bool => $job->fileId === 'FILE');
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 22,
            'message' => ['message_id' => 3, 'chat' => ['id' => 200], 'document' => ['file_id' => 'DIRECT', 'file_name' => 'direct.xls']],
        ], $this->headers())->assertOk();
        Queue::assertPushed(ImportExcelFromTelegramJob::class, fn (ImportExcelFromTelegramJob $job): bool => $job->fileId === 'DIRECT');

        $this->sendCallback(23, '100', 'cs')->assertOk();
        Queue::assertPushed(SyncMeliOpenClaimsForTelegramJob::class, fn (SyncMeliOpenClaimsForTelegramJob $job): bool => $job->chatId === '100');
    }

    public function test_post_sale_refresh_dispatches_one_global_run_and_reports_when_already_running(): void
    {
        Queue::fake();

        $this->sendCallback(24, '100', 'pu')->assertOk();
        $this->sendCallback(25, '200', 'pu')->assertOk();

        Queue::assertPushed(SyncMeliPostSaleForTelegramJob::class, fn (SyncMeliPostSaleForTelegramJob $job): bool => $job->chatId === '100' && $job->afterId === 0 && $job->lockOwner !== '');
        Queue::assertPushed(SyncMeliPostSaleForTelegramJob::class, 1);
        $texts = $this->telegramRequests('editMessageText')->map(fn (Request $request): string => (string) $request->data()['text']);
        $this->assertTrue($texts->contains(fn (string $text): bool => str_contains($text, 'quedó en cola')));
        $this->assertTrue($texts->contains(fn (string $text): bool => str_contains($text, 'ya está en curso')));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.mercadolibre.com'));
    }

    public function test_claim_menu_filters_pagination_detail_and_safe_conversation(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->claim([
                'claim_id' => (string) (1000 + $i),
                'action_responsible' => $i <= 4 ? 'respondent' : 'complainant',
                'due_date' => $i <= 2 ? now()->addHours(2) : now()->addDays(3),
                'affects_reputation' => $i === 3,
                'messages' => $i === 1 ? [[
                    'sender_role' => 'complainant', 'receiver_role' => 'respondent',
                    'message' => '<p>Hola <strong>equipo</strong></p><script>alert(1)</script>',
                ]] : [],
            ]);
        }

        [$menu] = app(TelegramClaimService::class)->menu();
        $this->assertStringContainsString('Abiertos: 8', $menu);
        $this->assertStringContainsString('Necesitan atención: 4', $menu);
        $this->assertStringContainsString('Urgentes: 2', $menu);
        $this->assertStringContainsString('Afectan reputación: 1', $menu);

        [$list, $buttons] = app(TelegramClaimService::class)->listing('o', 1);
        $this->assertStringContainsString('Página 1 de 2', $list);
        $claim = MeliClaim::query()->where('claim_id', '1001')->firstOrFail();
        [$detail] = app(TelegramClaimService::class)->detail($claim->id);
        $this->assertStringContainsString('Urgente: Sí', $detail);
        [, $detailButtons] = app(TelegramClaimService::class)->detail($claim->id);
        $this->assertTrue(collect($detailButtons)->flatten(1)->contains(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'cd:')));
        [$conversation] = app(TelegramClaimService::class)->conversation($claim->id);
        $this->assertStringContainsString('Hola equipo', $conversation);
        $this->assertStringNotContainsString('<strong>', $conversation);
        $this->assertStringNotContainsString('alert(1)', $conversation);
        $this->assertNotEmpty($buttons);
    }

    public function test_claim_reply_can_edit_cancel_and_confirm_only_once(): void
    {
        $claim = $this->claim(['available_actions' => [['action' => 'send_message_to_complainant']]]);
        $sender = Mockery::mock(MeliClaimMessageSender::class);
        $sender->shouldReceive('send')->once()->withArgs(fn ($actor, $target, $text, $files, $source): bool => $actor->is($this->user) && $target->is($claim) && $text === 'Texto final' && $files->isEmpty() && $source === 'telegram')
            ->andReturn(['ok' => true, 'refresh_failed' => false, 'error' => null]);
        $this->app->instance(MeliClaimMessageSender::class, $sender);

        $this->sendCallback(30, '100', 'cr:'.$claim->id)->assertOk();
        $this->sendMessage(31, '100', 'Texto inicial')->assertOk();
        $this->sendCallback(32, '100', 'ced:'.$claim->id)->assertOk();
        $this->sendMessage(33, '100', 'Texto final')->assertOk();
        $this->sendCallback(34, '100', 'cok:'.$claim->id)->assertOk();
        $this->sendCallback(35, '100', 'cok:'.$claim->id)->assertOk();
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);

        $this->sendCallback(36, '100', 'cr:'.$claim->id)->assertOk();
        $this->sendCallback(37, '100', 'cancel')->assertOk();
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
    }

    public function test_closed_claim_and_missing_entity_cannot_start_reply(): void
    {
        $closed = $this->claim(['status' => 'closed', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->sendCallback(40, '100', 'cr:'.$closed->id)->assertOk();
        $this->sendCallback(41, '100', 'cd:999999:o:1')->assertOk();
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
    }

    public function test_confirmed_claim_reply_reuses_recipient_and_audit_service(): void
    {
        $claim = $this->claim(['available_actions' => [['action' => 'send_message_to_mediator']]]);
        $remote = Mockery::mock(Response::class);
        $remote->shouldReceive('json')->andReturn(['id' => 'REMOTE-1']);
        $remote->shouldReceive('status')->andReturn(201);
        $claims = Mockery::mock(MeliClaimsService::class);
        $claims->shouldReceive('ensureFreshToken')->once()->withArgs(fn ($account): bool => $account->is($this->account));
        $claims->shouldReceive('sendMessage')->once()->withArgs(fn ($account, $target, $receiver, $text, $attachments): bool => $account->is($this->account)
            && $target->is($claim) && $receiver === 'mediator' && $text === 'Mensaje auditado' && $attachments === [])->andReturn($remote);
        $claims->shouldReceive('syncClaim')->once()->withArgs(fn ($account, $claimId, $force): bool => $account->is($this->account) && $claimId === $claim->claim_id && $force === true);
        $sender = new MeliClaimMessageSender(app(MeliClaimMessagePolicy::class), $claims);
        $this->app->instance(MeliClaimMessageSender::class, $sender);

        $this->sendCallback(42, '100', 'cr:'.$claim->id)->assertOk();
        $this->sendMessage(43, '100', 'Mensaje auditado')->assertOk();
        $this->sendCallback(44, '100', 'cok:'.$claim->id)->assertOk();

        $this->assertDatabaseHas('meli_claim_action_logs', [
            'meli_claim_id' => $claim->id,
            'user_id' => $this->user->id,
            'receiver_role' => 'mediator',
            'success' => true,
        ]);
        $payload = DB::table('meli_claim_action_logs')->value('request_payload_sanitized');
        $this->assertStringContainsString('telegram', (string) $payload);
    }

    public function test_claim_closed_after_writing_reply_is_revalidated_before_confirming(): void
    {
        $claim = $this->claim(['available_actions' => [['action' => 'send_message_to_complainant']]]);
        $claims = Mockery::mock(MeliClaimsService::class);
        $claims->shouldNotReceive('sendMessage');
        $this->app->instance(
            MeliClaimMessageSender::class,
            new MeliClaimMessageSender(app(MeliClaimMessagePolicy::class), $claims)
        );

        $this->sendCallback(45, '100', 'cr:'.$claim->id)->assertOk();
        $this->sendMessage(46, '100', 'Respuesta pendiente')->assertOk();
        $claim->forceFill(['status' => 'closed'])->save();
        $this->sendCallback(47, '100', 'cok:'.$claim->id)->assertOk();

        $this->assertDatabaseMissing('meli_claim_action_logs', ['meli_claim_id' => $claim->id]);
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
        $this->assertTrue($this->telegramRequests()->contains(
            fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text', ''), 'no permite enviar')
        ));
    }

    public function test_claim_detail_and_actions_menu_show_only_real_supported_actions(): void
    {
        $claim = $this->claim(['available_actions' => [
            ['action' => 'refund'],
            ['action' => 'send_message_to_complainant'],
            ['action' => 'unknown_remote_action'],
        ]]);

        $this->sendCallback(70, '100', "cd:{$claim->id}:o:1")->assertOk();
        $detail = $this->telegramRequests('editMessageText')->last();
        $this->assertTrue(collect(data_get($detail->data(), 'reply_markup.inline_keyboard'))->flatten(1)->contains(
            fn (array $button): bool => ($button['callback_data'] ?? null) === "ca:{$claim->id}"
        ));

        $this->sendCallback(71, '100', "ca:{$claim->id}")->assertOk();
        $menu = $this->telegramRequests('editMessageText')->last();
        $labels = collect(data_get($menu->data(), 'reply_markup.inline_keyboard'))->flatten(1)->pluck('text');
        $this->assertTrue($labels->contains('💰 Reembolso total'));
        $this->assertTrue($labels->contains('💬 Responder comprador'));
        $this->assertFalse($labels->contains(fn (string $label): bool => str_contains($label, 'Unknown')));

        $this->sendCallback(72, '100', "caa:{$claim->id}:z")->assertOk();
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.mercadolibre.com'));
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
    }

    public function test_refund_and_return_require_remote_preflight_and_confirmation(): void
    {
        $refund = $this->claim(['claim_id' => 'TG-REFUND', 'available_actions' => [['action' => 'refund']]]);
        $actions = ['refund'];
        $offers = [];
        $status = 'opened';
        $posts = 0;
        $this->fakeClaimActionApi('TG-REFUND', $actions, $offers, $status, $posts);

        $this->sendCallback(73, '100', "caa:{$refund->id}:r")->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', [
            'chat_id' => '100', 'mode' => 'confirm_claim_action', 'entity_id' => $refund->id,
        ]);
        $this->assertSame(0, $posts);
        $this->assertTrue($this->telegramRequests('editMessageText')->contains(
            fn (Request $request): bool => str_contains((string) $request->data()['text'], 'REEMBOLSO TOTAL')
        ));

        $this->sendCallback(74, '100', 'cancel')->assertOk();
        $return = $this->claim(['claim_id' => 'TG-RETURN', 'available_actions' => [['action' => 'allow_return']]]);
        $actions = ['allow_return'];
        $this->fakeClaimActionApi('TG-RETURN', $actions, $offers, $status, $posts);
        $this->sendCallback(75, '100', "caa:{$return->id}:d")->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', [
            'chat_id' => '100', 'mode' => 'confirm_claim_action', 'entity_id' => $return->id,
        ]);
        $this->assertSame(0, $posts);
        $this->assertTrue($this->telegramRequests('editMessageText')->contains(
            fn (Request $request): bool => str_contains((string) $request->data()['text'], 'HABILITAR DEVOLUCIÓN')
        ));
    }

    public function test_partial_refund_accepts_only_a_remote_offer_and_audits_telegram_source(): void
    {
        $claim = $this->claim(['claim_id' => 'TG-PARTIAL', 'available_actions' => [['action' => 'allow_partial_refund']]]);
        $actions = ['allow_partial_refund'];
        $offers = [['percentage' => 40, 'amount' => 259.60, 'currency_id' => 'MXN']];
        $status = 'opened';
        $posts = 0;
        $clearActionsAfterPost = true;
        $this->fakeClaimActionApi('TG-PARTIAL', $actions, $offers, $status, $posts, 201, $clearActionsAfterPost);

        $this->sendCallback(76, '100', "caa:{$claim->id}:p")->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', ['chat_id' => '100', 'mode' => 'awaiting_claim_partial_refund_amount']);
        $this->sendMessage(77, '100', 'importe inválido')->assertOk();
        $this->sendMessage(78, '100', '250.00')->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', ['chat_id' => '100', 'mode' => 'awaiting_claim_partial_refund_amount']);
        $this->sendMessage(79, '100', '259.60')->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', ['chat_id' => '100', 'mode' => 'confirm_claim_action']);
        $this->sendCallback(80, '100', "cac:{$claim->id}")->assertOk();

        $this->assertSame(1, $posts);
        $this->assertDatabaseHas('meli_claim_action_logs', [
            'meli_claim_id' => $claim->id,
            'user_id' => $this->user->id,
            'source' => 'telegram',
            'telegram_chat_id' => '100',
            'action' => 'partial_refund',
            'remote_status' => 201,
            'success' => true,
        ]);
        $audit = MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->sole();
        $this->assertSame(['percentage' => 40, 'amount' => 259.6, 'currency_id' => 'MXN'], $audit->request_payload_sanitized);
        $this->assertStringNotContainsString('meli-token', $audit->toJson());
        $this->assertSame([], $claim->fresh()->available_actions);
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
    }

    public function test_double_and_duplicate_confirmation_execute_one_refund(): void
    {
        $claim = $this->claim(['claim_id' => 'TG-ONCE', 'available_actions' => [['action' => 'refund']]]);
        $actions = ['refund'];
        $offers = [];
        $status = 'opened';
        $posts = 0;
        $this->fakeClaimActionApi('TG-ONCE', $actions, $offers, $status, $posts);

        $this->sendCallback(81, '100', "caa:{$claim->id}:r")->assertOk();
        $this->sendCallback(82, '100', "cac:{$claim->id}")->assertOk();
        $this->sendCallback(82, '100', "cac:{$claim->id}")->assertOk();
        $this->sendCallback(83, '100', "cac:{$claim->id}")->assertOk();

        $this->assertSame(1, $posts);
        $this->assertSame(1, MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->count());
    }

    public function test_action_disappearing_or_claim_closing_before_confirmation_never_posts(): void
    {
        $claim = $this->claim(['claim_id' => 'TG-DISAPPEARS', 'available_actions' => [['action' => 'refund']]]);
        $actions = ['refund'];
        $offers = [];
        $status = 'opened';
        $posts = 0;
        $this->fakeClaimActionApi('TG-DISAPPEARS', $actions, $offers, $status, $posts);
        $this->sendCallback(84, '100', "caa:{$claim->id}:r")->assertOk();
        $actions = [];
        $this->sendCallback(85, '100', "cac:{$claim->id}")->assertOk();
        $this->assertSame(0, $posts);

        $closed = $this->claim(['claim_id' => 'TG-CLOSED', 'available_actions' => [['action' => 'allow_return']]]);
        $actions = ['allow_return'];
        $status = 'opened';
        $this->fakeClaimActionApi('TG-CLOSED', $actions, $offers, $status, $posts);
        $this->sendCallback(86, '200', "caa:{$closed->id}:d")->assertOk();
        $status = 'closed';
        $this->sendCallback(87, '200', "cac:{$closed->id}")->assertOk();
        $this->assertSame(0, $posts);
        $this->assertDatabaseMissing('meli_claim_action_logs', ['meli_claim_id' => $closed->id]);
    }

    public function test_partial_offer_is_revalidated_before_confirming(): void
    {
        $claim = $this->claim(['claim_id' => 'TG-OFFER-CHANGED', 'available_actions' => [['action' => 'allow_partial_refund']]]);
        $actions = ['allow_partial_refund'];
        $offers = [['percentage' => 40, 'amount' => 100, 'currency_id' => 'MXN']];
        $status = 'opened';
        $posts = 0;
        $this->fakeClaimActionApi('TG-OFFER-CHANGED', $actions, $offers, $status, $posts);

        $this->sendCallback(88, '100', "caa:{$claim->id}:p")->assertOk();
        $this->sendMessage(89, '100', '100')->assertOk();
        $offers = [['percentage' => 40, 'amount' => 90, 'currency_id' => 'MXN']];
        $this->sendCallback(90, '100', "cac:{$claim->id}")->assertOk();

        $this->assertSame(0, $posts);
        $this->assertTrue($this->telegramRequests()->contains(
            fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text', ''), 'importe indicado ya no es válido')
        ));
    }

    public function test_remote_action_error_is_controlled_and_not_retried(): void
    {
        $claim = $this->claim(['claim_id' => 'TG-REJECTED', 'available_actions' => [['action' => 'refund']]]);
        $actions = ['refund'];
        $offers = [];
        $status = 'opened';
        $posts = 0;
        $this->fakeClaimActionApi('TG-REJECTED', $actions, $offers, $status, $posts, 500);

        $this->sendCallback(91, '100', "caa:{$claim->id}:r")->assertOk();
        $this->sendCallback(92, '100', "cac:{$claim->id}")->assertOk();

        $this->assertSame(1, $posts);
        $this->assertDatabaseHas('meli_claim_action_logs', [
            'meli_claim_id' => $claim->id, 'source' => 'telegram', 'success' => false, 'remote_status' => 500,
        ]);
        $texts = $this->telegramRequests()->map(fn (Request $request): string => (string) data_get($request->data(), 'text', ''))->implode(' ');
        $this->assertStringContainsString('Mercado Libre no procesó', $texts);
        $this->assertStringNotContainsString('meli-token', $texts);
    }

    public function test_unmapped_allowed_chat_cannot_start_economic_action(): void
    {
        $claim = $this->claim(['claim_id' => 'TG-UNMAPPED', 'available_actions' => [['action' => 'refund']]]);

        $this->sendCallback(930, '300', "caa:{$claim->id}:r")->assertOk();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.mercadolibre.com'));
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '300']);
        $this->assertTrue($this->telegramRequests()->contains(
            fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text', ''), 'no está vinculado')
        ));
    }

    public function test_mapped_operator_without_web_permission_cannot_start_economic_action(): void
    {
        $this->user->forceFill(['role' => 'disabled'])->save();
        $claim = $this->claim(['claim_id' => 'TG-FORBIDDEN', 'available_actions' => [['action' => 'refund']]]);
        $actions = ['refund'];
        $offers = [];
        $status = 'opened';
        $posts = 0;
        $this->fakeClaimActionApi('TG-FORBIDDEN', $actions, $offers, $status, $posts);

        $this->sendCallback(93, '100', "caa:{$claim->id}:r")->assertOk();

        $this->assertSame(0, $posts);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.mercadolibre.com'));
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
    }

    public function test_mapped_operator_must_own_the_claim_account(): void
    {
        $other = User::factory()->create(['role' => 'operations']);
        TelegramOperatorIdentity::query()->where('chat_id', '200')->update(['user_id' => $other->id]);
        $claim = $this->claim(['claim_id' => 'TG-FOREIGN-ACCOUNT', 'available_actions' => [['action' => 'refund']]]);

        $this->sendCallback(931, '200', "caa:{$claim->id}:r")->assertOk();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.mercadolibre.com'));
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '200']);
    }

    public function test_respondent_reply_is_labeled_as_the_seller_for_an_unmapped_read_only_chat(): void
    {
        $claim = $this->claim(['available_actions' => [['action' => 'send_message_to_respondent']]]);

        $this->sendCallback(932, '300', "caa:{$claim->id}:v")->assertOk();

        $request = $this->telegramRequests('editMessageText')->last();
        $this->assertStringContainsString('para el vendedor', (string) $request->data()['text']);
        $this->assertStringNotContainsString('para el comprador', (string) $request->data()['text']);
        $this->assertDatabaseHas('telegram_conversation_states', [
            'chat_id' => '300', 'mode' => 'awaiting_claim_reply', 'entity_id' => $claim->id,
        ]);
    }

    public function test_action_menu_message_flows_keep_buyer_and_mediator_targets(): void
    {
        $claim = $this->claim(['available_actions' => [
            ['action' => 'send_message_to_complainant'],
            ['action' => 'send_message_to_mediator'],
        ]]);
        $sender = Mockery::mock(MeliClaimMessageSender::class);
        $sender->shouldReceive('send')->once()->withArgs(
            fn ($actor, $target, $text, $files, $source, $receiver): bool => $actor->is($this->user)
                && $target->is($claim) && $text === 'Para comprador' && $files->isEmpty()
                && $source === 'telegram' && $receiver === 'complainant'
        )->andReturn(['ok' => true, 'refresh_failed' => false, 'error' => null]);
        $sender->shouldReceive('send')->once()->withArgs(
            fn ($actor, $target, $text, $files, $source, $receiver): bool => $actor->is($this->user)
                && $target->is($claim) && $text === 'Para mediador' && $files->isEmpty()
                && $source === 'telegram' && $receiver === 'mediator'
        )->andReturn(['ok' => true, 'refresh_failed' => false, 'error' => null]);
        $this->app->instance(MeliClaimMessageSender::class, $sender);

        $this->sendCallback(94, '100', "caa:{$claim->id}:c")->assertOk();
        $this->sendMessage(95, '100', 'Para comprador')->assertOk();
        $this->sendCallback(96, '100', "cok:{$claim->id}")->assertOk();
        $this->sendCallback(97, '100', "caa:{$claim->id}:m")->assertOk();
        $this->sendMessage(98, '100', 'Para mediador')->assertOk();
        $this->sendCallback(99, '100', "cok:{$claim->id}")->assertOk();
    }

    public function test_post_sale_menu_listing_and_null_local_role_never_call_meli(): void
    {
        $nullFlow = $this->flow('ORDER-NULL');
        $customerFlow = $this->flow('ORDER-CUSTOMER')->forceFill(['last_message_role' => 'customer']);
        $customerFlow->save();
        $sellerFlow = $this->flow('ORDER-SELLER')->forceFill(['last_message_role' => 'seller']);
        $sellerFlow->save();
        $messaging = Mockery::mock(MeliMessageService::class);
        $messaging->shouldNotReceive('resolveApiUser');
        $api = Mockery::mock(MeliApi::class);
        $api->shouldNotReceive('getPackPostSaleMessages');
        $service = new TelegramPostSaleService($api, $messaging, app(TelegramText::class));

        [$menu] = $service->menu();
        [$listing] = $service->listing('p', 1);

        $this->assertStringContainsString('Pendientes de respuesta: 1', $menu);
        $this->assertStringContainsString('Total: 1', $listing);
        $this->assertFalse($service->snapshot($nullFlow->fresh())['pending']);
        $this->assertTrue($service->snapshot($customerFlow->fresh())['pending']);
        $this->assertFalse($service->snapshot($sellerFlow->fresh())['pending']);
    }

    public function test_post_sale_pending_listing_finds_old_conversation_beyond_150_rows(): void
    {
        $now = now();
        $rows = [];
        for ($i = 0; $i < 151; $i++) {
            $order = $i === 0 ? 'ORDER-OLD-PENDING' : 'ORDER-PENDING-'.$i;
            $rows[] = [
                'user_id' => $this->user->id,
                'meli_account_id' => $this->account->id,
                'order_id' => $order,
                'pack_id' => $order,
                'buyer_id' => 'BUYER-'.$order,
                'last_message_role' => 'customer',
                'last_message_at' => $i === 0 ? $now->copy()->subYear() : $now,
                'last_message_text' => 'Pendiente '.$i,
                'last_message_synced_at' => $now,
                'created_at' => $i === 0 ? $now->copy()->subYear() : $now,
                'updated_at' => $i === 0 ? $now->copy()->subYear() : $now,
            ];
        }
        MeliChatFlow::query()->insert($rows);
        $oldId = MeliChatFlow::query()->where('order_id', 'ORDER-OLD-PENDING')->value('id');
        $messaging = Mockery::mock(MeliMessageService::class);
        $messaging->shouldNotReceive('resolveApiUser');
        $api = Mockery::mock(MeliApi::class);
        $api->shouldNotReceive('getPackPostSaleMessages');
        $service = new TelegramPostSaleService($api, $messaging, app(TelegramText::class));

        [$listing, $keyboard] = $service->listing('p', 26);

        $this->assertStringContainsString('Página 26 de 26', $listing);
        $this->assertStringContainsString('Total: 151', $listing);
        $this->assertTrue(collect($keyboard)->flatten(1)->contains(
            fn (array $button): bool => ($button['callback_data'] ?? null) === "pd:{$oldId}:p:26"
        ));
    }

    public function test_post_sale_sync_does_not_reorder_an_old_conversation_by_updated_at(): void
    {
        $old = $this->flow('ORDER-OLD')->forceFill([
            'last_message_role' => 'customer',
            'last_message_at' => '2026-09-01T10:00:00Z',
            'last_message_text' => 'Mensaje antiguo',
        ]);
        $old->save();
        $new = $this->flow('ORDER-NEW')->forceFill([
            'last_message_role' => 'customer',
            'last_message_at' => '2026-09-10T10:00:00Z',
            'last_message_text' => 'Mensaje reciente',
        ]);
        $new->save();
        $apiUser = clone $this->user;
        $apiUser->forceFill(['meli_id' => '900', 'access_token' => 'token']);
        $messaging = Mockery::mock(MeliMessageService::class);
        $messaging->shouldReceive('resolveApiUser')->once()->with($old)->andReturn($apiUser);
        $api = Mockery::mock(MeliApi::class);
        $api->shouldReceive('getPackPostSaleMessages')->once()->andReturn(['messages' => [[
            'id' => 'OLD',
            'from' => ['user_id' => 'BUYER-ORDER-OLD'],
            'text' => 'Mensaje antiguo',
            'message_date' => ['created' => '2026-09-01T10:00:00Z'],
        ]]]);
        $service = new TelegramPostSaleService($api, $messaging, app(TelegramText::class));

        [, $before] = $service->listing('p', 1);
        $service->syncFlow($old);
        [, $after] = $service->listing('p', 1);

        $this->assertSame([$new->id, $old->id], $this->postSaleListingIds($before));
        $this->assertSame($this->postSaleListingIds($before), $this->postSaleListingIds($after));
        $this->assertTrue($old->fresh()->updated_at->gte($new->fresh()->updated_at));
    }

    public function test_post_sale_detail_navigation_matches_listing_message_order(): void
    {
        $old = $this->flow('ORDER-NAV-OLD')->forceFill(['last_message_role' => 'customer', 'last_message_at' => '2026-09-01T10:00:00Z']);
        $old->save();
        $middle = $this->flow('ORDER-NAV-MIDDLE')->forceFill(['last_message_role' => 'customer', 'last_message_at' => '2026-09-05T10:00:00Z']);
        $middle->save();
        $new = $this->flow('ORDER-NAV-NEW')->forceFill(['last_message_role' => 'customer', 'last_message_at' => '2026-09-10T10:00:00Z']);
        $new->save();
        $withoutDate = $this->flow('ORDER-NAV-NO-DATE')->forceFill(['last_message_role' => 'customer']);
        $withoutDate->save();
        $api = Mockery::mock(MeliApi::class);
        $api->shouldNotReceive('getPackPostSaleMessages');
        $messaging = Mockery::mock(MeliMessageService::class);
        $messaging->shouldNotReceive('resolveApiUser');
        $service = new TelegramPostSaleService($api, $messaging, app(TelegramText::class));

        [, $listing] = $service->listing('p', 1);
        [, $detail] = $service->detail($old->id, 'p', 1);
        $navigation = collect($detail)->flatten(1)->pluck('callback_data')->filter()->values();

        $this->assertSame([$new->id, $middle->id, $old->id, $withoutDate->id], $this->postSaleListingIds($listing));
        $this->assertTrue($navigation->contains("pd:{$middle->id}:p:1"));
        $this->assertTrue($navigation->contains("pd:{$withoutDate->id}:p:1"));
    }

    public function test_post_sale_detail_callback_uses_only_local_snapshot(): void
    {
        $flow = $this->flow('ORDER-LOCAL-DETAIL')->forceFill([
            'last_message_role' => 'customer',
            'last_message_at' => '2026-09-18T10:00:00Z',
            'last_message_text' => 'Mensaje guardado localmente',
            'last_message_synced_at' => now(),
        ]);
        $flow->save();
        $api = Mockery::mock(MeliApi::class);
        $api->shouldNotReceive('getPackPostSaleMessages');
        $this->app->instance(MeliApi::class, $api);

        $this->sendCallback(26, '100', "pd:{$flow->id}:p:1")->assertOk();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.mercadolibre.com'));
        $this->assertTrue($this->telegramRequests()->contains(
            fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text', ''), 'Mensaje guardado localmente')
        ));
    }

    public function test_post_sale_background_job_hydrates_local_message_snapshot(): void
    {
        $flow = $this->flow('ORDER-BACKGROUND');
        $api = Mockery::mock(MeliApi::class);
        $api->shouldReceive('getPackPostSaleMessages')->once()->withArgs(
            fn ($apiUser, string $packId, int $limit, int $offset, bool $markAsRead): bool => (string) $apiUser->meli_id === '900'
                && $packId === 'ORDER-BACKGROUND' && $limit === 50 && $offset === 0 && $markAsRead === false
        )->andReturn(['messages' => [[
            'id' => 'REMOTE-BACKGROUND',
            'from' => ['user_id' => 'BUYER-ORDER-BACKGROUND'],
            'text' => 'Respuesta pendiente',
            'message_date' => ['created' => '2026-09-18T10:00:00Z'],
        ]]]);
        $service = new TelegramPostSaleService($api, app(MeliMessageService::class), app(TelegramText::class));
        $coordinator = Mockery::mock(TelegramPostSaleSyncCoordinator::class);
        $coordinator->shouldReceive('owns')->once()->with('test-owner')->andReturnTrue();
        $coordinator->shouldReceive('release')->once()->with('test-owner');

        (new SyncMeliPostSaleForTelegramJob('100', lockOwner: 'test-owner'))
            ->handle($service, app(TelegramBotClient::class), $coordinator);

        $flow->refresh();
        $this->assertSame('customer', $flow->last_message_role);
        $this->assertSame('Respuesta pendiente', $flow->last_message_text);
        $this->assertNotNull($flow->last_message_at);
        $this->assertNotNull($flow->last_message_synced_at);
    }

    public function test_post_sale_background_chunks_keep_the_same_global_lock(): void
    {
        Queue::fake();
        $flows = new EloquentCollection(collect(range(1, 20))->map(function (int $id): MeliChatFlow {
            $flow = new MeliChatFlow;
            $flow->id = $id;

            return $flow;
        }));
        $service = Mockery::mock(TelegramPostSaleService::class);
        $service->shouldReceive('syncableFlowsAfter')->once()->with(0, 20)->andReturn($flows);
        $service->shouldReceive('syncFlow')->times(20)->andReturn([]);
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldNotReceive('sendMessage');
        $coordinator = Mockery::mock(TelegramPostSaleSyncCoordinator::class);
        $coordinator->shouldReceive('owns')->once()->with('global-owner')->andReturnTrue();
        $coordinator->shouldNotReceive('release');

        (new SyncMeliPostSaleForTelegramJob('100', lockOwner: 'global-owner'))
            ->handle($service, $telegram, $coordinator);

        Queue::assertPushed(SyncMeliPostSaleForTelegramJob::class, fn (SyncMeliPostSaleForTelegramJob $job): bool => $job->afterId === 20
            && $job->lockOwner === 'global-owner' && $job->synced === 20 && $job->failed === 0);
    }

    public function test_successful_post_sale_send_marks_conversation_answered(): void
    {
        $flow = $this->flow('ORDER-ANSWER')->forceFill([
            'requires_human' => true,
            'requires_human_at' => now(),
            'last_message_role' => 'customer',
        ]);
        $flow->save();
        Http::fake([
            'api.mercadolibre.com/*' => Http::response(['id' => 'POST-SALE-1'], 201),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $result = app(MeliMessageService::class)->trySendMessage($flow, 'Respuesta real');

        $this->assertTrue($result['ok']);
        $this->assertSame('seller', $flow->fresh()->last_message_role);
        $this->assertFalse($flow->fresh()->requires_human);
        $this->assertSame('Respuesta real', $flow->fresh()->last_message_text);
    }

    public function test_post_sale_list_detail_navigation_and_conversation(): void
    {
        $flows = collect();
        for ($i = 1; $i <= 7; $i++) {
            $flow = $this->flow('ORDER-LIST-'.$i);
            $flow->forceFill([
                'last_message_role' => 'customer',
                'last_message_at' => now()->subMinutes($i),
                'last_message_text' => 'Mensaje local '.$i,
                'last_message_synced_at' => now(),
            ])->save();
            $flows->push($flow);
        }
        $selected = $flows->first();
        $apiUser = clone $this->user;
        $apiUser->forceFill(['meli_id' => '900', 'access_token' => 'token']);
        $messaging = Mockery::mock(MeliMessageService::class);
        $messaging->shouldReceive('resolveApiUser')->once()->andReturn($apiUser);
        $api = Mockery::mock(MeliApi::class);
        $api->shouldReceive('getPackPostSaleMessages')->once()->andReturn(['messages' => [[
            'id' => 'REMOTE-LIST', 'from' => ['user_id' => 'BUYER'], 'text' => '<p>Necesito <strong>ayuda</strong></p>',
            'message_date' => ['created' => '2026-09-17T12:00:00Z'],
        ]]]);
        $service = new TelegramPostSaleService($api, $messaging, app(TelegramText::class));

        [$list] = $service->listing('p', 1);
        [$detail, $buttons] = $service->detail($selected->id, 'p', 1);
        [$conversation] = $service->conversation($selected->id);

        $this->assertStringContainsString('Página 1 de 2', $list);
        $this->assertStringContainsString('Mensaje local 1', $detail);
        $this->assertStringContainsString('Necesito ayuda', $conversation);
        $this->assertStringNotContainsString('<strong>', $conversation);
        $this->assertTrue(collect($buttons)->flatten(1)->contains(fn (array $button): bool => str_starts_with((string) ($button['callback_data'] ?? ''), 'pd:')));
    }

    public function test_post_sale_reply_confirmation_is_isolated_and_sent_once(): void
    {
        $flow = $this->flow('ORDER-3');
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldReceive('trySendMessage')->once()->withArgs(fn (MeliChatFlow $target, string $text): bool => $target->is($flow) && $text === 'Respuesta posventa')
            ->andReturn(['ok' => true, 'error' => null, 'status' => 201]);
        $this->app->instance(MeliMessageService::class, $sender);

        $this->sendCallback(50, '100', 'pr:'.$flow->id)->assertOk();
        $this->sendMessage(51, '200', 'No debe usar estado ajeno')->assertOk();
        $this->assertDatabaseHas('telegram_conversation_states', ['chat_id' => '100', 'mode' => 'awaiting_post_sale_reply']);
        $this->sendMessage(52, '100', 'Respuesta posventa')->assertOk();
        $this->sendCallback(53, '100', 'pok:'.$flow->id)->assertOk();
        $this->sendCallback(54, '100', 'pok:'.$flow->id)->assertOk();
    }

    public function test_expired_state_and_invalid_callback_are_safe(): void
    {
        TelegramConversationState::query()->create([
            'chat_id' => '100', 'mode' => 'awaiting_claim_reply', 'entity_type' => 'claim', 'entity_id' => 99,
            'expires_at' => now()->subMinute(),
        ]);
        $this->sendMessage(60, '100', 'No se captura')->assertOk();
        $this->assertDatabaseMissing('telegram_conversation_states', ['chat_id' => '100']);
        $this->sendCallback(61, '100', 'desconocido')->assertOk();
        $this->assertCount(1, $this->telegramRequests('answerCallbackQuery'));
    }

    private function fakeClaimActionApi(
        string $claimId,
        array &$actions,
        array &$offers,
        string &$status,
        int &$posts,
        int $postStatus = 201,
        bool $clearActionsAfterPost = false,
    ): void {
        Http::fake(function (Request $request) use ($claimId, &$actions, &$offers, &$status, &$posts, $postStatus, $clearActionsAfterPost) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true]);
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                $posts++;
                if ($clearActionsAfterPost && $postStatus >= 200 && $postStatus < 300) {
                    $actions = [];
                }

                return Http::response($postStatus >= 400 ? ['message' => 'private-token-rejected'] : ['id' => 'TG-ACTION-1'], $postStatus);
            }
            if (str_ends_with($path, '/partial-refund/available-offers')) {
                return Http::response(['available_offers' => $offers]);
            }
            if (preg_match('#/(detail|affects-reputation|status-history|actions-history|expected-resolutions|messages|changes)$#', $path)) {
                return Http::response([]);
            }

            return Http::response([
                'id' => $claimId,
                'status' => $status,
                'stage' => 'claim',
                'last_updated' => now()->toISOString(),
                'players' => [[
                    'role' => 'respondent',
                    'type' => 'seller',
                    'available_actions' => array_map(fn (string $action): array => ['action' => $action], $actions),
                ]],
            ]);
        });
    }

    private function claim(array $overrides = []): MeliClaim
    {
        return MeliClaim::query()->create([
            'meli_account_id' => $this->account->id, 'claim_id' => 'CLAIM-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'opened', 'stage' => 'claim', 'date_created' => now(), 'last_updated' => now(),
            'telegram_notified_at' => now(),
            ...$overrides,
        ]);
    }

    private function flow(string $order): MeliChatFlow
    {
        return MeliChatFlow::query()->create([
            'user_id' => $this->user->id, 'meli_account_id' => $this->account->id,
            'order_id' => $order, 'pack_id' => $order, 'buyer_id' => 'BUYER-'.$order,
        ]);
    }

    private function postSaleListingIds(array $keyboard): array
    {
        return collect($keyboard)->flatten(1)
            ->pluck('callback_data')
            ->filter(fn (?string $callback): bool => str_starts_with((string) $callback, 'pd:'))
            ->map(fn (string $callback): int => (int) explode(':', $callback)[1])
            ->values()->all();
    }

    private function sendMessage(int $updateId, string $chatId, string $text)
    {
        return $this->postJson('/api/telegram/webhook', [
            'update_id' => $updateId,
            'message' => ['message_id' => $updateId, 'chat' => ['id' => (int) $chatId], 'text' => $text],
        ], $this->headers());
    }

    private function sendCallback(int $updateId, string $chatId, string $data)
    {
        return $this->postJson('/api/telegram/webhook', [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId, 'data' => $data,
                'message' => ['message_id' => 777, 'chat' => ['id' => (int) $chatId]],
            ],
        ], $this->headers());
    }

    private function headers(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret'];
    }

    private function telegramRequests(?string $method = null)
    {
        return collect(Http::recorded())->pluck(0)->filter(function (Request $request) use ($method): bool {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            return $method === null || str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/'.$method);
        })->values();
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
