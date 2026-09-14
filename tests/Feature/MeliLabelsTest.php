<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\MeliLabelPrint;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeliLabelsTest extends TestCase
{
    private object $labelPrintsMigration;

    private object $explicitCountsMigration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Shared account queries are unrelated to this isolated module.
        $this->withoutMiddleware(HandleInertiaRequests::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_OPERATIONS);
            $table->rememberToken();
            $table->timestamps();
        });

        $this->labelPrintsMigration = require database_path('migrations/2026_09_14_000002_create_meli_label_prints_table.php');
        $this->labelPrintsMigration->up();
        $this->explicitCountsMigration = require database_path('migrations/2026_09_14_000003_add_explicit_counts_to_meli_label_prints_table.php');
        $this->explicitCountsMigration->up();
    }

    protected function tearDown(): void
    {
        $this->explicitCountsMigration->down();
        $this->labelPrintsMigration->down();
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function operator(string $email = 'operations@example.test'): User
    {
        return User::query()->forceCreate([
            'name' => 'Operaciones',
            'email' => $email,
            'role' => User::ROLE_OPERATIONS,
        ]);
    }

    private function analyze(string $filename, string $content)
    {
        return $this->postJson('/mercado-libre/etiquetas/procesar', [
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
        ]);
    }

    public function test_guest_cannot_access_upload_or_record_printing(): void
    {
        $print = MeliLabelPrint::query()->create([
            'type' => MeliLabelPrint::TYPE_PRODUCT,
            'original_filename' => 'labels.txt',
            'file_hash' => str_repeat('a', 64),
            'labels_count' => 1,
            'status' => MeliLabelPrint::STATUS_ANALYZED,
        ]);

        $this->get('/mercado-libre/etiquetas')->assertRedirect('/login');
        $this->postJson('/mercado-libre/etiquetas/procesar')->assertUnauthorized();
        $this->postJson("/mercado-libre/etiquetas/registrar-impresion/{$print->id}")->assertUnauthorized();
    }

    public function test_operator_can_open_page_with_persistent_history(): void
    {
        $operator = $this->operator();
        MeliLabelPrint::query()->create([
            'shipment_id' => '76771745',
            'type' => MeliLabelPrint::TYPE_PACKAGE,
            'original_filename' => 'Envio-76771745-Etiquetas-de-bultos.txt',
            'file_hash' => str_repeat('b', 64),
            'labels_count' => 3,
            'status' => MeliLabelPrint::STATUS_PRINTED,
            'printer_name' => '4BARCODE',
            'printed_at' => now(),
            'created_by' => $operator->id,
        ]);

        $this->actingAs($operator)->get('/mercado-libre/etiquetas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MercadoLibre/Etiquetas')
                ->has('history', 1)
                ->where('history.0.shipment_id', '76771745')
                ->where('history.0.type', 'package'));
    }

    public function test_product_upload_preserves_quantities_and_returns_physical_and_block_counts(): void
    {
        $this->actingAs($this->operator());
        $input = str_repeat("^XA\n^FDPRODUCTO PRUEBA^FS\n^PQ10,0,1,Y\n^XZ\n", 6);
        $response = $this->analyze('Envio-76771745-Etiquetas-de-productos.txt', $input)
            ->assertOk()
            ->assertJsonPath('shipment_id', '76771745')
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('block_count', 6)
            ->assertJsonPath('count', 60)
            ->assertJsonPath('physical_label_count', 60)
            ->assertJsonCount(6, 'quantities')
            ->assertJsonPath('quantities.0', 10)
            ->assertJsonPath('file_hash', hash('sha256', $input))
            ->assertJsonPath('fingerprint', hash('sha256', $input))
            ->assertJsonPath('previous_print', null)
            ->assertJsonPath('encoding', 'base64');

        $this->assertSame("^XA^PW406^LL203\n^FDPRODUCTO PRUEBA^FS\n^PQ10,0,1,Y\n^XZ", base64_decode($response->json('labels.0')));
        $this->assertDatabaseHas('meli_label_prints', [
            'id' => $response->json('print_id'),
            'status' => 'analyzed',
            'file_hash' => hash('sha256', $input),
            'labels_count' => 6,
            'zpl_blocks_count' => 6,
            'physical_labels_count' => 60,
        ]);
    }

    public function test_real_fifteen_block_product_batch_reports_and_persists_one_hundred_sixty_four_labels(): void
    {
        $this->actingAs($this->operator());
        $quantities = [4, 12, 12, 6, 6, 12, 6, 20, 20, 12, 12, 12, 12, 12, 6];
        $content = implode("\n", array_map(
            fn (int $quantity, int $index): string => "^XA^FDSKU-{$index}^FS^PQ{$quantity},0,1,Y^XZ",
            $quantities,
            array_keys($quantities),
        ));

        $response = $this->analyze('Envio-76771745-Etiquetas-de-productos (1).txt', $content)
            ->assertOk()
            ->assertJsonPath('block_count', 15)
            ->assertJsonPath('count', 164)
            ->assertJsonPath('physical_label_count', 164)
            ->assertJsonPath('quantities', $quantities);

        $this->assertDatabaseHas('meli_label_prints', [
            'id' => $response->json('print_id'),
            'labels_count' => 15,
            'zpl_blocks_count' => 15,
            'physical_labels_count' => 164,
        ]);
    }

    public function test_product_and_package_prints_can_coexist_under_the_same_shipment(): void
    {
        $this->actingAs($this->operator());
        $product = $this->analyze('Envio-76771745-Etiquetas-de-productos.txt', '^XA^FDSKU 123^FS^XZ')->assertOk();
        $package = $this->analyze('Envio-76771745-Etiquetas-de-bultos.txt', '^XA^FDBULTO 1^FS^PQ12,0,1,Y^XZ')
            ->assertOk()
            ->assertJsonPath('block_count', 1)
            ->assertJsonPath('physical_label_count', 1)
            ->assertJsonPath('quantities.0', 1);
        $this->assertSame('^XA^FDBULTO 1^FS^PQ1,0,1,Y^XZ', base64_decode($package->json('labels.0')));

        $this->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$product->json('print_id'), [
            'printer_name' => 'Zebra productos', 'status' => 'printed',
        ])->assertOk();
        $this->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$package->json('print_id'), [
            'printer_name' => '4BARCODE bultos', 'status' => 'printed',
        ])->assertOk();

        $this->assertDatabaseHas('meli_label_prints', ['shipment_id' => '76771745', 'type' => 'product', 'status' => 'printed']);
        $this->assertDatabaseHas('meli_label_prints', ['shipment_id' => '76771745', 'type' => 'package', 'status' => 'printed']);
        $this->assertSame(2, MeliLabelPrint::query()->where('shipment_id', '76771745')->where('status', 'printed')->count());
    }

    public function test_additive_migration_backfills_legacy_count_into_both_explicit_columns(): void
    {
        $this->explicitCountsMigration->down();
        $id = MeliLabelPrint::query()->insertGetId([
            'type' => 'package',
            'original_filename' => 'legacy.txt',
            'file_hash' => str_repeat('c', 64),
            'labels_count' => 7,
            'status' => 'printed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->explicitCountsMigration->up();

        $this->assertDatabaseHas('meli_label_prints', [
            'id' => $id,
            'labels_count' => 7,
            'zpl_blocks_count' => 7,
            'physical_labels_count' => 7,
        ]);
    }

    public function test_previously_printed_hash_requires_explicit_reprint_confirmation(): void
    {
        $this->actingAs($this->operator());
        $content = '^XA^FDSKU REPETIDO^FS^XZ';
        $first = $this->analyze('Envio-42-Etiquetas-de-productos.txt', $content)->assertOk();
        $this->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$first->json('print_id'), [
            'printer_name' => '4BARCODE', 'status' => 'printed',
        ])->assertOk();

        $second = $this->analyze('archivo-renombrado.txt', $content)
            ->assertOk()
            ->assertJsonPath('previous_print.id', $first->json('print_id'));

        $this->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$second->json('print_id'), [
            'printer_name' => '4BARCODE', 'status' => 'printed', 'confirmed_reprint' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmed_reprint');
        $this->assertDatabaseHas('meli_label_prints', ['id' => $second->json('print_id'), 'status' => 'analyzed']);

        $this->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$second->json('print_id'), [
            'printer_name' => '4BARCODE', 'status' => 'printed', 'confirmed_reprint' => true,
        ])->assertOk()->assertJsonPath('print.status', 'printed');
        $this->assertSame(2, MeliLabelPrint::query()->where('file_hash', hash('sha256', $content))->where('status', 'printed')->count());
    }

    public function test_failed_qz_attempt_is_recorded_without_marking_it_printed(): void
    {
        $this->actingAs($this->operator());
        $analysis = $this->analyze('Envio-55-Etiquetas-de-bultos.txt', '^XA^FDBULTO^FS^XZ')->assertOk();

        $this->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$analysis->json('print_id'), [
            'printer_name' => '4BARCODE',
            'status' => 'failed',
            'error_message' => '<b>QZ desconectado</b>',
        ])->assertOk()->assertJsonPath('print.status', 'failed');

        $this->assertDatabaseHas('meli_label_prints', [
            'id' => $analysis->json('print_id'),
            'status' => 'failed',
            'error_message' => 'QZ desconectado',
            'printed_at' => null,
        ]);
    }

    public function test_user_cannot_record_another_users_analysis(): void
    {
        $owner = $this->operator('owner@example.test');
        $foreign = $this->operator('foreign@example.test');
        $analysis = $this->actingAs($owner)->analyze('Envio-1-Etiquetas-de-productos.txt', '^XA^FDSKU^FS^XZ')->assertOk();

        $this->actingAs($foreign)->postJson('/mercado-libre/etiquetas/registrar-impresion/'.$analysis->json('print_id'), [
            'printer_name' => '4BARCODE', 'status' => 'printed',
        ])->assertNotFound();
    }

    public function test_upload_validation_rejects_invalid_files_and_unknown_type(): void
    {
        $this->actingAs($this->operator());
        foreach ([
            null,
            UploadedFile::fake()->createWithContent('empty.txt', ''),
            UploadedFile::fake()->createWithContent('invalid.txt', 'hello'),
            UploadedFile::fake()->createWithContent('unknown.txt', '^XA^FDABC123^FS^XZ'),
            UploadedFile::fake()->createWithContent('label.php', '^XA^FDSKU^FS^XZ'),
            UploadedFile::fake()->create('large.txt', 5121, 'text/plain'),
        ] as $file) {
            $this->postJson('/mercado-libre/etiquetas/procesar', ['file' => $file])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
    }
}
