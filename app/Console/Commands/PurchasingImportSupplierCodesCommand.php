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

    /**
     * Aliases de marcas en inventory_products para cada proveedor.
     */
    private const BRAND_ALIASES = [
        'Alfaparf' => ['ALFAPARF', 'YELLOW', 'MILANO', 'IL SALONE'],
        'Tec Italy' => ['TEC ITALY', 'TECITALY', 'TECITALLY', 'TEC'],
        'Salerm' => ['SALERM', 'BIOKERA', 'SALERMVISON'],
        'Lendan' => ['LENDAN'],
        "L'Oréal" => ["L'OREAL", "L'ORÉAL", 'LOREAL', 'SERIE EXPERT'],
        'Wella' => ['WELLA', 'COLOR PERFECT', 'ILLUMINA', 'SEBASTIAN', 'INVIGO', 'SP'],
        'Schwarzkopf' => ['SCHWARZKOPF', 'BC BONACURE', 'OSIS', 'IGORA'],
        'Wahl' => ['WAHL', 'WHAL'],
        'Letmex' => ['LETMEX', 'MUEBLES'],
        'ABC' => ['ABC', 'AUTHENTIC BEAUTY CONCEPT'],
        'Joico' => ['JOICO', 'K-PAK', 'DEFY DAMAGE'],
        'Davines' => ['DAVINES', 'MORE INSIDE', 'ESSENTIAL HAIRCARE', 'OI'],
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
        $this->info('✓ Extracción completada.');
        $this->info("  Total códigos extraídos de proveedores: {$totalExtracted}");
        $this->info("  Total vinculados a productos existentes: {$totalMapped}");
        $this->info('  Registros actuales en supplier_products: ' . SupplierProduct::count());
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
            } elseif ($cleanName === 'wella') {
                $records = $this->parseWellaRows($rows, 'Wella');
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
            $colCount = count($r);
            for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                $val = trim((string) ($r[$cIdx] ?? ''));
                if (is_numeric($val) && strlen($val) === 7) {
                    $desc = '';
                    $cap = '';
                    if ($cIdx + 1 < $colCount && ! empty($r[$cIdx + 1])) {
                        $desc = trim((string) $r[$cIdx + 1]);
                    }
                    if ($cIdx + 2 < $colCount && ! empty($r[$cIdx + 2])) {
                        $cap = trim((string) $r[$cIdx + 2]);
                    }
                    $fullName = trim($desc . ($cap !== '' ? ' ' . $cap : ''));
                    if ($fullName !== '') {
                        $items[] = [
                            'supplier_name' => $supplierName,
                            'supplier_sku' => $val,
                            'product_name' => $fullName,
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
        $rowsCount = count($rows);
        for ($i = 2; $i < $rowsCount; $i++) {
            $r = $rows[$i];
            $c0 = isset($r[0]) ? trim((string) $r[0]) : '';
            $c1 = isset($r[1]) ? trim((string) $r[1]) : '';
            $c2 = isset($r[2]) ? trim((string) $r[2]) : '';

            $code = null;
            $desc = null;

            if ($c1 !== '' && $c2 !== '' && strlen($c1) <= 10 && ! $this->startsWithAny($c1, ['TINTE', 'PEROXIDO', 'BIOKERA', 'SALERM'])) {
                $code = $c1;
                $desc = $c2;
            } elseif ($c0 !== '' && $c1 !== '') {
                $code = $c0;
                $desc = $c1;
            }

            if ($code && $desc && $code !== 'REF.' && ! str_starts_with($code, 'SALERM') && strlen($code) >= 2) {
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

    private function parseLendanRows(array $rows, string $supplierName): array
    {
        $items = [];
        $rowsCount = count($rows);
        for ($i = 2; $i < $rowsCount; $i++) {
            $r = $rows[$i];
            $c0 = isset($r[0]) ? trim((string) $r[0]) : '';
            $c1 = isset($r[1]) ? trim((string) $r[1]) : '';
            $c2 = isset($r[2]) ? trim((string) $r[2]) : '';

            $code = null;
            $desc = null;

            if ($c1 !== '' && $c2 !== '' && strlen($c1) <= 10 && ! $this->startsWithAny($c1, ['LENDAN', 'LD '])) {
                $code = $c1;
                $desc = $c2;
            } elseif ($c0 !== '' && $c1 !== '') {
                $code = $c0;
                $desc = $c1;
            }

            if ($code && $desc && $code !== 'REF.' && ! str_starts_with($code, 'LENDAN') && strlen($code) >= 2) {
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

    private function parseWahlRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            // En la hoja de Wahl el código está en col 5 y nombre en col 6
            if (isset($r[5], $r[6])) {
                $code = trim((string) $r[5]);
                $desc = trim((string) $r[6]);
                if (strlen($code) >= 4 && $desc !== '' && $code !== 'WAHL' && ! str_starts_with($desc, 'nombre')) {
                    $items[] = [
                        'supplier_name' => $supplierName,
                        'supplier_sku' => $code,
                        'product_name' => $desc,
                        'cost' => null,
                    ];
                }
            }
        }
        return $items;
    }

    private function parseLetmexRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (isset($r[1], $r[2])) {
                $desc = trim((string) $r[1]);
                $code = trim((string) $r[2]);
                if ($code !== '' && ! str_starts_with($desc, 'cantidad') && strlen($code) >= 2) {
                    $items[] = [
                        'supplier_name' => $supplierName,
                        'supplier_sku' => $code,
                        'product_name' => $desc,
                        'cost' => null,
                    ];
                }
            }
        }
        return $items;
    }

    private function parseAbcRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (isset($r[1], $r[2])) {
                $c = trim((string) $r[1]);
                $d = trim((string) $r[2]);
                $cost = isset($r[3]) && is_numeric($r[3]) ? (float) $r[3] : null;
                if (is_numeric($c) && strlen($c) >= 5 && $d !== '') {
                    $items[] = [
                        'supplier_name' => $supplierName,
                        'supplier_sku' => $c,
                        'product_name' => $d,
                        'cost' => $cost,
                    ];
                }
            }
            if (isset($r[9], $r[10])) {
                $c = trim((string) $r[9]);
                $d = trim((string) $r[10]);
                $cost = isset($r[11]) && is_numeric($r[11]) ? (float) $r[11] : null;
                if (is_numeric($c) && strlen($c) >= 5 && $d !== '') {
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

    private function parseWellaRows(array $rows, string $supplierName): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (isset($r[0], $r[1])) {
                $c = trim((string) $r[0]);
                $d = trim((string) $r[1]);
                $cost = isset($r[2]) && is_numeric($r[2]) ? (float) $r[2] : null;
                if ((is_numeric($c) && strlen($c) >= 8) || str_starts_with($c, 'W')) {
                    $items[] = [
                        'supplier_name' => $supplierName,
                        'supplier_sku' => $c,
                        'product_name' => $d,
                        'cost' => $cost,
                        'ean' => (is_numeric($c) && strlen($c) === 13) ? $c : null,
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
     * buscando coincidencias con inventory_products de manera inteligente.
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

            $supplier = Supplier::where('name', $supplierName)->first();

            // Buscar coincidencia en inventory_products:
            // 1. Por código de barras (EAN de 13 u 8 dígitos)
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

            // 3. Por SKU o código de barras que contenga el código del proveedor
            if (! $product && strlen($supplierSku) >= 4) {
                $product = InventoryProduct::where('sku', 'like', "%{$supplierSku}%")
                    ->orWhere('barcode', 'like', "%{$supplierSku}%")
                    ->orWhere('barcode_secondary', 'like', "%{$supplierSku}%")
                    ->first();
            }

            // 4. Por marca y similitud de nombre
            if (! $product && $productName !== '') {
                $aliases = self::BRAND_ALIASES[$supplierName] ?? [$supplierName];
                $words = preg_split('/[\s\-\/\,\.]+/', mb_strtolower($productName));
                $stopWords = ['shp', 'cnd', 'con', 'para', 'del', 'las', 'los', 'por', '1lt', '1000ml', '500ml', '300ml', '250ml', 'vol', 'pza', 'tinte'];
                $keywords = array_values(array_filter($words, fn ($w) => strlen($w) >= 3 && ! in_array($w, $stopWords, true)));

                if (! empty($keywords)) {
                    $query = InventoryProduct::query()->where(function ($q) use ($aliases) {
                        foreach ($aliases as $al) {
                            $q->orWhere('brand', 'like', "%{$al}%")
                                ->orWhere('supplier', 'like', "%{$al}%")
                                ->orWhere('name', 'like', "%{$al}%");
                        }
                    });

                    // Intentar con las 2 primeras palabras clave
                    $searchWords = array_slice($keywords, 0, 2);
                    foreach ($searchWords as $kw) {
                        $query->where('name', 'like', "%{$kw}%");
                    }

                    $product = $query->first();
                }
            }

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

    private function startsWithAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
