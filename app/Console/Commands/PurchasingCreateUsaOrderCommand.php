<?php

namespace App\Console\Commands;

use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class PurchasingCreateUsaOrderCommand extends Command
{
    protected $signature = 'purchasing:create-usa-order
                            {--include-desired : Incluir también productos que solo tienen cantidad deseada}
                            {--as-ordered : Marcar la orden como Colocada / En camino en vez de Borrador}';

    protected $description = 'Crea una orden de compra consolidada para Compras USA a partir de la lista de suplementos y productos';

    private const BRAND_MAP = [
        'PURITANS PRIDE' => "Puritan's Pride",
        'NATURES BOUNTY' => "Nature's Bounty",
        'NATURE MADE' => 'Nature Made',
        'GOLI NUTRITION' => 'Goli Nutrition',
        'LIFE EXTENSION' => 'Life Extension',
        'NATROL' => 'Natrol',
        'EQUATE' => 'Equate',
        'SIBERIAN GREEN' => 'Siberian Green',
        'KUKAMONGA' => 'Kukamonga',
        'NOW' => 'NOW Foods',
    ];

    public function handle(PurchaseOrderService $poService): int
    {
        $this->info('=====================================================');
        $this->info('  GENERADOR DE ORDEN DE COMPRA CONSOLIDADA - USA     ');
        $this->info('=====================================================');

        $csvPath = base_path('database/data/pedido_usa.csv');
        if (! file_exists($csvPath)) {
            $this->error("No se encontró el archivo {$csvPath}");
            return Command::FAILURE;
        }

        $includeDesired = (bool) $this->option('include-desired');
        $asOrdered = (bool) $this->option('as-ordered');

        // 1. Obtener o crear proveedor 'Compras USA'
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Compras USA'],
            [
                'contact_name' => 'Encargado Compras USA',
                'notes' => 'Proveedor y logística para compras en Estados Unidos (Walmart, Sam\'s, Amazon US, marcas directas)',
                'is_active' => true,
            ]
        );

        // 2. Sincronizar marcas asociadas al proveedor
        $supplierBrands = [
            'Spring Valley',
            'Life Extension',
            "Puritan's Pride",
            'Natrol',
            'Equate',
            'Native',
            'Starface',
            "Nature's Bounty",
            'Goli Nutrition',
            'Siberian Green',
            "Burt's Bees",
            'Nature Made',
            "O'Keeffe's",
            'Zep',
        ];
        $supplier->syncBrands($supplierBrands);

        // 3. Ubicación de inventario
        $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
        if (! $location) {
            $location = InventoryLocation::create([
                'code' => 'BOD-CENTRAL',
                'name' => 'Bodega Central SBS',
                'is_active' => true,
            ]);
        }

        // 4. Usuario comprador
        $buyer = User::where('role', User::ROLE_ADMIN)->first() ?: User::first();
        if (! $buyer) {
            $buyer = User::forceCreate([
                'name' => 'Admin Compras',
                'email' => 'compras@sbs.com',
                'role' => User::ROLE_ADMIN,
            ]);
        }

        // 5. Parsear CSV
        $file = fopen($csvPath, 'r');
        $currentBrand = 'Spring Valley';
        $itemsToOrder = [];
        $skippedCount = 0;

        while (($r = fgetcsv($file)) !== false) {
            if (empty(array_filter($r))) {
                continue;
            }
            if (isset($r[2]) && str_contains(mb_strtolower((string) $r[2]), 'deseada')) {
                continue;
            }

            $name = isset($r[1]) ? trim((string) $r[1]) : '';
            $desiredStr = isset($r[2]) ? trim((string) $r[2]) : '';
            $pedidoStr = isset($r[4]) ? trim((string) $r[4]) : '';

            if ($name === '') {
                continue;
            }

            // Detectar si es un encabezado de sección de marca
            $upperName = mb_strtoupper($name);
            if (isset(self::BRAND_MAP[$upperName])) {
                $currentBrand = self::BRAND_MAP[$upperName];
                continue;
            }

            // Detección de marca por contenido del nombre
            $brand = $currentBrand;
            if (str_contains($upperName, "O'KEEFFE")) {
                $brand = "O'Keeffe's";
            } elseif (str_contains($upperName, 'NATIVE')) {
                $brand = 'Native';
            } elseif (str_contains($upperName, 'STARFACE')) {
                $brand = 'Starface';
            } elseif (str_contains($upperName, "BURT'S BEES") || str_contains($upperName, 'BURTS BEES')) {
                $brand = "Burt's Bees";
            } elseif (str_contains($upperName, 'EUCERIN') || str_contains($upperName, 'AQUAPHOR')) {
                $brand = 'Eucerin';
            } elseif (str_contains($upperName, 'EQUATE')) {
                $brand = 'Equate';
            } elseif (str_contains($upperName, 'SPRING VALLEY')) {
                $brand = 'Spring Valley';
            } elseif (str_contains($upperName, 'ZEP')) {
                $brand = 'Zep';
            }

            $pedidoQty = (is_numeric($pedidoStr) && (int) $pedidoStr > 0) ? (int) $pedidoStr : 0;
            $desiredQty = (is_numeric($desiredStr) && (int) $desiredStr > 0) ? (int) $desiredStr : 0;

            $qtyToOrder = $pedidoQty;
            if ($qtyToOrder <= 0 && $includeDesired && $desiredQty > 0) {
                $qtyToOrder = $desiredQty;
            }

            if ($qtyToOrder > 0) {
                $itemsToOrder[] = [
                    'brand' => $brand,
                    'name' => $name,
                    'quantity_ordered' => $qtyToOrder,
                    'quantity_desired' => $desiredQty,
                ];
            } else {
                $skippedCount++;
            }
        }
        fclose($file);

        if (empty($itemsToOrder)) {
            $this->warn('No se encontraron partidas con cantidad mayor a cero para ordenar.');
            return Command::SUCCESS;
        }

        $this->line("Procesando <comment>" . count($itemsToOrder) . "</comment> partidas para compras en Estados Unidos...");

        // 6. Asegurar existencia de productos en inventory_products y armar partidas
        $validatedItems = [];
        $totalUnits = 0;

        foreach ($itemsToOrder as $entry) {
            $name = $entry['name'];
            $brand = $entry['brand'];
            $qty = $entry['quantity_ordered'];

            // Buscar producto existente por nombre exacto o similar
            $product = InventoryProduct::query()
                ->where('name', $name)
                ->first();

            if (! $product) {
                $cleanSearch = mb_substr($name, 0, 40);
                $product = InventoryProduct::query()
                    ->where(function ($q) use ($brand) {
                        $q->where('brand', $brand)
                            ->orWhere('supplier', 'Compras USA');
                    })
                    ->where('name', 'like', "%{$cleanSearch}%")
                    ->first();
            }

            if (! $product) {
                // Generar SKU único
                $sku = $this->generateUniqueSku($brand, $name);
                $product = InventoryProduct::create([
                    'sku' => $sku,
                    'name' => $name,
                    'brand' => $brand,
                    'supplier' => 'Compras USA',
                    'product_type' => InventoryProduct::SIMPLE,
                    'cost' => 0.00,
                    'is_active' => true,
                    'primary_location_id' => $location->id,
                ]);
            }

            $validatedItems[] = [
                'inventory_product_id' => $product->id,
                'sku' => $product->sku,
                'supplier_sku' => null,
                'product_name' => $product->name,
                'quantity_ordered' => $qty,
                'quantity_received' => 0,
                'unit_cost' => (float) ($product->cost ?? 0),
                'subtotal' => 0.00,
                'status' => PurchaseOrderItem::STATUS_PENDING,
            ];

            $totalUnits += $qty;
        }

        // 7. Crear Orden de Compra en DB
        $order = DB::transaction(function () use ($poService, $supplier, $location, $buyer, $validatedItems, $totalUnits, $asOrdered) {
            $orderNumber = $poService->generateOrderNumber();

            $order = PurchaseOrder::create([
                'order_number' => $orderNumber,
                'supplier_name' => $supplier->name,
                'brand' => 'Suplementos / Compras USA',
                'status' => $asOrdered ? PurchaseOrder::STATUS_ORDERED : PurchaseOrder::STATUS_DRAFT,
                'inventory_location_id' => $location->id,
                'user_id' => $buyer->id,
                'ordered_at' => $asOrdered ? now() : null,
                'total_items_count' => count($validatedItems),
                'total_units_ordered' => $totalUnits,
                'total_units_received' => 0,
                'subtotal' => 0.00,
                'tax_amount' => 0.00,
                'shipping_cost' => 0.00,
                'total_cost' => 0.00,
                'supplier_quote_reference' => 'COMPRAS-USA-' . now()->format('Ymd'),
                'notes' => "Pedido consolidado de pastillas, suplementos y productos de Estados Unidos. Generado automáticamente a partir de lista de reabastecimiento.",
            ]);

            foreach ($validatedItems as $vItem) {
                $order->items()->create($vItem);
            }

            return $order;
        });

        // 8. Generar exportación Excel
        $this->exportOrderToExcel($order, $itemsToOrder);

        $this->newLine();
        $this->info("=====================================================");
        $this->info("✓ ORDEN DE COMPRA CREADA EXITOSAMENTE: {$order->order_number}");
        $this->info("  Proveedor: Compras USA");
        $this->info("  Estado: " . ($asOrdered ? "COLOCADA (En camino)" : "BORRADOR"));
        $this->info("  Total de partidas: " . count($validatedItems));
        $this->info("  Total de piezas pedidas: {$totalUnits} pzas");
        $this->info("  Almacén de destino: {$location->name} ({$location->code})");
        $this->info("=====================================================");
        $this->line("• Ver / Editar en sistema: " . url("/compras/ordenes/{$order->id}"));
        $this->line("• PDF Oficial de la orden: " . url("/compras/ordenes/{$order->id}/pdf"));
        $this->line("• Excel generado en: database/data/orden_compra_{$order->order_number}.xlsx");
        $this->newLine();

        return Command::SUCCESS;
    }

    private function generateUniqueSku(string $brand, string $name): string
    {
        $bClean = preg_replace('/[^A-Za-z0-9]/', '', mb_strtoupper($brand));
        $prefix = mb_substr($bClean, 0, 3) ?: 'USA';

        // Extraer palabras clave de nombre
        $words = preg_split('/[\s\-\/\,\.\(\)\+]+/', mb_strtoupper($name));
        $cleanWords = array_values(array_filter($words, fn ($w) => strlen($w) >= 2 && ! in_array($w, ['DE', 'LA', 'EL', 'Y', 'EN', 'POR', 'CON'], true)));
        $slug = implode('-', array_slice($cleanWords, 0, 3));

        $baseSku = "USA-{$prefix}-{$slug}";
        $candidate = mb_substr($baseSku, 0, 45);

        $seq = 1;
        while (InventoryProduct::where('sku', $candidate)->exists()) {
            $seq++;
            $candidate = mb_substr($baseSku, 0, 40) . "-{$seq}";
        }

        return $candidate;
    }

    private function exportOrderToExcel(PurchaseOrder $order, array $items): void
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Orden de Compra USA');

            // Encabezado
            $sheet->setCellValue('A1', 'T.O. THE BEAUTY SHOP - SALON & BARBER SUPPLY (SBS)');
            $sheet->setCellValue('A2', "ORDEN DE COMPRA CONSOLIDADA: {$order->order_number}");
            $sheet->setCellValue('A3', 'Proveedor: Compras USA | Fecha: ' . now()->format('d/m/Y'));

            // Columnas
            $headers = ['#', 'Marca', 'Producto / Descripción', 'Cantidad Pedida', 'Cantidad Deseada', 'SKU'];
            $colLetter = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue("{$colLetter}5", $h);
                $colLetter++;
            }

            $rowIdx = 6;
            foreach ($items as $idx => $it) {
                $sheet->setCellValue("A{$rowIdx}", $idx + 1);
                $sheet->setCellValue("B{$rowIdx}", $it['brand']);
                $sheet->setCellValue("C{$rowIdx}", $it['name']);
                $sheet->setCellValue("D{$rowIdx}", $it['quantity_ordered']);
                $sheet->setCellValue("E{$rowIdx}", $it['quantity_desired'] ?: '-');
                $sheet->setCellValue("F{$rowIdx}", $it['sku'] ?? '');
                $rowIdx++;
            }

            $writer = new Xlsx($spreadsheet);
            $targetPath = base_path("database/data/orden_compra_{$order->order_number}.xlsx");
            $writer->save($targetPath);
        } catch (Throwable) {
            // Silently ignore if spreadsheet fails to export
        }
    }
}
