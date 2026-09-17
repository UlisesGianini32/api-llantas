<?php

namespace Tests\Unit;

use App\Services\MercadoLibre\MeliLabelParser;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeliLabelParserTest extends TestCase
{
    public static function quantities(): array
    {
        return [['^PQ2'], ['^PQ3'], ['^PQ10,0,1,Y'], ['^PQ2,0,1,Y'], ['^PQ9,2'], ['^PQ,0,2,N,Y']];
    }

    #[DataProvider('quantities')]
    public function test_normalizes_only_quantity_command(string $quantity): void
    {
        $before = "^XA\r\n^FO50,50^A0N,30,30^FDPRUEBA á 123^FS\r\n^BQN,2,5^FDLA,QR123^FS\r\n";
        $after = "\r\n^XZ";
        $this->assertSame([$before.'^PQ1,0,1,Y'.$after], (new MeliLabelParser)->parse($before.$quantity.$after));
    }

    public function test_preserves_bytes_without_quantity_and_splits_six_labels(): void
    {
        $label = "^XA\n^FO50,50^FDPRUEBA \xE1^FS\n^XZ";
        $normalized = "^XA\n^FO50,50^FDPRUEBA \xE1^FS\n^PQ1,0,1,Y^XZ";
        $parser = new MeliLabelParser;
        $this->assertSame([$normalized], $parser->parse($label));
        $this->assertSame(array_fill(0, 6, $normalized), $parser->parse("\xEF\xBB\xBF".implode("\r\n", array_fill(0, 6, $label))));
        $this->assertCount(2, $parser->parse($label."\n".$label));
    }

    public function test_detects_product_and_package_by_filename_case_insensitively(): void
    {
        $parser = new MeliLabelParser;

        $this->assertSame('product', $parser->detectType('ENVIO-76771745-ETIQUETAS-DE-PRODUCTOS.TXT', '^XA^XZ'));
        $this->assertSame('package', $parser->detectType('Envio-76771745-Etiquetas-de-bultos.txt', '^XA^XZ'));
        $this->assertSame('package', $parser->detectType('Envio_76771745_Etiquetas_de_CAJAS.txt', '^XA^XZ'));
    }

    public function test_detects_type_from_unambiguous_content_and_rejects_unknown_or_ambiguous_content(): void
    {
        $parser = new MeliLabelParser;

        $this->assertSame('product', $parser->detectType('archivo.txt', '^XA^FDSKU: ABC123^FS^XZ'));
        $this->assertSame('package', $parser->detectType('archivo.txt', '^XA^FDBULTO 1 DE 2^FS^XZ'));
        $this->assertNull($parser->detectType('archivo.txt', '^XA^FDABC123^FS^XZ'));
        $this->assertNull($parser->detectType('archivo.txt', '^XA^FDSKU ABC - BULTO 1^FS^XZ'));
    }

    public function test_extracts_shipment_id_and_hashes_the_original_bytes(): void
    {
        $parser = new MeliLabelParser;
        $content = "\xEF\xBB\xBF^XA^FDdato^FS^XZ";

        $this->assertSame('76771745', $parser->extractShipmentId('Envio-76771745-Etiquetas-de-productos.txt'));
        $this->assertNull($parser->extractShipmentId('Etiquetas-de-productos.txt'));
        $this->assertSame(hash('sha256', $content), $parser->calculateHash($content));
        $this->assertNotSame($parser->calculateHash(substr($content, 3)), $parser->calculateHash($content));
    }

    public function test_rejects_more_than_five_hundred_labels(): void
    {
        $this->expectException(ValidationException::class);
        (new MeliLabelParser)->parse(str_repeat('^XA^XZ', MeliLabelParser::MAX_LABELS + 1));
    }

    public function test_product_extracts_real_quantities_and_defaults_to_one_without_pq(): void
    {
        $parser = new MeliLabelParser;

        $this->assertSame(4, $parser->extractPrintQuantity('^XA^PQ4,0,1,Y^XZ'));
        $this->assertSame(12, $parser->extractPrintQuantity('^XA^PQ12,0,1,Y^XZ'));
        $this->assertSame(1, $parser->extractPrintQuantity('^XA^FDPRODUCTO^FS^XZ'));
    }

    public function test_product_preserves_quantity_and_applies_two_by_one_dimensions_once(): void
    {
        $original = '^XA^PW900^LL1200^CI28^LH0,0^FO22,115^FB370^FH^FDABC_5E123 SKU-9^FS^BCN,80^FD750123^FS^PQ12,0,1,Y^XZ';
        $normalized = (new MeliLabelParser)->parse($original, MeliLabelParser::TYPE_PRODUCT)[0];

        $this->assertStringContainsString('^PQ12,0,1,Y', $normalized);
        $this->assertStringNotContainsString('^PQ1,0,1,Y', $normalized);
        $this->assertSame(1, substr_count($normalized, '^PW406'));
        $this->assertSame(1, substr_count($normalized, '^LL203'));
        $this->assertStringNotContainsString('^PW900', $normalized);
        $this->assertStringNotContainsString('^LL1200', $normalized);
        $this->assertStringNotContainsString('^POR', $normalized);
        $this->assertStringContainsString('^LH0,0^FO22,115^FB370^FH^FDABC_5E123 SKU-9^FS^BCN,80^FD750123^FS', $normalized);
    }

    public function test_product_adds_dimensions_without_adding_or_forcing_pq(): void
    {
        $normalized = (new MeliLabelParser)->parse('^XA^FO1,2^FDSKU^FS^XZ', MeliLabelParser::TYPE_PRODUCT)[0];

        $this->assertSame('^XA^PW406^LL203^FO1,2^FDSKU^FS^XZ', $normalized);
        $this->assertStringNotContainsString('^PQ', $normalized);
    }

    public function test_package_still_forces_a_single_copy_without_product_dimensions(): void
    {
        $normalized = (new MeliLabelParser)->parse('^XA^PW812^LL600^FDBULTO^FS^PQ12,0,1,Y^XZ', MeliLabelParser::TYPE_PACKAGE)[0];

        $this->assertStringContainsString('^PQ1,0,1,Y', $normalized);
        $this->assertStringContainsString('^PW812^LL600', $normalized);
        $this->assertStringNotContainsString('^PW406', $normalized);
        $this->assertStringNotContainsString('^LL203', $normalized);
    }

    public function test_real_product_batch_has_fifteen_blocks_and_one_hundred_sixty_four_physical_labels(): void
    {
        $quantities = [4, 12, 12, 6, 6, 12, 6, 20, 20, 12, 12, 12, 12, 12, 6];
        $content = implode("\n", array_map(
            fn (int $quantity, int $index): string => "^XA^FDSKU-{$index}^FS^PQ{$quantity},0,1,Y^XZ",
            $quantities,
            array_keys($quantities),
        ));
        $parser = new MeliLabelParser;
        $labels = $parser->parse($content, MeliLabelParser::TYPE_PRODUCT);

        $this->assertCount(15, $labels);
        $this->assertSame($quantities, $parser->printQuantities($labels));
        $this->assertSame(164, array_sum($parser->printQuantities($labels)));
    }

    public function test_product_rejects_malformed_multiple_or_absurd_pq_quantities(): void
    {
        $parser = new MeliLabelParser;

        foreach (['^XA^PQbad^XZ', '^XA^PQ0,0,1,Y^XZ', '^XA^PQ999999,0,1,Y^XZ', '^XA^PQ4^PQ12^XZ'] as $label) {
            try {
                $parser->parse($label, MeliLabelParser::TYPE_PRODUCT);
                $this->fail("The label {$label} should have failed validation.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_product_rejects_a_batch_over_the_physical_label_limit(): void
    {
        $this->expectException(ValidationException::class);
        (new MeliLabelParser)->parse(str_repeat('^XA^PQ101,0,1,Y^XZ', 100), MeliLabelParser::TYPE_PRODUCT);
    }

    public static function invalidFiles(): array
    {
        return [[''], ['sin etiquetas'], ['^XA'], ['^XZ'], ['^XA^XA^FDx^FS^XZ'], ['^XA^FDok^FS^XZ^XA'], ['texto^XA^XZ'], ['^XA^PQbad^XZ']];
    }

    public function test_large_graphic_label_does_not_hit_regex_backtracking_limits(): void
    {
        $label = '^XA^GFA,2000000,2000000,100,'.str_repeat('A', 4000000).'^FS^PQ2^XZ';
        $this->assertSame([str_replace('^PQ2', '^PQ1,0,1,Y', $label)], (new MeliLabelParser)->parse($label));
    }

    #[DataProvider('invalidFiles')]
    public function test_rejects_invalid_or_truncated_content(string $content): void
    {
        $this->expectException(ValidationException::class);
        (new MeliLabelParser)->parse($content);
    }
}
