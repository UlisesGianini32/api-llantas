<?php

namespace App\Console\Commands;

use App\Models\InventoryProduct;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class PurchasingImportSupplierCodesCommand extends Command
{
    protected $signature = 'purchasing:import-supplier-codes {file? : Ruta opcional al archivo Excel de proveedores}';

    protected $description = 'Importa y vincula claves y códigos de catálogo de proveedores con productos de inventario';

    /**
     * Marcas y proveedores estrictamente ignorados por instrucción del usuario.
     */
    private const IGNORED_SHEETS = [
        'fanola',
        'olaplex',
        'icon',
    ];

    public function handle(): int
    {
        $this->info('=====================================================');
        $this->info('  IMPORTADOR DE CÓDIGOS DE CATÁLOGO DE PROVEEDORES  ');
        $this->info('=====================================================');

        $mainFile = $this->argument('file') ?: base_path('database/data/listas_proveedores_sbs.xlsx');
        $lorealFile = base_path('database/data/loreal_serie_expert.xlsx');

        $totalExtracted = 0;
        $totalMapped = 0;

        if (file_exists($mainFile)) {
            $this->line("Procesando archivo principal: <comment>{$mainFile}</comment>");
            $res = $this->processMainWorkbook($mainFile);
            $totalExtracted += $res['extracted'];
            $totalMapped += $res['mapped'];
        } else {
            $this->warn("Archivo principal no encontrado en {$mainFile}. Se omitirá.");
        }

        if (file_exists($lorealFile)) {
            $this->line("Procesando lista Serie Expert L'Oréal: <comment>{$lorealFile}</comment>");
            $res = $this->processLorealWorkbook($lorealFile);
            $totalExtracted += $res['extracted'];
            $totalMapped += $res['mapped'];
        }

        $this->newLine();
        $this->info('=====================================================');
        $this->info("✓ Extracción completada.");
        $this->info("  Total códigos extraídos de proveedores: {$totalExtracted}");
        $this->info("  Total vinculados a productos existentes: {$totalMapped}");
        $this->info("  Registros actuales en supplier_products: " . SupplierProduct::count());
        $this->info('=====================================================');

        return Command::SUCCESS;
    }

    private function processMainWorkbook(string $filePath): array
    {
        $extracted = 0;
        $mapped = 0;

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (Throwable $e) {
            $this->error("Error al cargar {$filePath}: {$e->getMessage()}");
            return ['extracted' => 0, 'mapped' => 0];
        }

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $cleanName = mb_strtolower(trim($sheetName));

            // Exclusión explícita solicitada por el usuario
            if (in_array($cleanName, self::IGNORED_SHEETS, true)) {
                $this->warn("  [OMITIDA] Hoja '{$sheetName}' ignorada por restricción de marca (Fanola/Olaplex/Icon).");
                continue;
            }

            $worksheet = $spreadsheet->getSheetByName($sheetName);
            if (! $worksheet) {
                continue;
            }

            $rows = $worksheet->toArray(null, true, true, false);
            if (empty($rows)) {
                continue;
            }

            $records = [];

            if (str_contains($cleanName, 'alfaparf')) {
                $records = $this->parseAlfaparfRows($rows, 'Alfaparf');
            } elseif ($cleanName === 'joico') {
                $records = $this->parseJoicoRows($rows, 'Joico');
            } elseif ($cleanName === 'loreal') {
                $records = $this->parseLorealRows($rows, "L'Oréal");
            } elseif ($cleanName === 'tec italy') {
                $records = $this->parseTecItalyRows($rows, 'Tec Italy');
            } elseif ($cleanName === 'salerm') {
                $records = $this->parseSalermRows($rows, 'Salerm');
            } elseif ($cleanName === 'lendan') {
                $records = $this->parseLendanRows($rows, 'Lendan');
            } elseif ($cleanName === 'whal') {
                $records = $this->parseWahlRows($rows, 'Wahl');
            } elseif ($cleanName === 'letmex') {
                $records = $this->parseLetmexRows($rows, 'Letmex');
            } elseif ($cleanName === 'abc') {
                $records = $this->parseAbcRows($rows, 'ABC');
            } elseif ($cleanName === 'schwarzkopf.') {
                $records = $this->parseSchwarzkopfRows($rows, 'Schwarzkopf');
            } elseif ($cleanName === 'davines') {
                $records = $this->parseDavinesRows($rows, 'Davines');
            }

            if (! empty($records)) {
                $sheetExtracted = count($records);
                $sheetMapped = $this->syncRecords($records);
                $extracted += $sheetExtracted;
                $mapped += $sheetMapped;
                $this->line("  [OK] Hoja '{$sheetName}': {$sheetExtracted} códigos leídos, {$sheetMapped} vinculados.");
            }
        }

        return ['extracted' => $extracted, 'mapped' => $mapped];
    }

    private function processLorealWorkbook(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (Throwable $e) {
            $this->error("Error al cargar {$filePath}: {$e->getMessage()}");
            return ['extracted' => 0, 'mapped' => 0];
        }

        $records = [];
        foreach ($rows as $r) {
            if (count($r) < 8) {
                continue;
            }
            // Col 3: EAN, Col 6: SAP, Col 7: Descripción, Col 9: Unitario
            $sap = trim((string) ($r[6] ?? ''));
            $ean = trim((string) ($r[3] ?? ''));
            $desc = trim((string) ($r[7] ?? ''));
            $cost = isset($r[9]) && is_numeric($r[9]) ? (float) $r[9] : null;

            if ($sap !== '' && $sap !== 'SAP' && strlen($sap) >= 4) {
                $records[] = [
                    'supplier_name' => "L'Oréal",
                    'supplier_sku' => $sap,
                    'product_name' => $desc,
                    'cost' => $cost,
                    'ean' => (strlen($ean) >= 8 && is_numeric($ean)) ? $ean : null,
                ];
            }
        }

        $mapped = $this->syncRecords($records);
        $this->line("  [OK] L'Oréal Serie Expert: " . count($records) . " códigos leídos, {$mapped} vinculados.");

        return ['extracted' => count($records), 'mapped' => $mapped];
    }

    private function parseAlfaparfRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 3 || empty($r[1]) || empty($r[2])) {
                continue;
            }
            $code = trim((string) $r[1]);
            $desc = trim((string) $r[2]);
            if ($code === '' || $code === 'CODIGO' || strlen($code) < 3) {
                continue;
            }
            $cost = isset($r[4]) && is_numeric($r[4]) ? (float) $r[4] : null;

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $code,
                'product_name' => $desc,
                'cost' => $cost,
            ];
        }
        return $items;
    }

    private function parseJoicoRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[0]) || empty($r[1])) {
                continue;
            }
            $code = trim((string) $r[0]);
            $desc = trim((string) $r[1]);
            if ($code === '' || str_starts_with($code, 'INVENTARIO') || strlen($code) < 2) {
                continue;
            }

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $code,
                'product_name' => $desc,
                'cost' => null,
            ];
        }
        return $items;
    }

    private function parseLorealRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[0]) || empty($r[1])) {
                continue;
            }
            $code = trim((string) $r[0]);
            $desc = trim((string) $r[1]);
            if ($code === '' || $code === 'REF' || strlen($code) < 4) {
                continue;
            }

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $code,
                'product_name' => $desc,
                'cost' => null,
            ];
        }
        return $items;
    }

    private function parseTecItalyRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            foreach ([0, 4, 8] as $colIdx) {
                if (isset($r[$colIdx], $r[$colIdx + 1])) {
                    $code = trim((string) $r[$colIdx]);
                    $desc = trim((string) $r[$colIdx + 1]);
                    if (is_numeric($code) && strlen($code) >= 5 && $desc !== '') {
                        $items[] = [
                            'supplier_name' => $supplierName,
                            'supplier_sku' => $code,
                            'product_name' => $desc,
                            'cost' => null,
                        ];
                    }
                }
            }
        }
        return $items;
    }

    private function parseSalermRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[0]) || empty($r[1])) {
                continue;
            }
            $code = trim((string) $r[0]);
            $desc = trim((string) $r[1]);
            if ($code === '' || $code === 'REF.' || str_starts_with($code, 'SALERM COSMETICS') || strlen($code) < 2) {
                continue;
            }

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $code,
                'product_name' => $desc,
                'cost' => null,
            ];
        }
        return $items;
    }

    private function parseLendanRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[0]) || empty($r[1])) {
                continue;
            }
            $code = trim((string) $r[0]);
            $desc = trim((string) $r[1]);
            if ($code === '' || $code === 'REF.' || str_starts_with($code, 'LENDAN') || strlen($code) < 2) {
                continue;
            }

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $code,
                'product_name' => $desc,
                'cost' => null,
            ];
        }
        return $items;
    }

    private function parseWahlRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[0]) || empty($r[1])) {
                continue;
            }
            $code = trim((string) $r[0]);
            $desc = trim((string) $r[1]);
            if (is_numeric($code) && strlen($code) >= 4 && $desc !== '') {
                $items[] = [
                    'supplier_name' => $supplierName,
                    'supplier_sku' => $code,
                    'product_name' => $desc,
                    'cost' => null,
                ];
            }
        }
        return $items;
    }

    private function parseLetmexRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[0]) || empty($r[1])) {
                continue;
            }
            $desc = trim((string) $r[0]);
            $code = trim((string) $r[1]);
            if ($code === '' || str_starts_with($desc, 'cantidad') || strlen($code) < 2) {
                continue;
            }

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $code,
                'product_name' => $desc,
                'cost' => null,
            ];
        }
        return $items;
    }

    private function parseAbcRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (! empty($r[0]) && ! empty($r[1])) {
                $c = trim((string) $r[0]);
                $d = trim((string) $r[1]);
                $cost = isset($r[2]) && is_numeric($r[2]) ? (float) $r[2] : null;
                if (is_numeric($c) && strlen($c) >= 5) {
                    $items[] = [
                        'supplier_name' => $supplierName,
                        'supplier_sku' => $c,
                        'product_name' => $d,
                        'cost' => $cost,
                    ];
                }
            }
            if (! empty($r[7]) && ! empty($r[8])) {
                $c = trim((string) $r[7]);
                $d = trim((string) $r[8]);
                $cost = isset($r[9]) && is_numeric($r[9]) ? (float) $r[9] : null;
                if (is_numeric($c) && strlen($c) >= 5) {
                    $items[] = [
                        'supplier_name' => $supplierName,
                        'supplier_sku' => $c,
                        'product_name' => $d,
                        'cost' => $cost,
                    ];
                }
            }
        }
        return $items;
    }

    private function parseSchwarzkopfRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[1])) {
                continue;
            }
            $material = trim((string) $r[1]);
            if ($material === '' || $material === 'Material') {
                continue;
            }
            $cost = isset($r[3]) && is_numeric($r[3]) ? (float) $r[3] : null;

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $material,
                'product_name' => $material,
                'cost' => $cost,
            ];
        }
        return $items;
    }

    private function parseDavinesRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (count($r) < 2 || empty($r[1])) {
                continue;
            }
            $prod = trim((string) $r[1]);
            if ($prod === '' || $prod === 'PRODUCTO' || str_starts_with($prod, 'LISTA DE PRECIOS')) {
                continue;
            }
            $cost = isset($r[3]) && is_numeric($r[3]) ? (float) $r[3] : null;

            $items[] = [
                'supplier_name' => $supplierName,
                'supplier_sku' => $prod,
                'product_name' => $prod,
                'cost' => $cost,
            ];
        }
        return $items;
    }

    /**
     * Sincroniza los registros con la tabla supplier_products
     * buscando coincidencias con inventory_products.
     */
    private function syncRecords(array $records): int
    {
        $mappedCount = 0;

        foreach ($records as $entry) {
            $supplierName = $entry['supplier_name'];
            $supplierSku = $entry['supplier_sku'];
            $productName = $entry['product_name'] ?? '';
            $cost = $entry['cost'] ?? null;
            $ean = $entry['ean'] ?? null;

            // Buscar proveedor existente o relacionar
            $supplier = Supplier::where('name', $supplierName)->first();

            // Buscar coincidencia en inventory_products:
            // 1. Por código de barras (EAN)
            $product = null;
            if ($ean) {
                $product = InventoryProduct::where('barcode', $ean)
                    ->orWhere('barcode_secondary', $ean)
                    ->first();
            }

            // 2. Por coincidencia exacta de SKU
            if (! $product) {
                $product = InventoryProduct::where('sku', $supplierSku)->first();
            }

            // 3. Por marca + nombre similar
            if (! $product && $productName !== '') {
                $cleanSearch = $this->sanitizeForSearch($productName);
                if (strlen($cleanSearch) >= 4) {
                    $product = InventoryProduct::query()
                        ->where(function ($q) use ($supplierName) {
                            $q->where('brand', 'like', "%{$supplierName}%")
                                ->orWhere('supplier', 'like', "%{$supplierName}%");
                        })
                        ->where('name', 'like', "%{$cleanSearch}%")
                        ->first();
                }
            }

            // Si encontramos producto en inventory_products, guardamos relación
            if ($product) {
                SupplierProduct::updateOrCreate(
                    [
                        'supplier_name' => $supplierName,
                        'inventory_product_id' => $product->id,
                        'supplier_sku' => $supplierSku,
                    ],
                    [
                        'supplier_id' => $supplier?->id,
                        'supplier_product_name' => $productName ?: $product->name,
                        'supplier_cost' => $cost ?: $product->cost,
                        'is_primary' => true,
                    ]
                );
                $mappedCount++;
            }
        }

        return $mappedCount;
    }

    private function sanitizeForSearch(string $text): string
    {
        $words = preg_split('/\s+/', trim($text));
        // Tomar las primeras dos palabras significativas
        $meaningful = array_filter($words, fn ($w) => strlen($w) >= 3);
        return implode(' ', array_slice($meaningful, 0, 2));
    }
}
