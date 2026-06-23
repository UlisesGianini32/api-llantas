<?php

namespace App\Http\Controllers;

use App\Models\Llanta;
use App\Models\ProductoCompuesto;
use App\Services\MeliPublishService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MeliPublishController extends Controller
{
    public function create($id): Response
    {
        $llanta = Llanta::findOrFail($id);

        return Inertia::render('Llantas/Publish', [
            'llanta' => [
                'id' => $llanta->id,
                'sku' => $llanta->sku,
                'marca' => $llanta->marca,
                'medida' => $llanta->medida,
                'descripcion' => $llanta->descripcion,
                'title_familyname' => $llanta->title_familyname,
                'precio_ML' => (float) ($llanta->precio_ML ?? 0),
                'stock' => (int) ($llanta->stock ?? 0),
            ],
        ]);
    }

    // ==========================
    // FORM COMPUESTO
    // ==========================
    public function createCompuesto($id): Response
{
    $compuesto = ProductoCompuesto::with('llanta')->findOrFail($id);

    $base = $compuesto->llanta ?: new Llanta([
        'sku' => $compuesto->sku,
        'marca' => $compuesto->marca ?? '',
        'medida' => $compuesto->medida ?? '',
        'descripcion' => $compuesto->descripcion ?? '',
        'title_familyname' => $compuesto->title_familyname ?? '',
    ]);

    $packQty = $this->inferPackQtyFromSku((string) $compuesto->sku);
    $prefix = $packQty === 4 ? 'JUEGO 4 LLANTAS' : 'PAQUETE 2 LLANTAS';

    $medida = trim((string) ($base->medida ?? ''));
    $marca = trim((string) ($base->marca ?? ''));

    $defaultTitle = trim($prefix . ' ' . $medida . ' ' . $marca);
    $defaultTitle = Str::limit(trim(preg_replace('/\s+/', ' ', $defaultTitle)), 60, '');

    return Inertia::render('ProductosCompuestos/Publish', [
        'compuesto' => [
            'id' => $compuesto->id,
            'sku' => $compuesto->sku,
            'tipo' => $compuesto->tipo,
            'stock' => (int) ($compuesto->stock ?? 0),
            'descripcion' => $compuesto->descripcion,
            'title_familyname' => $compuesto->title_familyname,
            'costo' => (float) ($compuesto->costo ?? 0),
            'precio_ML' => (float) ($compuesto->precio_ML ?? 0),
        ],
        'baseLlanta' => [
            'sku' => $base->sku,
            'marca' => $base->marca,
            'medida' => $base->medida,
            'descripcion' => $base->descripcion,
            'title_familyname' => $base->title_familyname,
        ],
        'packQty' => $packQty,
        'defaultTitle' => $defaultTitle,
    ]);
}

    // ==========================
    // AJAX: sugerir categorías
    // ==========================
    public function suggestCategories(Request $request, MeliPublishService $svc)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $user = auth()->user();

        try {
            $data = $svc->suggestCategories($user, $request->q, 8);

            return response()->json([
                'ok' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ML suggestCategories failed', ['err' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Error consultando categorías en MercadoLibre.',
                'raw' => $e->getMessage(),
            ], 422);
        }
    }

    // ==========================
    // AJAX: meta de categoría
    // ==========================
    public function categoryMeta(Request $request, MeliPublishService $svc)
    {
        $request->validate([
            'category_id' => ['required', 'string', 'max:50'],
        ]);

        $user = auth()->user();

        try {
            $cat = $svc->getCategory($user, (string) $request->category_id);
            $isCatalog = $svc->categoryIsCatalogLike($cat);

            return response()->json([
                'ok' => true,
                'data' => [
                    'category_id' => (string) ($cat['id'] ?? $request->category_id),
                    'name' => (string) ($cat['name'] ?? ''),
                    'is_catalog_category' => $isCatalog,
                    'settings' => $cat['settings'] ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ML categoryMeta failed', [
                'category_id' => $request->category_id,
                'err' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo consultar la categoría.',
                'raw' => $e->getMessage(),
            ], 422);
        }
    }

    // ==========================
    // AJAX: buscar catálogo
    // ==========================
    public function searchCatalog(Request $request, MeliPublishService $svc)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:140'],
            'category_id' => ['nullable', 'string', 'max:50'],
        ]);

        $user = auth()->user();

        try {
            $data = $svc->searchCatalog($user, (string) $request->q, $request->category_id, 10);

            $data = array_map(function ($row) {
                $title = trim((string) ($row['title'] ?? ''));
                $cpid = trim((string) ($row['catalog_product_id'] ?? ''));

                if ($title === '' && $cpid !== '') {
                    $row['title'] = $cpid;
                }

                return $row;
            }, $data);

            return response()->json([
                'ok' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ML searchCatalog failed', ['err' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Error buscando catálogo en MercadoLibre.',
                'raw' => $e->getMessage(),
            ], 422);
        }
    }

    // --------------------------
    // Helpers
    // --------------------------
    private function inferPackQtyFromSku(string $sku): int
    {
        $s = strtoupper(trim($sku));
        if (preg_match('/-\s*4\s*$/', $s)) return 4;
        if (preg_match('/-\s*2\s*$/', $s)) return 2;

        if (Str::contains($s, ['JUEGO4', 'JUEGO 4', 'X4', '4PZ', '4 PZ'])) return 4;
        if (Str::contains($s, ['PAR', 'PAQUETE 2', 'X2', '2PZ', '2 PZ'])) return 2;

        return 2;
    }

    private function parseTireFromText(string $text): array
    {
        $out = [
            'section_width' => null,
            'aspect_ratio' => null,
            'rim_diameter' => null,
            'load_index' => null,
            'speed_rating' => null,
            'model' => null,
        ];

        $t = strtoupper($text);

        if (preg_match('/(\d{3})\s*\/\s*(\d{2})\s*(?:ZR|R)\s*(\d{2}(?:\.\d)?)/i', $t, $m)) {
            $out['section_width'] = (string) $m[1];
            $out['aspect_ratio'] = (string) $m[2];
            $out['rim_diameter'] = (string) $m[3];
        }

        if (preg_match('/\b(\d{2,3}(?:\/\d{2,3})?)\s*([A-Z])\b/', $t, $m)) {
            $out['load_index'] = (string) $m[1];
            $out['speed_rating'] = (string) $m[2];
        }

        if (preg_match_all('/\b[A-Z][A-Z0-9\-]{2,}\b/', $t, $mm)) {
            $candidates = $mm[0] ?? [];
            $ban = ['R', 'ZR', 'XL', 'C', 'LT', 'AT', 'HT', 'MT', 'AS', 'AUTO', 'RADIAL', 'SUV', 'PCR'];

            foreach ($candidates as $cand) {
                if (in_array($cand, $ban, true)) continue;
                if (preg_match('/^R\d+$/', $cand)) continue;
                if (preg_match('/^\d+$/', $cand)) continue;
                if (preg_match('/^\d{2,3}(?:\/\d{2,3})?[A-Z]$/', $cand)) continue;

                $out['model'] = $cand;
                break;
            }
        }

        return $out;
    }

    private function buildAttributesManualFirst(Llanta $llanta, Request $request, array $attrMap = []): array
    {
        $src = trim(
            ($request->input('title', '') ?: '') . ' ' .
            ($llanta->medida ?? '') . ' ' .
            ($llanta->descripcion ?? '') . ' ' .
            ($llanta->title_familyname ?? '')
        );

        $spec = $this->parseTireFromText($src);
        $attrs = [];

        $idWidth = $attrMap['section_width'] ?: 'SECTION_WIDTH';
        $idAspect = $attrMap['aspect_ratio'] ?: 'AUTOMOTIVE_TIRE_ASPECT_RATIO';
        $idRim = $attrMap['rim_diameter'] ?: 'RIM_DIAMETER';
        $idLoad = $attrMap['load_index'] ?: 'LOAD_INDEX';
        $idSpeed = $attrMap['speed_index'] ?: 'SPEED_INDEX';
        $idLine = $attrMap['line'] ?? null;
        $idSidewall = $attrMap['sidewall'] ?? null;
        $idServiceType = $attrMap['service_type'] ?? null;
        $idRunFlat = $attrMap['run_flat'] ?: 'IS_RUN_FLAT';
        $idUtqg = $attrMap['utqg'] ?? null;
        $idTerrainType = $attrMap['terrain_type'] ?? null;
        $idConstruction = $attrMap['construction_type'] ?: 'TIRE_CONSTRUCTION_TYPE';
        $idLoadRange = $attrMap['load_range'] ?? null;
        $idTireQuantity = $attrMap['tire_quantity'] ?: 'TIRES_NUMBER';

        $idGtin = $attrMap['gtin'] ?: 'GTIN';
        $idEmptyGtinReason = $attrMap['empty_gtin_reason'] ?: 'EMPTY_GTIN_REASON';
        $idSellerSku = $attrMap['seller_sku'] ?: 'SELLER_SKU';

        $catalogMode = trim((string) $request->input('catalog_mode', 'search'));
        $wantSeparated = $catalogMode === 'no_catalog';

        $brand = trim((string) ($request->input('brand') ?: ($llanta->marca ?? '')));
        if ($brand !== '') {
            $attrs[] = ['id' => 'BRAND', 'value_name' => Str::limit($brand, 60, '')];
        }

        $modelManual = trim((string) $request->input('model', ''));
        $model = $modelManual !== '' ? $modelManual : (string) ($spec['model'] ?? '');
        $model = trim($model);
        if ($model !== '') {
            $attrs[] = ['id' => 'MODEL', 'value_name' => Str::limit($model, 60, '')];
        }

        $sectionWidth = trim((string) ($request->input('section_width', $spec['section_width'] ?? '')));
        $aspectRatio = trim((string) ($request->input('aspect_ratio', $spec['aspect_ratio'] ?? '')));
        $rimDiameter = trim((string) ($request->input('rim_diameter', $spec['rim_diameter'] ?? '')));
        $loadIndex = trim((string) ($request->input('load_index', $spec['load_index'] ?? '')));
        $speedRating = trim((string) ($request->input('speed_rating', $spec['speed_rating'] ?? '')));

        if ($sectionWidth !== '') $attrs[] = ['id' => $idWidth, 'value_name' => "{$sectionWidth} mm"];
        if ($aspectRatio !== '') $attrs[] = ['id' => $idAspect, 'value_name' => $aspectRatio];
        if ($rimDiameter !== '') $attrs[] = ['id' => $idRim, 'value_name' => "{$rimDiameter} in"];
        if ($loadIndex !== '') $attrs[] = ['id' => $idLoad, 'value_name' => $loadIndex];
        if ($speedRating !== '') $attrs[] = ['id' => $idSpeed, 'value_name' => strtoupper($speedRating)];

        $line = trim((string) $request->input('line', ''));
        if ($idLine && $line !== '') {
            $attrs[] = ['id' => $idLine, 'value_name' => Str::limit($line, 60, '')];
        }

        $sidewall = trim((string) $request->input('sidewall', ''));
        if ($idSidewall && $sidewall !== '') {
            $attrs[] = ['id' => $idSidewall, 'value_name' => Str::limit($sidewall, 60, '')];
        }

        $serviceType = trim((string) $request->input('service_type', ''));
        if ($idServiceType && $serviceType !== '') {
            $attrs[] = ['id' => $idServiceType, 'value_name' => strtoupper($serviceType)];
        }

        if ($idRunFlat && $request->filled('run_flat')) {
            $attrs[] = [
                'id' => $idRunFlat,
                'value_name' => (string) $request->input('run_flat') === '1' ? 'Sí' : 'No',
            ];
        }

        $utqg = trim((string) $request->input('utqg', ''));
        if ($idUtqg && $utqg !== '') {
            $attrs[] = ['id' => $idUtqg, 'value_name' => $utqg];
        }

        $terrainType = trim((string) $request->input('terrain_type', ''));
        if ($idTerrainType && $terrainType !== '') {
            $attrs[] = ['id' => $idTerrainType, 'value_name' => $terrainType];
        }

        $constructionType = trim((string) $request->input('construction_type', ''));
        if ($idConstruction && $constructionType !== '') {
            $attrs[] = ['id' => $idConstruction, 'value_name' => $constructionType];
        }

        $loadRange = trim((string) $request->input('load_range', ''));
        if ($idLoadRange && $loadRange !== '') {
            $attrs[] = ['id' => $idLoadRange, 'value_name' => $loadRange];
        }

        $tireQuantity = trim((string) $request->input('tire_quantity', '1'));
        if ($idTireQuantity && $tireQuantity !== '') {
            $attrs[] = ['id' => $idTireQuantity, 'value_name' => $tireQuantity];
        }

        $gtin = trim((string) $request->input('gtin', ''));
        if (!$wantSeparated && $gtin !== '') {
            $attrs[] = ['id' => $idGtin, 'value_name' => $gtin];
        } else {
            $attrs[] = ['id' => $idEmptyGtinReason, 'value_name' => 'Otra razón'];
        }

        $sellerSku = trim((string) $request->input('seller_sku', $llanta->sku));
        if ($sellerSku !== '') {
            $attrs[] = ['id' => $idSellerSku, 'value_name' => Str::limit($sellerSku, 120, '')];
        }

        $unitsPerPack = (int) ($request->input('tire_quantity', 1));
        if ($unitsPerPack < 1) $unitsPerPack = 1;
        $attrs[] = ['id' => 'UNITS_PER_PACK', 'value_name' => (string) $unitsPerPack];

        $h = (float) ($request->input('package_height_cm', 83));
        $w = (float) ($request->input('package_width_cm', 83));
        $l = (float) ($request->input('package_length_cm', 30));
        $kg = (float) ($request->input('package_weight_kg', 26));

        $h = $h > 0 ? $h : 83;
        $w = $w > 0 ? $w : 83;
        $l = $l > 0 ? $l : 30;
        $kg = $kg > 0 ? $kg : 26;

        $g = (int) round($kg * 1000);

        $attrs[] = ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_name' => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') . ' cm'];
        $attrs[] = ['id' => 'SELLER_PACKAGE_WIDTH', 'value_name' => rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.') . ' cm'];
        $attrs[] = ['id' => 'SELLER_PACKAGE_LENGTH', 'value_name' => rtrim(rtrim(number_format($l, 2, '.', ''), '0'), '.') . ' cm'];
        $attrs[] = ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_name' => $g . ' g'];

        $final = [];
        foreach ($attrs as $a) {
            if (empty($a['id'])) continue;

            $id = (string) $a['id'];
            $val = $a['value_name'] ?? null;
            $valId = $a['value_id'] ?? null;

            if (($val === null || $val === '') && ($valId === null || $valId === '')) {
                continue;
            }

            $final[$id] = $a;
        }

        $aspectIds = [
            'ASPECT_RATIO',
            'AUTOMOTIVE_TIRE_ASPECT_RATIO',
            'VEHICLE_TIRE_ASPECT_RATIO',
            'TIRE_ASPECT_RATIO',
        ];

        foreach ($aspectIds as $aid) {
            if ($aid !== $idAspect) {
                unset($final[$aid]);
            }
        }

        return array_values($final);
    }

    private function buildSaleTerms(Request $request): array
    {
        $type = (string) $request->input('warranty_type', 'seller');
        $timeValue = (int) $request->input('warranty_time_value', 30);
        $timeUnit = (string) $request->input('warranty_time_unit', 'days');

        if ($type === 'none') return [];

        if ($timeValue < 1) $timeValue = 30;
        if (!in_array($timeUnit, ['days', 'months', 'years'], true)) $timeUnit = 'days';

        $typeValueName = match ($type) {
            'factory' => 'Garantía de fábrica',
            'seller' => 'Garantía del vendedor',
            default => 'Garantía del vendedor',
        };

        $unitLabel = match ($timeUnit) {
            'days' => $timeValue === 1 ? 'día' : 'días',
            'months' => $timeValue === 1 ? 'mes' : 'meses',
            'years' => $timeValue === 1 ? 'año' : 'años',
            default => 'días',
        };

        return [
            ['id' => 'WARRANTY_TYPE', 'value_name' => $typeValueName],
            ['id' => 'WARRANTY_TIME', 'value_name' => $timeValue . ' ' . $unitLabel],
        ];
    }

    private function resolveOfficialStoreId(Request $request): ?int
    {
        $mode = (string) $request->input('official_store_mode', 'tobeauty');

        return match ($mode) {
            'marketmax' => (int) (
                config('services.meli.official_store_id_marketmax')
                ?: config('services.meli.official_store_id')
                ?: 0
            ),
            'tobeauty' => (int) (
                config('services.meli.official_store_id_tobeauty')
                ?: config('services.meli.official_store_id')
                ?: 0
            ),
            'none' => null,
            default => (int) (config('services.meli.official_store_id') ?: 0),
        };
    }

    private function isCatalogError(string $msg): bool
    {
        return Str::contains($msg, [
            'missing_catalog_required',
            'item.attribute.missing_catalog_required',
            'catalog_product_id',
            'buy box',
            'body.invalid_fields',
            'The fields [title] are invalid',
            'fields [title] are invalid',
            'item.attribute.invalid_product_identifier',
            'item.attribute.missing_conditional_required',
            'The attributes [GTIN] are required',
        ]);
    }

    // ==========================
    // PUBLICAR LLANTA
    // ==========================
    public function publishLlantaById($id, Request $request, MeliPublishService $svc)
    {
        $user = auth()->user();
        $llanta = Llanta::findOrFail($id);

        $request->validate([
            'category_id' => ['required', 'string', 'max:50'],
            'category_name' => ['nullable', 'string', 'max:200'],

            'catalog_mode' => ['nullable', 'string', 'in:search,no_catalog'],
            'catalog_product_id' => ['nullable', 'string', 'max:60'],

            'title' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],

            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'line' => ['nullable', 'string', 'max:120'],
            'sidewall' => ['nullable', 'string', 'max:120'],
            'service_type' => ['nullable', 'string', 'max:20'],
            'run_flat' => ['nullable', 'in:0,1'],
            'tire_quantity' => ['nullable', 'integer', 'min:1', 'max:20'],

            'section_width' => ['nullable', 'numeric', 'min:50', 'max:500'],
            'aspect_ratio' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'rim_diameter' => ['nullable', 'numeric', 'min:8', 'max:30'],
            'load_index' => ['nullable', 'string', 'max:20'],
            'speed_rating' => ['nullable', 'string', 'max:10'],
            'utqg' => ['nullable', 'string', 'max:50'],
            'load_range' => ['nullable', 'string', 'max:50'],
            'terrain_type' => ['nullable', 'string', 'max:50'],
            'construction_type' => ['nullable', 'string', 'max:50'],

            'package_width_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'package_height_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'package_length_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:99.99'],

            'stock_input' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'gtin' => ['nullable', 'string', 'max:120'],
            'seller_sku' => ['nullable', 'string', 'max:120'],

            'official_store_mode' => ['nullable', 'string', 'in:marketmax,tobeauty,none'],
            'warranty_type' => ['nullable', 'string', 'in:seller,factory,none'],
            'warranty_time_value' => ['nullable', 'integer', 'min:1', 'max:120'],
            'warranty_time_unit' => ['nullable', 'string', 'in:days,months,years'],

            'condition' => ['nullable', 'string', 'in:new,used,not_specified'],
            'listing_type_id' => ['nullable', 'string', 'max:50'],

            'pictures_urls' => ['nullable', 'array', 'max:12'],
            'pictures_urls.*' => ['nullable', 'string', 'max:500'],
            'pictures_files' => ['nullable', 'array', 'max:12'],
            'pictures_files.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $officialStoreId = $this->resolveOfficialStoreId($request);
        $officialStoreMode = (string) $request->input('official_store_mode', 'tobeauty');

        if ($officialStoreMode !== 'none' && !$officialStoreId) {
            return back()->withInput()->with('error', 'Falta configurar la tienda oficial seleccionada en services.php / .env.');
        }

        $categoryId = (string) $request->input('category_id');
        $catalogMode = (string) $request->input('catalog_mode', 'search');
        $catalogProductId = trim((string) $request->input('catalog_product_id', ''));

        if ($catalogMode === 'no_catalog') {
            $catalogProductId = '';
        }

        $listingType = (string) $request->input('listing_type_id', 'gold_special');
        $condition = (string) $request->input('condition', 'new');

        $catAttrs = [];
        $attrMap = [];
        $isCatalogCategory = false;
        $isUserProductSeller = false;

        try {
            $cat = $svc->getCategory($user, $categoryId);
            $isCatalogCategory = $svc->categoryIsCatalogLike($cat);
        } catch (\Throwable $e) {
            Log::warning('ML getCategory failed (catalog detect)', [
                'err' => $e->getMessage(),
                'category' => $categoryId,
            ]);
        }

        try {
            $catAttrs = $svc->getCategoryAttributes($user, $categoryId);
            $attrMap = $svc->tireAttributeIdsFromCategoryAttrs($catAttrs);
        } catch (\Throwable $e) {
            Log::warning('ML getCategoryAttributes failed', [
                'category' => $categoryId,
                'err' => $e->getMessage(),
            ]);
        }

        try {
            $isUserProductSeller = $svc->isUserProductSeller($user);
        } catch (\Throwable $e) {
            Log::warning('ML isUserProductSeller failed', [
                'err' => $e->getMessage(),
            ]);
        }

        if ($isCatalogCategory && $catalogProductId === '' && $catalogMode !== 'no_catalog') {
            return back()->withInput()->with(
                'error',
                "Esta categoría usa catálogo.\n" .
                "Si quieres COMPETIR, selecciona un producto del catálogo.\n" .
                "Si quieres PUBLICAR SEPARADO (tradicional), usa “No encuentro mi opción”."
            );
        }

        $forceCatalog = ($catalogMode !== 'no_catalog') && ($catalogProductId !== '') && $isCatalogCategory;

        $title = trim((string) (
            $request->input('title')
            ?: ($llanta->title_familyname ?: (($llanta->marca ?? 'LLANTA') . ' ' . ($llanta->medida ?? '')))
        ));
        $title = trim(preg_replace('/\s+/', ' ', $title));
        if ($title === '') $title = 'Llanta';
        $title = Str::limit($title, 60, '');

        $familyName = $llanta->title_familyname ?: $title;
        $familyName = trim(preg_replace('/\s+/', ' ', (string) $familyName));
        $familyName = Str::limit($familyName, 60, '');

        $attributes = $this->buildAttributesManualFirst($llanta, $request, $attrMap);
        $saleTerms = $this->buildSaleTerms($request);

        $pictureSources = [];
        $picErrors = [];

        if ($request->hasFile('pictures_files')) {
            foreach ($request->file('pictures_files') as $file) {
                if (!$file || !$file->isValid()) {
                    $picErrors[] = "Archivo inválido.";
                    continue;
                }

                $path = $file->store('llantas', 'public');
                $publicUrl = secure_url('storage/' . $path);
                $pictureSources[] = $publicUrl;
            }
        }

        $urls = array_values(array_filter(array_map('trim', (array) $request->input('pictures_urls', []))));

        foreach ($urls as $url) {
            if ($url === '') continue;
            if (Str::startsWith($url, 'data:image')) { $picErrors[] = "URL base64 no permitida."; continue; }
            if (Str::contains($url, ['google.com', 'gstatic.com', 'googleusercontent.com'])) { $picErrors[] = "URL de Google no permitida: {$url}"; continue; }
            if (!preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url)) { $picErrors[] = "URL no directa (no termina en jpg/png/webp): {$url}"; continue; }

            $pictureSources[] = $url;
        }

        $pictureSources = array_values(array_unique($pictureSources));
        $pictures = array_map(fn ($u) => ['source' => $u], array_slice($pictureSources, 0, 12));

        if ($listingType === 'gold_special' && empty($pictures)) {
            $detail = implode("\n- ", array_slice($picErrors, 0, 5));
            $detail = $detail ? ("\nDetalle:\n- " . $detail) : '';

            return back()->withInput()->with(
                'error',
                "Para listing type GOLD_SPECIAL, MercadoLibre exige imágenes.\n" .
                "Sube mínimo 1 foto (JPG/PNG/WEBP) o pon una URL directa." .
                $detail
            );
        }

        $normalizedCatalogProductId = $catalogProductId !== ''
            ? $svc->normalizeCatalogProductId($user, $catalogProductId)
            : '';

        $stock = (int) $request->input('stock_input', max(1, (int) ($llanta->stock ?? 1)));
        if ($stock < 1) $stock = 1;

        $payload = [
            'category_id' => $categoryId,
            'price' => (float) ($llanta->precio_ML ?? 0),
            'currency_id' => 'MXN',
            'available_quantity' => $stock,
            'buying_mode' => 'buy_it_now',
            'listing_type_id' => $listingType,
            'condition' => $condition,
            'attributes' => $attributes,
            'family_name' => $familyName ?: $title,
            'shipping' => [
                'mode' => 'me2',
                'free_shipping' => true,
            ],
        ];

        if ($officialStoreId) {
            $payload['official_store_id'] = $officialStoreId;
        }

        if (!empty($saleTerms)) {
            $payload['sale_terms'] = $saleTerms;
        }

        $sellerSku = trim((string) $request->input('seller_sku', $llanta->sku));
        if ($sellerSku !== '') {
            $payload['seller_custom_field'] = Str::limit($sellerSku, 250, '');
        }

        if (!empty($pictures)) {
            $payload['pictures'] = $pictures;
        }

        if ($forceCatalog) {
            $payload['catalog_listing'] = true;
            $payload['catalog_product_id'] = $normalizedCatalogProductId;
            unset($payload['title']);
        } else {
            unset($payload['catalog_product_id']);
            unset($payload['catalog_listing']);

            if (!$isUserProductSeller) {
                $payload['title'] = $title;
            } else {
                unset($payload['title']);
            }
        }

        Log::info('ML publish llanta mode', [
            'sku' => $llanta->sku,
            'category_id' => $categoryId,
            'catalog_mode' => $catalogMode,
            'forceCatalog' => $forceCatalog,
            'up_seller' => $isUserProductSeller,
            'catalog_listing' => $payload['catalog_listing'] ?? null,
            'catalog_product_id' => $payload['catalog_product_id'] ?? null,
            'title_sent' => array_key_exists('title', $payload),
            'gtin' => $request->input('gtin'),
        ]);

        try {
            $created = $svc->createItem($user, $payload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            Log::warning('ML createItem failed', [
                'payload' => $payload,
                'error' => $msg,
            ]);

            if ($this->isCatalogError($msg)) {
                return back()->withInput()->with(
                    'error',
                    "MercadoLibre exige catálogo o hay validación de catálogo.\n" .
                    "Si quieres competir: selecciona un catalog_product_id.\n" .
                    "Si quieres separado: cambia a una categoría que permita publicación tradicional.\n\n" .
                    "Error ML: {$msg}"
                );
            }

            return back()->withInput()->with('error', 'Error creando item en MercadoLibre: ' . $msg);
        }

        $newMlm = $created['id'] ?? null;
        if (!$newMlm) {
            return back()->withInput()->with('error', 'MercadoLibre no regresó un MLM al crear el item.');
        }

        $desc = trim((string) $request->input('description', ''));
        if (!$forceCatalog && $desc !== '') {
            try {
                $svc->createDescription($user, $newMlm, $desc);
            } catch (\Throwable $e) {
            }
        }

        try {
            $item = $svc->getItem($user, $newMlm);
            $svc->upsertPublication($user, $llanta->sku, $item);
        } catch (\Throwable $e) {
        }

        return back()->with('success', "Publicado OK: {$newMlm}");
    }

    // ==========================
    // PUBLICAR COMPUESTO
    // ==========================
    public function publishCompuestoById($id, Request $request, MeliPublishService $svc)
    {
        $user = auth()->user();
        $compuesto = ProductoCompuesto::with('llanta')->findOrFail($id);

        $base = $compuesto->llanta ?: new Llanta([
            'sku' => $compuesto->sku,
            'marca' => $compuesto->marca ?? '',
            'medida' => $compuesto->medida ?? '',
            'descripcion' => $compuesto->descripcion ?? '',
            'title_familyname' => $compuesto->title_familyname ?? '',
        ]);

        $packQty = $this->inferPackQtyFromSku((string) $compuesto->sku);

        $request->merge([
            'tire_quantity' => $packQty,
            'seller_sku' => $request->input('seller_sku') ?: $compuesto->sku,
            'brand' => $request->input('brand') ?: ($base->marca ?? $compuesto->marca),
        ]);

        $request->validate([
            'category_id' => ['required', 'string', 'max:50'],
            'category_name' => ['nullable', 'string', 'max:200'],

            'catalog_mode' => ['nullable', 'string', 'in:search,no_catalog'],
            'catalog_product_id' => ['nullable', 'string', 'max:60'],

            'title' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],

            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'line' => ['nullable', 'string', 'max:120'],
            'sidewall' => ['nullable', 'string', 'max:120'],
            'service_type' => ['nullable', 'string', 'max:20'],
            'run_flat' => ['nullable', 'in:0,1'],

            'tire_quantity' => ['required', 'integer', 'in:2,4'],

            'section_width' => ['nullable', 'numeric', 'min:50', 'max:500'],
            'aspect_ratio' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'rim_diameter' => ['nullable', 'numeric', 'min:8', 'max:30'],
            'load_index' => ['nullable', 'string', 'max:20'],
            'speed_rating' => ['nullable', 'string', 'max:10'],
            'utqg' => ['nullable', 'string', 'max:50'],
            'load_range' => ['nullable', 'string', 'max:50'],
            'terrain_type' => ['nullable', 'string', 'max:50'],
            'construction_type' => ['nullable', 'string', 'max:50'],

            'package_width_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'package_height_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'package_length_cm' => ['nullable', 'numeric', 'min:1', 'max:300'],
            'package_weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:99.99'],

            'stock_input' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'gtin' => ['nullable', 'string', 'max:120'],
            'seller_sku' => ['required', 'string', 'max:120'],

            'official_store_mode' => ['nullable', 'string', 'in:marketmax,tobeauty,none'],
            'warranty_type' => ['nullable', 'string', 'in:seller,factory,none'],
            'warranty_time_value' => ['nullable', 'integer', 'min:1', 'max:120'],
            'warranty_time_unit' => ['nullable', 'string', 'in:days,months,years'],

            'condition' => ['nullable', 'string', 'in:new,used,not_specified'],
            'listing_type_id' => ['nullable', 'string', 'max:50'],

            'pictures_urls' => ['nullable', 'array', 'max:12'],
            'pictures_urls.*' => ['nullable', 'string', 'max:500'],
            'pictures_files' => ['nullable', 'array', 'max:12'],
            'pictures_files.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $officialStoreId = $this->resolveOfficialStoreId($request);
        $officialStoreMode = (string) $request->input('official_store_mode', 'tobeauty');

        if ($officialStoreMode !== 'none' && !$officialStoreId) {
            return back()->withInput()->with('error', 'Falta configurar la tienda oficial seleccionada en services.php / .env.');
        }

        $categoryId = (string) $request->input('category_id');
        $catalogMode = (string) $request->input('catalog_mode', 'search');
        $catalogProductId = trim((string) $request->input('catalog_product_id', ''));

        if ($catalogMode === 'no_catalog') {
            $catalogProductId = '';
        }

        $listingType = (string) $request->input('listing_type_id', 'gold_special');
        $condition = (string) $request->input('condition', 'new');

        $catAttrs = [];
        $attrMap = [];
        $isCatalogCategory = false;
        $isUserProductSeller = false;

        try {
            $cat = $svc->getCategory($user, $categoryId);
            $isCatalogCategory = $svc->categoryIsCatalogLike($cat);
        } catch (\Throwable $e) {
        }

        try {
            $catAttrs = $svc->getCategoryAttributes($user, $categoryId);
            $attrMap = $svc->tireAttributeIdsFromCategoryAttrs($catAttrs);
        } catch (\Throwable $e) {
        }

        try {
            $isUserProductSeller = $svc->isUserProductSeller($user);
        } catch (\Throwable $e) {
            Log::warning('ML isUserProductSeller failed', [
                'err' => $e->getMessage(),
            ]);
        }

        if ($isCatalogCategory && $catalogProductId === '' && $catalogMode !== 'no_catalog') {
            return back()->withInput()->with(
                'error',
                "Esta categoría usa catálogo.\n" .
                "Si quieres COMPETIR, selecciona un producto del catálogo.\n" .
                "Si quieres PUBLICAR SEPARADO (tradicional), usa “No encuentro mi opción”."
            );
        }

        $forceCatalog = ($catalogMode !== 'no_catalog') && ($catalogProductId !== '') && $isCatalogCategory;

        $prefix = $packQty === 4 ? 'PACK DE 4 LLANTAS' : 'PACK DE 2 LLANTAS';
        $medida = trim((string) ($base->medida ?? ''));
        $marca = trim((string) ($base->marca ?? ''));

        $title = trim((string) (
            $request->input('title')
            ?: ($compuesto->title_familyname ?: ($prefix . ' ' . $medida . ' ' . $marca))
        ));
        $title = Str::limit(trim(preg_replace('/\s+/', ' ', $title)), 60, '');

        $familyName = $compuesto->title_familyname ?: $title;
        $familyName = Str::limit(trim(preg_replace('/\s+/', ' ', (string) $familyName)), 60, '');

        $attributes = $this->buildAttributesManualFirst($base, $request, $attrMap);
        $saleTerms = $this->buildSaleTerms($request);

        $pictureSources = [];
        $picErrors = [];

        if ($request->hasFile('pictures_files')) {
            foreach ($request->file('pictures_files') as $file) {
                if (!$file || !$file->isValid()) {
                    $picErrors[] = "Archivo inválido.";
                    continue;
                }

                $path = $file->store('llantas', 'public');
                $publicUrl = secure_url('storage/' . $path);
                $pictureSources[] = $publicUrl;
            }
        }

        $urls = array_values(array_filter(array_map('trim', (array) $request->input('pictures_urls', []))));

        foreach ($urls as $url) {
            if ($url === '') continue;
            if (Str::startsWith($url, 'data:image')) { $picErrors[] = "URL base64 no permitida."; continue; }
            if (Str::contains($url, ['google.com', 'gstatic.com', 'googleusercontent.com'])) { $picErrors[] = "URL de Google no permitida: {$url}"; continue; }
            if (!preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url)) { $picErrors[] = "URL no directa (no termina en jpg/png/webp): {$url}"; continue; }

            $pictureSources[] = $url;
        }

        $pictureSources = array_values(array_unique($pictureSources));
        $pictures = array_map(fn ($u) => ['source' => $u], array_slice($pictureSources, 0, 12));

        if ($listingType === 'gold_special' && empty($pictures)) {
            return back()->withInput()->with('error', "Para GOLD_SPECIAL, MercadoLibre exige imágenes.\nSube mínimo 1 foto o pon URL directa.");
        }

        $normalizedCatalogProductId = $catalogProductId !== ''
            ? $svc->normalizeCatalogProductId($user, $catalogProductId)
            : '';

        $stock = max(1, (int) $request->input('stock_input', max(1, (int) ($compuesto->stock ?? 1))));
        $price = (float) ($compuesto->precio_ML ?? $compuesto->precio_ml ?? $compuesto->precio ?? 0);

        $payload = [
            'category_id' => $categoryId,
            'price' => $price,
            'currency_id' => 'MXN',
            'available_quantity' => $stock,
            'buying_mode' => 'buy_it_now',
            'listing_type_id' => $listingType,
            'condition' => $condition,
            'attributes' => $attributes,
            'family_name' => $familyName ?: $title,
            'shipping' => [
                'mode' => 'me2',
                'free_shipping' => true,
            ],
            'seller_custom_field' => Str::limit((string) $compuesto->sku, 250, ''),
        ];

        if ($officialStoreId) {
            $payload['official_store_id'] = $officialStoreId;
        }

        if (!empty($saleTerms)) {
            $payload['sale_terms'] = $saleTerms;
        }

        if (!empty($pictures)) {
            $payload['pictures'] = $pictures;
        }

        if ($forceCatalog) {
            $payload['catalog_listing'] = true;
            $payload['catalog_product_id'] = $normalizedCatalogProductId;
            unset($payload['title']);
        } else {
            unset($payload['catalog_product_id']);
            unset($payload['catalog_listing']);

            if (!$isUserProductSeller) {
                $payload['title'] = $title;
            } else {
                unset($payload['title']);
            }
        }

        Log::info('ML publish compuesto mode', [
            'sku' => $compuesto->sku,
            'category_id' => $categoryId,
            'catalog_mode' => $catalogMode,
            'forceCatalog' => $forceCatalog,
            'up_seller' => $isUserProductSeller,
            'catalog_listing' => $payload['catalog_listing'] ?? null,
            'catalog_product_id' => $payload['catalog_product_id'] ?? null,
            'title_sent' => array_key_exists('title', $payload),
            'gtin' => $request->input('gtin'),
        ]);

        try {
            $created = $svc->createItem($user, $payload);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if ($this->isCatalogError($msg)) {
                return back()->withInput()->with(
                    'error',
                    "MercadoLibre exige catálogo o hay validación de catálogo.\n" .
                    "Si quieres competir: selecciona un catalog_product_id.\n" .
                    "Si quieres separado: cambia a una categoría que permita publicación tradicional.\n\n" .
                    "Error ML: {$msg}"
                );
            }

            return back()->withInput()->with('error', 'Error creando item en MercadoLibre: ' . $msg);
        }

        $newMlm = $created['id'] ?? null;
        if (!$newMlm) {
            return back()->withInput()->with('error', 'MercadoLibre no regresó un MLM al crear el item.');
        }

        $desc = trim((string) $request->input('description', ''));
        if (!$forceCatalog && $desc !== '') {
            try {
                $svc->createDescription($user, $newMlm, $desc);
            } catch (\Throwable $e) {
            }
        }

        try {
            $item = $svc->getItem($user, $newMlm);
            $svc->upsertPublication($user, (string) $compuesto->sku, $item);
        } catch (\Throwable $e) {
        }

        return back()->with('success', "Publicado OK: {$newMlm}");
    }
}