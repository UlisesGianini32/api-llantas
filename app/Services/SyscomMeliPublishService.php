<?php

namespace App\Services;

use App\Models\MeliPublication;
use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Models\User;
use App\Support\SyscomHermosilloStock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyscomMeliPublishService
{
    public function __construct(
        private MeliPublishService $meli,
        private SyscomProductPricingService $pricing,
        private SyscomImageNormalizerService $imageNormalizer,
        private SyscomMeliCategoryGuardService $categoryGuard,
        private SyscomMeliCategoryResolverService $categoryResolver
    ) {}

    public function makeSku(int $syscomProductoId): string
    {
        $prefix = (string) config('syscom.sku_prefix', 'SYSCOM');

        return $prefix.'-'.$syscomProductoId;
    }

    public function resolveOfficialStoreId(string $mode): ?int
    {
        $id = match ($mode) {
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
            'none' => 0,
            default => (int) (config('services.meli.official_store_id') ?: 0),
        };

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{mlm: string, sku: string, item: array}
     */
    public function publish(
        User $user,
        SyscomProduct $product,
        string $hermosilloBranchCode,
        ?string $categoryId = null,
        string $officialStoreMode = 'marketmax',
        string $priceScope = 'llanta',
        ?string $manualUniversalCode = null
    ): array {
        if ($hermosilloBranchCode === '') {
            throw new \RuntimeException('Código de sucursal Hermosillo no resuelto. Ejecuta la sincronización SYSCOM.');
        }

        $categoryManual = $categoryId !== null && trim((string) $categoryId) !== '';
        $manualCategoryInput = $categoryManual ? trim((string) $categoryId) : null;

        if ($manualCategoryInput !== null) {
            $this->assertManualCategoryIdIsNotListingMlm($manualCategoryInput);
        }

        if (! $categoryManual) {
            $categoryId = $this->resolveMeliCategoryId($user, $product);
        } else {
            $categoryId = trim((string) $categoryId);
        }

        // Una categoría capturada manualmente nunca debe ser reemplazada por heurísticas.
        // Las correcciones automáticas solo se aplican cuando el panel dejó la categoría vacía.
        //
        // Además, una equivalencia SYSCOM -> ML aprobada tiene prioridad y
        // tampoco debe ser modificada por heurísticas históricas.
        $approvedProductOverride = $this->hasApprovedProductOverride(
            $product,
            (string) $categoryId
        );

        $approvedSyscomMapping = $this->hasApprovedSyscomMapping(
            $product,
            (string) $categoryId
        );

        if (
            ! $categoryManual
            && ! $approvedProductOverride
            && ! $approvedSyscomMapping
        ) {
            $categoryId = $this->applyAutoCategoryFixes(
                $product,
                $categoryId
            );
        }

        $this->assertMeliCategoryPlausibleForProduct($user, $product, $categoryId, $categoryManual);

        // Segunda barrera independiente. También valida categorías capturadas manualmente
        // y bloquea contradicciones fuertes antes de crear el item en Mercado Libre.
        $categoryDiagnostic = $this->categoryGuard->validate(
            $user,
            $product,
            (string) $categoryId,
            $categoryManual
        );

        Log::info('SyscomMeliPublish: categoría ML final para publicación', [
            'category_id' => $categoryId,
            'syscom_producto_id' => $product->syscom_producto_id,
            'titulo' => trim((string) ($product->titulo ?? '')),
            'manual' => $categoryManual,
            'category_guard' => $categoryDiagnostic,
        ]);

        $stock = (int) ($product->stock_hermosillo ?? 0);
        $price = $this->pricing->priceFor($product, $priceScope);

        if ($price <= 0) {
            throw new \RuntimeException('El precio calculado es 0. Revisa costos en SYSCOM o las fórmulas (conjunto Syscom).');
        }

        $title = $this->buildPublishTitle($product);

        $sku = $this->makeSku((int) $product->syscom_producto_id);

        $pictures = $this->buildPicturePayload($product, $user);
        if ($pictures === []) {
            throw new \RuntimeException(
                'Mercado Libre no recibió fotos válidas (mín. 500×500 px). Revisa URLs en SYSCOM (.jpg/.png/.webp), que el normalizador esté activo (SYSCOM_IMAGE_NORMALIZER_ENABLED) y los logs si falla la descarga, GD o el upload a ML.'
            );
        }

        $officialStoreId = $this->resolveOfficialStoreId($officialStoreMode);
        if ($officialStoreMode !== 'none' && ! $officialStoreId) {
            throw new \RuntimeException('Falta tienda oficial en .env (MELI_OFFICIAL_STORE_ID_*) o elige "Sin tienda oficial".');
        }

        $catAttrsAuth = $this->meli->getCategoryAttributes($user, $categoryId);
        $catAttrsPublic = $this->meli->getCategoryAttributesPublic($categoryId);
        $catAttrs = $this->meli->mergeCategoryAttributes($catAttrsAuth, $catAttrsPublic);
        $isUserProductSeller = $this->meli->isUserProductSeller($user);
        $attributes = $this->buildGenericAttributes($catAttrs, $product, $sku, $isUserProductSeller);
        $attributes = $this->ensureAllRequiredAttributesPresent(
            $attributes,
            $catAttrs,
            $product,
            $sku,
            $isUserProductSeller
        );
        $attributes = $this->ensureProductIdentifierConditional(
            $attributes,
            $catAttrs,
            $product,
            $sku,
            $isUserProductSeller,
            $categoryId,
            $manualUniversalCode
        );

        $payload = [
            'category_id' => $categoryId,
            'price' => round($price, 2),
            'currency_id' => 'MXN',
            'available_quantity' => max(0, $stock),
            'buying_mode' => 'buy_it_now',
            'listing_type_id' => 'gold_special',
            'condition' => 'new',
            'attributes' => $attributes,
            // Publicación tradicional: NO competir en catálogo de ML.
            'catalog_listing' => false,
            'shipping' => [
                'mode' => 'me2',
                'free_shipping' => true,
            ],
            'pictures' => $pictures,
            'sale_terms' => [
                ['id' => 'WARRANTY_TYPE', 'value_name' => 'Garantía del vendedor'],
                ['id' => 'WARRANTY_TIME', 'value_name' => '30 días'],
            ],
        ];

        $pauseAtOrBelow = (int) config('services.meli.pause_syscom_when_stock_at_or_below', 0);
        if ($pauseAtOrBelow >= 0 && $stock <= $pauseAtOrBelow) {
            $payload['status'] = 'paused';
            if ($stock < 1) {
                $payload['available_quantity'] = 1;
            }
        }

        $videoId = $this->extractFirstVideoIdForMeli($product);
        if ($videoId !== null && $videoId !== '') {
            $payload['video_id'] = $videoId;
        }

        if ($officialStoreId) {
            $payload['official_store_id'] = $officialStoreId;
        }

        if ($isUserProductSeller) {
            // ML rechaza `title` cuando la cuenta es user_product seller (toma el título del
            // atributo NAME), pero exige `family_name` para identificar la familia/producto.
            $payload['family_name'] = $title;
            $this->ensureNameAttribute($payload, $title);
        } else {
            $payload['title'] = $title;
        }

        // Solo mandamos empaque de envío cuando la categoría realmente lo exige.
        // Evita warnings "attribute ignored because it is not modifiable".
        $this->ensurePackageDimensions($payload, $product, $catAttrs);

        $payload['seller_custom_field'] = Str::limit($sku, 250, '');

        try {
            $created = $this->meli->createItem($user, $payload);
        } catch (\RuntimeException $e) {
            if ($this->isMeliPictureSizeError($e)) {
                throw new \RuntimeException(
                    'Mercado Libre rechazó las fotos (tamaño mínimo por lado). Suele pasar con imágenes muy '.
                    'anchas/altas o miniaturas: re-sincronizá el producto en SYSCOM, activá el normalizador '.
                    '(SYSCOM_IMAGE_NORMALIZER_ENABLED=true), subí fotos más cuadradas o probá '.
                    'SYSCOM_IMAGE_MAX_ASPECT_BEFORE_CROP=2. Detalle: '.$e->getMessage()
                );
            }
            if ($this->isMeliAttributeNumberFormatError($e) || $this->isMeliAttributeNormalizableUnitError($e)) {
                Log::warning('SyscomMeliPublish: retry createItem por atributo numérico/unidad inválida', [
                    'category' => $categoryId,
                    'sku' => $sku,
                    'err' => $e->getMessage(),
                ]);
                $payload['attributes'] = $this->sanitizeNumericAttributesForMeli(
                    is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [],
                    $catAttrs
                );
                try {
                    $created = $this->meli->createItem($user, $payload);
                } catch (\RuntimeException $eNum) {
                    throw new \RuntimeException(
                        'Mercado Libre rechazó un atributo con número o unidad inválida (ej. potencia: "1 W", no solo "1"). '.
                        'Revisá características SYSCOM o pegá category_id manual. Detalle: '.$eNum->getMessage()
                    );
                }
            } elseif ($this->isMissingRequiredAttributesError($e)) {
                $missingIds = $this->extractMissingRequiredAttributeIds($e, $catAttrs);
                $extras = $this->buildAttributesForMissingIds(
                    $missingIds,
                    $catAttrs,
                    $product,
                    $sku,
                    $isUserProductSeller
                );
                if ($extras !== []) {
                    $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                    $payload['attributes'] = $this->dedupeAttributes(array_merge($attrs, $extras));

                    Log::warning('SyscomMeliPublish: retry createItem por atributos obligatorios faltantes', [
                        'category' => $categoryId,
                        'sku' => $sku,
                        'missing' => $missingIds,
                        'added' => array_column($extras, 'id'),
                    ]);

                    try {
                        $created = $this->meli->createItem($user, $payload);
                    } catch (\RuntimeException $eReq) {
                        throw new \RuntimeException(
                            $this->formatMissingRequiredAttributesMessage($eReq, $categoryId)
                        );
                    }
                } else {
                    throw new \RuntimeException(
                        $this->formatMissingRequiredAttributesMessage($e, $categoryId)
                    );
                }
            } elseif ($this->isMissingConditionalGtinError($e)) {
                $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                $attrs = $this->dedupeAttributes(array_merge($attrs, [
                    $this->fallbackEmptyGtinReasonPayload((string) $categoryId),
                ]));
                $payload['attributes'] = $attrs;

                Log::warning('SyscomMeliPublish: retry createItem por GTIN condicional faltante', [
                    'category' => $categoryId,
                    'sku' => $sku,
                    'attrs_count' => count($attrs),
                ]);

                try {
                    $created = $this->meli->createItem($user, $payload);
                } catch (\RuntimeException $e2) {
                    if ($this->isMissingConditionalGtinError($e2)) {
                        throw new \RuntimeException(
                            'Esta categoría exige GTIN (código universal). Captura el campo "Código universal (GTIN/EAN/UPC)" y vuelve a publicar.'
                        );
                    }
                    throw $e2;
                }
            } else {
                throw $e;
            }
        }

        $mlm = $created['id'] ?? null;
        if (! $mlm) {
            throw new \RuntimeException('Mercado Libre no devolvió id al crear el ítem.');
        }

        $desc = $this->plainDescription($product);
        if ($desc !== '') {
            try {
                $this->meli->createDescription($user, (string) $mlm, Str::limit($desc, 5000, ''));
            } catch (\Throwable $e) {
                Log::warning('ML createDescription syscom', [
                    'err' => $e->getMessage(),
                    'mlm' => $mlm,
                ]);
            }
        }

        $item = $this->meli->getItem($user, (string) $mlm);
        $this->meli->upsertPublication($user, $sku, $item);

        return [
            'mlm' => (string) $mlm,
            'sku' => $sku,
            'item' => $item,
        ];
    }

    public function updateStockAndPriceFromProduct(
        User $user,
        string $mlm,
        SyscomProduct $product,
        string $priceScope = 'llanta',
        ?SyscomMeliQueue $queue = null
    ): void {
        $this->syncPublishedItemFromProduct($user, $product, $mlm, $priceScope, $queue);
    }

    /**
     * PUT precio + stock en ML para un ítem ya publicado (respeta precio MANUAL en cola).
     *
     * @return array{mlm: string, price: float, stock: int, sku: string}
     */
    public function syncPublishedItemFromProduct(
        User $user,
        SyscomProduct $product,
        string $mlm,
        ?string $priceScope = null,
        ?SyscomMeliQueue $queue = null
    ): array {
        $mlm = trim($mlm);
        if ($mlm === '') {
            throw new \RuntimeException('Sin MLM; no se puede sincronizar con Mercado Libre.');
        }

        $scope = (string) ($priceScope ?? $queue?->price_scope ?? 'llanta');
        $price = $this->pricing->priceFor($product, $scope, $queue);
        $stock = max(0, (int) ($product->stock_hermosillo ?? 0));
        if ($price <= 0) {
            throw new \RuntimeException('Precio calculado es 0; revisá costos SYSCOM o fórmulas antes de subir a ML.');
        }

        $sku = $this->makeSku((int) $product->syscom_producto_id);
        $priceRounded = round($price, 2);

        $item = $this->meli->getItem($user, $mlm);
        $this->meli->upsertPublication($user, $sku, $item);

        $mlStatus = strtolower(trim((string) ($item['status'] ?? '')));
        if (! MeliPublication::permiteActualizarPrecioStock($mlStatus)) {
            $etiqueta = MeliPublication::etiquetaEstadoPublicacion($mlStatus) ?? strtoupper($mlStatus);

            throw new \RuntimeException(
                "La publicación {$mlm} está {$etiqueta} en Mercado Libre y no acepta cambios de precio ni stock. "
                .'Usá «Republicar» en esta fila para crear una publicación nueva con el precio actual.'
            );
        }

        $payload = [
            'available_quantity' => $stock,
            'price' => $priceRounded,
        ];
        $pauseAtOrBelow = (int) config('services.meli.pause_syscom_when_stock_at_or_below', 0);
        if ($pauseAtOrBelow >= 0 && $stock <= $pauseAtOrBelow) {
            $payload['status'] = 'paused';
        } elseif ($mlStatus === 'paused' && $stock > 0) {
            $payload['status'] = 'active';
        }

        try {
            $this->meli->updateItem($user, $mlm, $payload);
            $item = $this->meli->getItem($user, $mlm);
            $this->meli->upsertPublication($user, $sku, $item);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(MeliPublishService::friendlyMlErrorMessage($e->getMessage()), 0, $e);
        }

        if ($queue !== null) {
            $queueUpdates = [
                'last_price_synced_at' => now(),
                'last_stock_synced_at' => now(),
            ];
            if ($queue->status === 'pending_price') {
                $queueUpdates['status'] = 'published';
            }
            $queue->update($queueUpdates);
        }

        return [
            'mlm' => $mlm,
            'price' => $priceRounded,
            'stock' => $stock,
            'sku' => $sku,
        ];
    }

    /**
     * Garantiza que el payload incluya los 4 atributos de packaging del seller con valores
     * por defecto razonables. ML pyme los exige aunque la categoría no los declare required.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ensurePackageDimensions(array &$payload, ?SyscomProduct $product = null, array $catAttrs = []): void
    {
        if (! $this->categoryRequiresSellerPackageDimensions($catAttrs)) {
            return;
        }

        $detected = $this->extractPhysicalMeasures($product);
        if ($this->sellerPackageMeasuresIncomplete($detected) && $this->looksLikeTireProduct($product)) {
            foreach ($this->fallbackTirePackageMeasures() as $k => $v) {
                if (! isset($detected[$k]) || $detected[$k] === '' || $detected[$k] === null) {
                    $detected[$k] = $v;
                }
            }
        }

        $height = $detected['height'] ?? '25 cm';
        $width = $detected['width'] ?? '25 cm';
        $length = $detected['length'] ?? '20 cm';
        $weight = $detected['weight'] ?? '2000 g';

        $defaults = [
            'SELLER_PACKAGE_HEIGHT' => $height,
            'SELLER_PACKAGE_WIDTH' => $width,
            'SELLER_PACKAGE_LENGTH' => $length,
            'SELLER_PACKAGE_WEIGHT' => $weight,
        ];

        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        $present = [];
        foreach ($attrs as $a) {
            if (! is_array($a)) {
                continue;
            }
            $present[strtoupper((string) ($a['id'] ?? ''))] = true;
        }

        foreach ($defaults as $id => $value) {
            if (isset($present[$id])) {
                continue;
            }
            $attrs[] = ['id' => $id, 'value_name' => $value];
        }

        $payload['attributes'] = $attrs;
    }

    /**
     * @param  array<int, array<string,mixed>>  $catAttrs
     */
    private function categoryRequiresSellerPackageDimensions(array $catAttrs): bool
    {
        $requiredIds = [
            'SELLER_PACKAGE_HEIGHT',
            'SELLER_PACKAGE_WIDTH',
            'SELLER_PACKAGE_LENGTH',
            'SELLER_PACKAGE_WEIGHT',
        ];

        foreach ($catAttrs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if (! in_array($id, $requiredIds, true)) {
                continue;
            }
            if ($this->isAttributeRequired($attr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Garantiza que el atributo NAME exista en el payload con el título dado. ML lo usa como
     * "título visible" cuando el seller es user_product y no se manda `title` en el body.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ensureNameAttribute(array &$payload, string $title): void
    {
        $title = Str::limit(trim($title), 60, '');
        if ($title === '') {
            return;
        }

        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $found = false;
        foreach ($attrs as &$attr) {
            if (! is_array($attr)) {
                continue;
            }
            if (strtoupper((string) ($attr['id'] ?? '')) === 'NAME') {
                $attr['value_name'] = $title;
                unset($attr['value_id']);
                $found = true;
                break;
            }
        }
        unset($attr);

        if (! $found) {
            $attrs[] = ['id' => 'NAME', 'value_name' => $title];
        }

        $payload['attributes'] = $attrs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $catAttrs
     * @return array<int, array<string, mixed>>
     */
    public function buildGenericAttributes(array $catAttrs, SyscomProduct $product, string $sku, bool $isUserProductSeller): array
    {
        $out = [];
        $seen = [];
        $facts = $this->extractCharacteristicsFacts($product);

        foreach ($catAttrs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            if ($this->isReadOnlyAttribute($attr)) {
                continue;
            }

            if (! $this->isAttributeRequired($attr)) {
                continue;
            }

            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $filled = $this->fillAttribute($attr, $product, $sku, $isUserProductSeller, $facts, true);
            if ($filled === null) {
                continue;
            }

            $out[] = $filled;
            $seen[$id] = true;
        }

        // Segunda pasada: atributos no requeridos (secundarios), SOLO si se pueden rellenar
        // desde características reales del producto; no usamos defaults ciegos aquí.
        foreach ($catAttrs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            if ($this->isReadOnlyAttribute($attr)) {
                continue;
            }

            if ($this->isAttributeRequired($attr)) {
                continue;
            }

            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $filled = $this->fillAttribute($attr, $product, $sku, $isUserProductSeller, $facts, false);
            if ($filled === null) {
                continue;
            }

            $out[] = $filled;
            $seen[$id] = true;
        }

        if (! isset($seen['SELLER_SKU']) && ! $isUserProductSeller) {
            $out[] = ['id' => 'SELLER_SKU', 'value_name' => Str::limit($sku, 120, '')];
        }

        return $this->dedupeAttributes($out);
    }

    /**
     * Segunda pasada: ML rechaza si falta un required aunque la primera pasada no pudo inferirlo.
     *
     * @param  array<int, array<string, mixed>>  $attributes
     * @param  array<int, array<string, mixed>>  $catAttrs
     * @return array<int, array<string, mixed>>
     */
    private function ensureAllRequiredAttributesPresent(
        array $attributes,
        array $catAttrs,
        SyscomProduct $product,
        string $sku,
        bool $isUserProductSeller
    ): array {
        $present = [];
        foreach ($attributes as $a) {
            if (! is_array($a)) {
                continue;
            }
            $aid = strtoupper(trim((string) ($a['id'] ?? '')));
            if ($aid !== '') {
                $present[$aid] = true;
            }
        }

        $facts = $this->extractCharacteristicsFacts($product);
        $extras = [];

        foreach ($catAttrs as $attr) {
            if (! is_array($attr) || $this->isReadOnlyAttribute($attr) || ! $this->isAttributeRequired($attr)) {
                continue;
            }

            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($id === '' || isset($present[$id])) {
                continue;
            }

            $filled = $this->fillAttribute($attr, $product, $sku, $isUserProductSeller, $facts, true);
            if ($filled === null) {
                $filled = $this->fillRequiredAttributeFallback($attr, $product, $facts);
            }
            if ($filled !== null) {
                $extras[] = $filled;
                $present[$id] = true;
            }
        }

        return $extras === [] ? $attributes : $this->dedupeAttributes(array_merge($attributes, $extras));
    }

    /**
     * @param  array<string, string>  $facts  extractCharacteristicsFacts()['by_key'] or full facts array
     * @return array<string, mixed>|null
     */
    private function fillRequiredAttributeFallback(array $attr, SyscomProduct $product, array $facts = []): ?array
    {
        $id = strtoupper(trim((string) ($attr['id'] ?? '')));
        if ($id === '') {
            return null;
        }

        /*
         * GTIN/EAN/UPC nunca deben recibir un fallback textual genérico.
         *
         * Si SYSCOM no trae un código universal válido, dejamos el
         * identificador vacío para que ensureProductIdentifierConditional()
         * utilice EMPTY_GTIN_REASON / EMPTY_EAN_REASON.
         *
         * Enviar "No especificado" como GTIN provoca:
         * item.attribute.product_identifier.invalid_format
         */
        if (in_array($id, ['GTIN', 'EAN', 'UPC'], true)) {
            return null;
        }

        $factsPack = isset($facts['by_key']) ? $facts : ['by_key' => [], 'lines' => []];

        if ($id === 'FIBERS_NUMBER') {
            return $this->fillFibersNumberAttribute($attr, $product, $factsPack);
        }

        $values = is_array($attr['values'] ?? null) ? $attr['values'] : [];
        $type = (string) ($attr['value_type'] ?? '');

        if ($values !== []) {
            $pick = $this->pickValueIdByName($values, []);
            if ($pick !== null) {
                return ['id' => $id, 'value_id' => $pick['id'], 'value_name' => $pick['name']];
            }
        }

        if (in_array($type, ['number', 'number_unit'], true)) {
            $n = '1';
            $formatted = $this->formatAttributeValueForMeli($attr, $n);
            if ($formatted === null && $type === 'number_unit') {
                $formatted = $this->formatBareNumberWithDefaultUnit($attr, $n);
            }

            return ['id' => $id, 'value_name' => $formatted ?? $n];
        }

        if ($type === 'boolean') {
            return ['id' => $id, 'value_name' => 'No'];
        }

        return ['id' => $id, 'value_name' => 'No especificado'];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    private function fillFibersNumberAttribute(array $attr, SyscomProduct $product, array $facts): ?array
    {
        $id = 'FIBERS_NUMBER';
        $fromFacts = $this->fillAttributeFromFacts($attr, $facts);
        if ($fromFacts !== null) {
            return $fromFacts;
        }

        $blob = mb_strtolower(implode(' ', array_filter([
            (string) ($product->titulo ?? ''),
            (string) ($product->descripcion ?? ''),
            (string) ($product->modelo ?? ''),
        ])));

        $n = '1';
        if (preg_match('/(\d+)\s*(?:fibras?|hilos?|cores?|núcleos?|nucleos?|hebras?)/u', $blob, $m)) {
            $n = (string) $m[1];
        } elseif (preg_match('/(?:fibras?|hilos?)\s*[:x×]?\s*(\d+)/u', $blob, $m)) {
            $n = (string) $m[1];
        }

        $values = is_array($attr['values'] ?? null) ? $attr['values'] : [];
        $pick = $this->pickValueIdByName($values, [$n, $n.' fibras', $n.' fiber']);
        if ($pick !== null) {
            return ['id' => $id, 'value_id' => $pick['id'], 'value_name' => $pick['name']];
        }

        foreach ($values as $v) {
            if (! is_array($v)) {
                continue;
            }
            $name = (string) ($v['name'] ?? '');
            if ($name !== '' && preg_match('/\b'.preg_quote($n, '/').'\b/u', $name)) {
                $vid = (string) ($v['id'] ?? '');
                if ($vid !== '') {
                    return ['id' => $id, 'value_id' => $vid, 'value_name' => $name];
                }
            }
        }

        if ($values !== []) {
            $first = $values[0];
            if (is_array($first)) {
                $vid = (string) ($first['id'] ?? '');
                $vname = (string) ($first['name'] ?? '');
                if ($vid !== '' && $vname !== '') {
                    return ['id' => $id, 'value_id' => $vid, 'value_name' => $vname];
                }
            }
        }

        return ['id' => $id, 'value_name' => $n];
    }

    /**
     * @param  array<int, string>  $missingIds
     * @param  array<int, array<string, mixed>>  $catAttrs
     * @return array<int, array<string, mixed>>
     */
    private function buildAttributesForMissingIds(
        array $missingIds,
        array $catAttrs,
        SyscomProduct $product,
        string $sku,
        bool $isUserProductSeller
    ): array {
        if ($missingIds === []) {
            return [];
        }

        $facts = $this->extractCharacteristicsFacts($product);
        $byId = [];
        foreach ($catAttrs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $aid = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($aid !== '') {
                $byId[$aid] = $attr;
            }
        }

        $out = [];
        foreach ($missingIds as $mid) {
            $attr = $byId[$mid] ?? null;
            if ($attr === null) {
                continue;
            }
            $filled = $this->fillAttribute($attr, $product, $sku, $isUserProductSeller, $facts, true);
            if ($filled === null) {
                $filled = $this->fillRequiredAttributeFallback($attr, $product, $facts);
            }
            if ($filled !== null) {
                $out[] = $filled;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $catAttrs
     * @return array<int, string>
     */
    private function extractMissingRequiredAttributeIds(\RuntimeException $e, array $catAttrs): array
    {
        $jsonPart = preg_replace('/^ML_ERROR:\d+:/', '', $e->getMessage()) ?? $e->getMessage();
        $data = json_decode($jsonPart, true);
        if (! is_array($data)) {
            return [];
        }

        $ids = [];
        $causes = is_array($data['cause'] ?? null) ? $data['cause'] : [];
        foreach ($causes as $c) {
            if (! is_array($c)) {
                continue;
            }
            $code = (string) ($c['code'] ?? '');
            if (! in_array($code, [
                'item.attributes.missing_required',
                'item.attribute.missing_catalog_required',
                'item.attributes.normalizable.invalid',
            ], true)) {
                continue;
            }

            $msg = (string) ($c['message'] ?? '');
            if (preg_match('/Attribute\s+([A-Z0-9_]+)\s+with/i', $msg, $mAttr)) {
                $aid = strtoupper(trim($mAttr[1]));
                if ($aid !== '') {
                    $ids[] = $aid;
                }
            }
            if (preg_match('/\[([A-Z0-9_,\s]+)\]/', $msg, $m)) {
                foreach (explode(',', $m[1]) as $part) {
                    $id = strtoupper(trim($part));
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }

            if (preg_match('/El campo\s+"([^"]+)"/u', $msg, $mName)) {
                $label = mb_strtolower(trim($mName[1]));
                foreach ($catAttrs as $attr) {
                    if (! is_array($attr)) {
                        continue;
                    }
                    $aname = mb_strtolower(trim((string) ($attr['name'] ?? '')));
                    if ($aname !== '' && ($aname === $label || str_contains($aname, $label) || str_contains($label, $aname))) {
                        $aid = strtoupper(trim((string) ($attr['id'] ?? '')));
                        if ($aid !== '') {
                            $ids[] = $aid;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isMissingRequiredAttributesError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'item.attributes.missing_required')
            || str_contains($m, 'item.attribute.missing_catalog_required');
    }

    private function formatMissingRequiredAttributesMessage(\RuntimeException $e, string $categoryId): string
    {
        $jsonPart = preg_replace('/^ML_ERROR:\d+:/', '', $e->getMessage()) ?? $e->getMessage();
        $data = json_decode($jsonPart, true);
        $labels = [];
        if (is_array($data)) {
            foreach (is_array($data['cause'] ?? null) ? $data['cause'] : [] as $c) {
                if (! is_array($c)) {
                    continue;
                }
                if (! in_array((string) ($c['code'] ?? ''), ['item.attributes.missing_required', 'item.attribute.missing_catalog_required'], true)) {
                    continue;
                }
                $msg = trim((string) ($c['message'] ?? ''));
                if ($msg !== '') {
                    $labels[] = $msg;
                }
            }
        }

        $detail = $labels !== [] ? implode(' ', array_slice($labels, 0, 2)) : $e->getMessage();

        return 'Mercado Libre exige datos obligatorios de la categoría '.$categoryId.' que no se pudieron inferir desde SYSCOM. '
            .'Revisá que la categoría ML sea la correcta (campo category_id) o completá características en SYSCOM. Detalle: '.$detail;
    }

    /**
     * Refuerzo del par condicional GTIN / EMPTY_GTIN_REASON (cause ML 7810): si la respuesta
     * autenticada omitió filas hidden, el merge público suele bastar; esto cubre el caso residual.
     *
     * @param  array<int, array<string, mixed>>  $attrs
     * @param  array<int, array<string, mixed>>  $catAttrs
     * @return array<int, array<string, mixed>>
     */
    private function ensureProductIdentifierConditional(
        array $attrs,
        array $catAttrs,
        SyscomProduct $product,
        string $sku,
        bool $isUserProductSeller,
        string $categoryId,
        ?string $manualUniversalCode = null
    ): array {
        $present = [];
        foreach ($attrs as $a) {
            if (! is_array($a)) {
                continue;
            }
            $aid = strtoupper((string) ($a['id'] ?? ''));
            if ($aid !== '') {
                $present[$aid] = true;
            }
        }

        $hasCode = ! empty($present['GTIN']) || ! empty($present['EAN']) || ! empty($present['UPC']);
        $hasEmpty = ! empty($present['EMPTY_GTIN_REASON']) || ! empty($present['EMPTY_EAN_REASON']);
        $manualCode = $this->normalizeBarcodeDigits((string) $manualUniversalCode);

        // Si el usuario capturó código universal manual, debe ganar sobre "motivo vacío".
        if ($manualCode !== null && $manualCode !== '') {
            return $this->dedupeAttributes(array_merge($attrs, [
                ['id' => 'GTIN', 'value_name' => $manualCode],
            ]));
        }

        if ($hasCode || $hasEmpty) {
            return $attrs;
        }

        $code = $this->extractBarcodeDigitsFromSyscomProduct($product);
        if ($code !== null && $code !== '') {
            return $this->dedupeAttributes(array_merge($attrs, [
                ['id' => 'GTIN', 'value_name' => $code],
            ]));
        }

        foreach ($catAttrs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            if (strtoupper((string) ($attr['id'] ?? '')) !== 'EMPTY_GTIN_REASON') {
                continue;
            }
            $filled = $this->fillAttribute($attr, $product, $sku, $isUserProductSeller, [], true);
            if ($filled !== null) {
                return $this->dedupeAttributes(array_merge($attrs, [$filled]));
            }

            break;
        }

        return $this->dedupeAttributes(array_merge($attrs, [
            $this->fallbackEmptyGtinReasonPayload($categoryId),
        ]));
    }

    /**
     * value_id de "Otra razón" donde la API lo fija por sitio (merge público suele traer la lista).
     *
     * @return array{id: string, value_id?: string, value_name: string}
     */
    private function fallbackEmptyGtinReasonPayload(string $categoryId): array
    {
        $prefix = strtoupper(substr($categoryId, 0, 3));
        if ($prefix === 'MLM') {
            return [
                'id' => 'EMPTY_GTIN_REASON',
                'value_id' => '17055161',
                'value_name' => 'Otra razón',
            ];
        }

        return ['id' => 'EMPTY_GTIN_REASON', 'value_name' => 'Otra razón'];
    }

    /**
     * domain_discovery: varias consultas (título, marca, modelo, descripción, jerarquía SYSCOM) y
     * votación por puntuación para no quedarse con la primera sugerencia cuando suele ser categoría incorrecta.
     */
    private function resolveMeliCategoryId(User $user, SyscomProduct $product): string
    {
        $queries = $this->buildMeliDomainDiscoveryQueries($product);
        $scores = [];
        $names = [];

        [$title, $blob] = $this->productClassificationContext($product);
        $brand = trim((string) ($product->marca ?? ''));
        $model = trim((string) ($product->modelo ?? ''));
        $descPlain = $this->plainTextDescription($product);
        $catLine = $this->flattenSyscomCategoriesLine($product);
        $tireProduct = $this->productLooksLikeTire($title, $model, $blob);
        $sensorOrAccessHints = $this->blobSuggestsSecurityOrVehicularAccess($blob);
        $dashCamProduct = $this->productLooksLikeDashCam($blob, $title);
        $upsProduct = $this->productLooksLikeUps($blob, $title);

        if ($upsProduct) {
            $upsCategoryId = $this->configuredUpsCategoryId();

            Log::info('SyscomMeliPublish: categoría ML fija por familia UPS', [
                'category_id' => $upsCategoryId,
                'syscom_producto_id' => $product->syscom_producto_id,
                'titulo' => $title,
            ]);

            return $upsCategoryId;
        }

        $dvrProduct = ! $dashCamProduct && $this->productLooksLikeNvrDvr($blob, $title);
        $cameraProduct = ! $dashCamProduct && $this->productLooksLikeSurveillanceCamera($blob, $title);
        $videoporteroProduct = $this->productLooksLikeVideoIntercom($blob, $title);
        $videoSurveillanceProduct = $dvrProduct
            || $cameraProduct
            || $this->productBlobSuggestsVideovigilancia($product, $blob);
        $isKitProduct = $this->productLooksLikeKit($blob, $title);
        $networkingProduct = $this->productLooksLikeNetworkingEquipment($blob, $title);
        $switchProduct = $this->productLooksLikeSwitch($blob, $title);
        $oltProduct = $this->productLooksLikeOlt($blob, $title);
        $antennaProduct = $this->productLooksLikeWirelessAntenna($blob, $title);
        $routerProduct = $this->productLooksLikeRouterOnly($blob, $title);
        $poeInjectorProduct = $this->productLooksLikePoeInjector($blob, $title);
        $cellPhoneProduct = $this->productLooksLikeCellPhone($blob, $title);
        $solarPanelProduct = $this->productLooksLikeSolarPanel($blob, $title);
        $solarMeterProduct = $this->productLooksLikeSolarEnergyMeter($blob, $title);
        $solarMountProduct = ! $solarPanelProduct && ! $solarMeterProduct && $this->productLooksLikeSolarMount($blob, $title);
        $handToolKitProduct = $this->productLooksLikeHandToolKit($blob, $title);
        $flashlightProduct = $this->productLooksLikeFlashlight($blob, $title);
        $balunProduct = $this->productLooksLikeAudioVideoBalun($blob, $title);
        $alarmAccessoryProduct = $this->productLooksLikeHomeAlarmAccessory($blob, $title);
        $powerSupplyProduct = $this->productLooksLikePowerSupply($blob, $title);
        $pduProduct = $this->productLooksLikePdu($blob, $title);
        $solarCableProduct = $this->productLooksLikeSolarOrControllerCable($blob, $title);
        $connectorProduct = $this->productLooksLikeElectricalConnector($blob, $title);
        $standaloneCameraProduct = $this->productIsStandaloneSurveillanceCamera(
            $blob,
            $title,
            $product,
            $dvrProduct,
            $isKitProduct
        );
        // Fase 4: clasifica la familia antes de domain_discovery y busca únicamente
        // categorías compatibles. Las categorías fijas sólo se usan cuando fueron verificadas.
        $familyResolution = $this->categoryResolver->resolve($user, $product);
        if (is_array($familyResolution) && trim((string) ($familyResolution['category_id'] ?? '')) !== '') {
            Log::info('SyscomMeliPublish: categoría ML resuelta por familia', [
                'phase' => 4,
                'syscom_producto_id' => $product->syscom_producto_id,
                'titulo' => $title,
                'resolution' => $familyResolution,
            ]);

            return (string) $familyResolution['category_id'];
        }

        $configuredBalunCategory = $this->configuredBalunCategoryId();
        $configuredSwitchCategory = $this->configuredSwitchCategoryId();
        $configuredOltCategory = $this->configuredOltCategoryId();
        $configuredAntennaCategory = $this->configuredAntennaCategoryId();

        $configuredDashCamCategory = $this->configuredDashCamCategoryId();
        if ($dashCamProduct && $configuredDashCamCategory !== '') {
            Log::info('SyscomMeliPublish: categoría Dash Cam fija por SYSCOM_MELI_DASH_CAM_CATEGORY_ID', [
                'category_id' => $configuredDashCamCategory,
                'titulo' => $title,
            ]);

            return $configuredDashCamCategory;
        }

        $configuredPoeInjectorCategory = $this->configuredPoeInjectorCategoryId();
        if ($poeInjectorProduct && $configuredPoeInjectorCategory !== '') {
            Log::info('SyscomMeliPublish: categoría inyector PoE fija por SYSCOM_MELI_POE_INJECTOR_CATEGORY_ID', [
                'category_id' => $configuredPoeInjectorCategory,
                'titulo' => $title,
            ]);

            return $configuredPoeInjectorCategory;
        }

        $configuredAlarmCategory = $this->configuredAlarmCategoryId();
        if ($alarmAccessoryProduct && $configuredAlarmCategory !== '') {
            Log::info('SyscomMeliPublish: categoría alarma/intrusión fija por SYSCOM_MELI_ALARM_CATEGORY_ID', [
                'category_id' => $configuredAlarmCategory,
                'titulo' => $title,
            ]);

            return $configuredAlarmCategory;
        }

        $configuredRouterCategory = $this->configuredRouterCategoryId();
        if ($routerProduct && $configuredRouterCategory !== '') {
            Log::info('SyscomMeliPublish: categoría router fija por SYSCOM_MELI_ROUTER_CATEGORY_ID', [
                'category_id' => $configuredRouterCategory,
                'titulo' => $title,
            ]);

            return $configuredRouterCategory;
        }

        $configuredToolKitCategory = $this->configuredToolKitCategoryId();
        if ($handToolKitProduct && $configuredToolKitCategory !== '') {
            Log::info('SyscomMeliPublish: categoría kit/bolsa herramientas fija por SYSCOM_MELI_TOOL_KIT_CATEGORY_ID', [
                'category_id' => $configuredToolKitCategory,
                'titulo' => $title,
            ]);

            return $configuredToolKitCategory;
        }

        $configuredPowerSupplyCategory = $this->configuredPowerSupplyCategoryId();
        if ($powerSupplyProduct && $configuredPowerSupplyCategory !== '') {
            Log::info('SyscomMeliPublish: categoría fuente de poder fija por SYSCOM_MELI_POWER_SUPPLY_CATEGORY_ID', [
                'category_id' => $configuredPowerSupplyCategory,
                'titulo' => $title,
            ]);

            return $configuredPowerSupplyCategory;
        }

        $configuredPduCategory = $this->configuredPduCategoryId();
        if ($pduProduct && $configuredPduCategory !== '') {
            Log::info('SyscomMeliPublish: categoría PDU fija por SYSCOM_MELI_PDU_CATEGORY_ID', [
                'category_id' => $configuredPduCategory,
                'titulo' => $title,
            ]);

            return $configuredPduCategory;
        }

        $configuredConnectorCategory = $this->configuredConnectorCategoryId();
        if ($connectorProduct && $configuredConnectorCategory !== '') {
            Log::info('SyscomMeliPublish: categoría conector eléctrico fija por SYSCOM_MELI_CONNECTOR_CATEGORY_ID', [
                'category_id' => $configuredConnectorCategory,
                'titulo' => $title,
            ]);

            return $configuredConnectorCategory;
        }

        // Switch antes que DVR/cámara: PoE para cámaras no es grabador ni cámara.
        if ($switchProduct && $configuredSwitchCategory !== '') {
            Log::info('SyscomMeliPublish: categoría Switch fija por SYSCOM_MELI_SWITCH_CATEGORY_ID', [
                'category_id' => $configuredSwitchCategory,
                'titulo' => $title,
            ]);

            return $configuredSwitchCategory;
        }

        $configuredDvrCategory = trim((string) config('syscom.meli_dvr_category_id', ''));
        if ($dvrProduct && ! $isKitProduct && ! $standaloneCameraProduct && ! $solarMountProduct && $configuredDvrCategory !== '') {
            Log::info('SyscomMeliPublish: categoría DVR fija por SYSCOM_MELI_DVR_CATEGORY_ID', [
                'category_id' => $configuredDvrCategory,
                'titulo' => $title,
            ]);

            return $configuredDvrCategory;
        }

        // OLT GPON/EPON (central FTTH): ML no tiene categoría propia; evita Routers/Modems.
        if ($oltProduct && $configuredOltCategory !== '') {
            Log::info('SyscomMeliPublish: categoría OLT fija por SYSCOM_MELI_OLT_CATEGORY_ID', [
                'category_id' => $configuredOltCategory,
                'titulo' => $title,
            ]);

            return $configuredOltCategory;
        }

        if ($antennaProduct && $configuredAntennaCategory !== '') {
            Log::info('SyscomMeliPublish: categoría antena inalámbrica fija por SYSCOM_MELI_ANTENNA_CATEGORY_ID', [
                'category_id' => $configuredAntennaCategory,
                'titulo' => $title,
            ]);

            return $configuredAntennaCategory;
        }

        $surveillanceKitProduct = $isKitProduct && $videoSurveillanceProduct && ! $switchProduct && ! $routerProduct && ! $balunProduct;

        if ($balunProduct && $configuredBalunCategory !== '') {
            Log::info('SyscomMeliPublish: categoría balun A/V fija por SYSCOM_MELI_BALUN_CATEGORY_ID', [
                'category_id' => $configuredBalunCategory,
                'titulo' => $title,
            ]);

            return $configuredBalunCategory;
        }

        $configuredKitVideoCategory = $this->configuredKitVideoCategoryId();
        if ($surveillanceKitProduct && $configuredKitVideoCategory !== '') {
            Log::info('SyscomMeliPublish: categoría Kit videovigilancia fija por SYSCOM_MELI_KIT_VIDEO_CATEGORY_ID', [
                'category_id' => $configuredKitVideoCategory,
                'titulo' => $title,
            ]);

            return $configuredKitVideoCategory;
        }

        $configuredFlashlightCategory = $this->configuredFlashlightCategoryId();
        if ($flashlightProduct && $configuredFlashlightCategory !== '') {
            Log::info('SyscomMeliPublish: categoría linterna fija por SYSCOM_MELI_FLASHLIGHT_CATEGORY_ID', [
                'category_id' => $configuredFlashlightCategory,
                'titulo' => $title,
            ]);

            return $configuredFlashlightCategory;
        }

        $configuredSolarMeterCategory = $this->configuredSolarMeterCategoryId();
        if ($solarMeterProduct && $configuredSolarMeterCategory !== '') {
            Log::info('SyscomMeliPublish: categoría medidor solar fija por SYSCOM_MELI_SOLAR_METER_CATEGORY_ID', [
                'category_id' => $configuredSolarMeterCategory,
                'titulo' => $title,
            ]);

            return $configuredSolarMeterCategory;
        }

        $configuredSolarMountCategory = $this->configuredSolarMountCategoryId();
        if ($solarMountProduct && $configuredSolarMountCategory !== '') {
            Log::info('SyscomMeliPublish: categoría montaje solar fija por SYSCOM_MELI_SOLAR_MOUNT_CATEGORY_ID', [
                'category_id' => $configuredSolarMountCategory,
                'titulo' => $title,
            ]);

            return $configuredSolarMountCategory;
        }

        $configuredSolarCableCategory = $this->configuredSolarCableCategoryId();
        if ($solarCableProduct && $configuredSolarCableCategory !== '') {
            Log::info('SyscomMeliPublish: categoría cable solar/controlador fija por SYSCOM_MELI_SOLAR_CABLE_CATEGORY_ID', [
                'category_id' => $configuredSolarCableCategory,
                'titulo' => $title,
            ]);

            return $configuredSolarCableCategory;
        }

        $configuredVideoporteroCategory = $this->configuredVideoporteroCategoryId();
        if ($videoporteroProduct && $configuredVideoporteroCategory !== '') {
            Log::info('SyscomMeliPublish: categoría videoportero fija por SYSCOM_MELI_VIDEOPORTERO_CATEGORY_ID', [
                'category_id' => $configuredVideoporteroCategory,
                'titulo' => $title,
            ]);

            return $configuredVideoporteroCategory;
        }

        $configuredCameraCategory = $this->configuredCameraCategoryId();
        if ($standaloneCameraProduct && $configuredCameraCategory !== '') {
            Log::info('SyscomMeliPublish: categoría cámara fija por SYSCOM_MELI_CAMERA_CATEGORY_ID', [
                'category_id' => $configuredCameraCategory,
                'titulo' => $title,
            ]);

            return $configuredCameraCategory;
        }

        if ($videoSurveillanceProduct) {
            $fromSyscom = $this->resolveMeliCategoryIdFromSyscomHierarchy(
                $user,
                $product,
                $catLine,
                $dvrProduct
            );
            if ($fromSyscom !== null) {
                return $fromSyscom;
            }
        }

        foreach ($queries as $q) {
            $q = trim($q);
            if ($q === '') {
                continue;
            }
            try {
                $suggestions = $this->meli->suggestCategories($user, $q, 8);
            } catch (\Throwable $e) {
                Log::warning('SyscomMeliPublish: suggestCategories excepción', [
                    'q' => $q,
                    'err' => $e->getMessage(),
                ]);

                continue;
            }
            foreach ($suggestions as $idx => $sug) {
                if (! is_array($sug)) {
                    continue;
                }
                $id = (string) ($sug['id'] ?? '');
                $name = (string) ($sug['name'] ?? '');
                if ($id === '') {
                    continue;
                }
                $base = max(1, 9 - (int) $idx);
                $scores[$id] = ($scores[$id] ?? 0) + $base;
                if (! isset($names[$id]) || $names[$id] === '') {
                    $names[$id] = $name !== '' ? $name : 'Categoría ML';
                }

                $nameLower = mb_strtolower($name);
                $scores[$id] += $this->meliCategoryKeywordOverlapScore($blob, $nameLower);
                if ($catLine !== '') {
                    $scores[$id] += (int) round($this->meliCategoryKeywordOverlapScore(mb_strtolower($catLine), $nameLower) * 1.5);
                }

                if ($sensorOrAccessHints && ! $tireProduct && $this->categoryNameLooksLikeTire($nameLower)) {
                    $scores[$id] -= 35;
                }
                if ($sensorOrAccessHints && ! $tireProduct && ! $videoSurveillanceProduct && $this->categoryNameLooksLikeAccessOrSecurity($nameLower)) {
                    $scores[$id] += 14;
                }
                if ($videoSurveillanceProduct && $this->categoryNameLooksLikeVehicleCatalog($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($videoSurveillanceProduct && $this->categoryNameLooksLikeVehicleTurboMisleading($nameLower)) {
                    $scores[$id] -= 60;
                }
                if ($standaloneCameraProduct && $configuredCameraCategory !== '' && $id === $configuredCameraCategory) {
                    $scores[$id] += 45;
                }
                if ($dvrProduct && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)) {
                    $scores[$id] += 32;
                }
                if ($dvrProduct && $this->categoryNameLooksLikeCamera($nameLower) && ! $this->categoryNameLooksLikeNvrDvrOnly($nameLower)) {
                    $scores[$id] -= 18;
                }
                if ($tireProduct && $this->categoryNameLooksLikeElectronicsNotTire($nameLower)) {
                    $scores[$id] -= 22;
                }
                if ($cameraProduct && ! $isKitProduct && $this->categoryNameLooksLikeKit($nameLower)) {
                    $scores[$id] -= 28;
                }
                if ($surveillanceKitProduct && $this->categoryNameLooksLikeSurveillanceKitCategory($nameLower)) {
                    $scores[$id] += 40;
                }
                if ($surveillanceKitProduct && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    && ! $this->categoryNameLooksLikeKit($nameLower)) {
                    $scores[$id] -= 35;
                }
                if ($videoSurveillanceProduct && ! $switchProduct && $this->categoryNameLooksLikeCamera($nameLower)) {
                    $scores[$id] += 18;
                }
                if ($cameraProduct && ! $dvrProduct && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)) {
                    $scores[$id] -= 20;
                }
                if ($networkingProduct && ! $antennaProduct && $this->categoryNameLooksLikeCellPhone($nameLower)) {
                    $scores[$id] -= 48;
                }
                if ($networkingProduct && ! $antennaProduct && $this->categoryNameLooksLikeNetworking($nameLower)) {
                    $scores[$id] += 24;
                }
                if ($antennaProduct && $configuredAntennaCategory !== '' && $id === $configuredAntennaCategory) {
                    $scores[$id] += 48;
                }
                if ($antennaProduct && $this->categoryNameLooksLikeAntennaCategory($nameLower)) {
                    $scores[$id] += 42;
                }
                if ($antennaProduct && $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($antennaProduct && $this->categoryNameLooksLikeSwitchCategory($nameLower)) {
                    $scores[$id] -= 45;
                }
                if ($antennaProduct && $this->categoryNameLooksLikeModemCategory($nameLower)) {
                    $scores[$id] -= 40;
                }
                if ($switchProduct && $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] -= 45;
                }
                if ($switchProduct && $this->categoryNameLooksLikeUsbHubCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($switchProduct && $configuredSwitchCategory !== '' && $id === $configuredSwitchCategory) {
                    $scores[$id] += 42;
                }
                if ($switchProduct && $this->categoryNameLooksLikeSwitchCategory($nameLower)) {
                    $scores[$id] += 38;
                }
                if ($switchProduct && $this->categoryNameLooksLikeCamera($nameLower)
                    && ! $this->categoryNameLooksLikeSwitchCategory($nameLower)) {
                    $scores[$id] -= 60;
                }
                if ($switchProduct && $this->categoryNameLooksLikeSurveillanceKitCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($routerProduct && ! $antennaProduct && $this->categoryNameLooksLikeSwitchCategory($nameLower)
                    && ! $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] -= 32;
                }
                if ($routerProduct && ! $antennaProduct && $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] += 34;
                }
                if (! $cellPhoneProduct && ! $networkingProduct && $this->categoryNameLooksLikeCellPhone($nameLower)) {
                    $scores[$id] -= 18;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeSolarPanelCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeVehicleSunroofRiel($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($solarMountProduct && $configuredSolarMountCategory !== '' && $id === $configuredSolarMountCategory) {
                    $scores[$id] += 42;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeSolarMountFriendlyCategory($nameLower)) {
                    $scores[$id] += 28;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeSoftwareCategory($nameLower)) {
                    $scores[$id] -= 60;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeCameraMountCategory($nameLower)) {
                    $scores[$id] -= 45;
                }
                $configuredSolarMeterCategory = $this->configuredSolarMeterCategoryId();
                if ($solarMeterProduct && $this->categoryNameLooksLikeSolarPanelCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($solarMeterProduct && $configuredSolarMeterCategory !== '' && $id === $configuredSolarMeterCategory) {
                    $scores[$id] += 45;
                }
                if ($solarMeterProduct && $this->categoryNameLooksLikeSolarMeterFriendlyCategory($nameLower)) {
                    $scores[$id] += 32;
                }
                if ($balunProduct && $this->categoryNameLooksLikeSoftwareCategory($nameLower)) {
                    $scores[$id] -= 60;
                }
                if ($balunProduct && $configuredBalunCategory !== '' && $id === $configuredBalunCategory) {
                    $scores[$id] += 45;
                }
                if ($balunProduct && $this->categoryNameLooksLikeAvConverterCategory($nameLower)) {
                    $scores[$id] += 32;
                }
                if ($balunProduct && $this->categoryNameLooksLikeSurveillanceKitCategory($nameLower)
                    && ! $this->categoryNameLooksLikeAvConverterCategory($nameLower)) {
                    $scores[$id] -= 65;
                }
                if ($oltProduct && $configuredOltCategory !== '' && $id === $configuredOltCategory) {
                    $scores[$id] += 48;
                }
                if ($oltProduct && $this->categoryNameLooksLikeOltFriendlyCategory($nameLower)) {
                    $scores[$id] += 34;
                }
                if ($oltProduct && $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($oltProduct && $this->categoryNameLooksLikeModemCategory($nameLower)) {
                    $scores[$id] -= 50;
                }
                if ($oltProduct && $this->categoryNameLooksLikeSwitchCategory($nameLower)
                    && ! $this->categoryNameLooksLikeOltFriendlyCategory($nameLower)) {
                    $scores[$id] -= 35;
                }
                $configuredToolKitCategory = $this->configuredToolKitCategoryId();
                if ($handToolKitProduct && $this->categoryNameLooksLikeHuntingOrSportMisleadingCategory($nameLower)) {
                    $scores[$id] -= 60;
                }
                if ($handToolKitProduct && $this->categoryNameLooksLikeToyToolSetMisleadingCategory($nameLower)) {
                    $scores[$id] -= 50;
                }
                if ($handToolKitProduct && $configuredToolKitCategory !== '' && $id === $configuredToolKitCategory) {
                    $scores[$id] += 48;
                }
                if ($handToolKitProduct && $this->categoryNameLooksLikeHandToolKitFriendlyCategory($nameLower)) {
                    $scores[$id] += 35;
                }
                if ($handToolKitProduct && $this->categoryNameLooksLikeCamera($nameLower)
                    && ! $this->categoryNameLooksLikeHandToolKitFriendlyCategory($nameLower)) {
                    $scores[$id] -= 65;
                }
                $configuredFlashlightCategory = $this->configuredFlashlightCategoryId();
                if ($flashlightProduct && $this->categoryNameLooksLikeSoftwareCategory($nameLower)) {
                    $scores[$id] -= 60;
                }
                if ($flashlightProduct && $configuredFlashlightCategory !== '' && $id === $configuredFlashlightCategory) {
                    $scores[$id] += 45;
                }
                if ($flashlightProduct && $this->categoryNameLooksLikeFlashlightFriendlyCategory($nameLower)) {
                    $scores[$id] += 35;
                }
                if ($alarmAccessoryProduct && $this->categoryNameLooksLikeThermostatCategory($nameLower)) {
                    $scores[$id] -= 75;
                }
                if ($alarmAccessoryProduct && $this->categoryNameLooksLikeBarcodeScannerCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($alarmAccessoryProduct && $configuredAlarmCategory !== '' && $id === $configuredAlarmCategory) {
                    $scores[$id] += 50;
                }
                if ($alarmAccessoryProduct && $this->categoryNameLooksLikeHomeAlarmCategory($nameLower)) {
                    $scores[$id] += 40;
                }
                if ($powerSupplyProduct && $this->categoryNameLooksLikeCamera($nameLower)) {
                    $scores[$id] -= 70;
                }
                if ($powerSupplyProduct && $configuredPowerSupplyCategory !== '' && $id === $configuredPowerSupplyCategory) {
                    $scores[$id] += 50;
                }
                if ($powerSupplyProduct && $this->categoryNameLooksLikePowerSupplyCategory($nameLower)) {
                    $scores[$id] += 40;
                }
                $configuredRouterCategory = $this->configuredRouterCategoryId();
                if ($routerProduct && $this->categoryNameLooksLikePowerSupplyCategory($nameLower)
                    && ! $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] -= 70;
                }
                if ($routerProduct && $configuredRouterCategory !== '' && $id === $configuredRouterCategory) {
                    $scores[$id] += 50;
                }
                if ($routerProduct && $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    $scores[$id] += 42;
                }
                if ($pduProduct && $this->categoryNameLooksLikeUpsCategory($nameLower)) {
                    $scores[$id] -= 75;
                }
                if ($pduProduct && $configuredPduCategory !== '' && $id === $configuredPduCategory) {
                    $scores[$id] += 50;
                }
                if ($pduProduct && $this->categoryNameLooksLikePduFriendlyCategory($nameLower)) {
                    $scores[$id] += 40;
                }
                if ($standaloneCameraProduct && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    && ! $this->categoryNameLooksLikeKit($nameLower)) {
                    $scores[$id] -= 65;
                }
                if ($standaloneCameraProduct && $configuredCameraCategory !== '' && $id === $configuredCameraCategory) {
                    $scores[$id] += 50;
                }
                if ($standaloneCameraProduct && $this->categoryNameLooksLikeCamera($nameLower)) {
                    $scores[$id] += 35;
                }
                $configuredVideoporteroCategory = $this->configuredVideoporteroCategoryId();
                if ($videoporteroProduct && $this->categoryNameLooksLikeCamera($nameLower)
                    && ! $this->categoryNameLooksLikeVideoIntercomCategory($nameLower)) {
                    $scores[$id] -= 70;
                }
                if ($videoporteroProduct && $configuredVideoporteroCategory !== '' && $id === $configuredVideoporteroCategory) {
                    $scores[$id] += 50;
                }
                if ($videoporteroProduct && $this->categoryNameLooksLikeVideoIntercomCategory($nameLower)) {
                    $scores[$id] += 42;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)) {
                    $scores[$id] -= 65;
                }
                if ($solarMountProduct && $this->categoryNameLooksLikeCamera($nameLower)
                    && ! $this->categoryNameLooksLikeSolarMountFriendlyCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($solarCableProduct && $this->categoryNameLooksLikeTabletChargerCategory($nameLower)) {
                    $scores[$id] -= 70;
                }
                if ($solarCableProduct && $configuredSolarCableCategory !== '' && $id === $configuredSolarCableCategory) {
                    $scores[$id] += 50;
                }
                if ($solarCableProduct && $this->categoryNameLooksLikeSolarCableCategory($nameLower)) {
                    $scores[$id] += 40;
                }
                $configuredConnectorCategory = $this->configuredConnectorCategoryId();
                if ($connectorProduct && $this->categoryNameLooksLikeRadioFrequencyCategory($nameLower)) {
                    $scores[$id] -= 70;
                }
                if ($connectorProduct && $this->categoryNameLooksLikeCellPhone($nameLower)
                    && ! $this->categoryNameLooksLikeElectricalConnectorCategory($nameLower)) {
                    $scores[$id] -= 55;
                }
                if ($connectorProduct && $configuredConnectorCategory !== '' && $id === $configuredConnectorCategory) {
                    $scores[$id] += 50;
                }
                if ($connectorProduct && $this->categoryNameLooksLikeElectricalConnectorCategory($nameLower)) {
                    $scores[$id] += 42;
                }
            }
        }

        if ($scores !== []) {
            arsort($scores, SORT_NUMERIC);
            $bestId = $this->pickBestScoredMeliCategoryId(
                $scores,
                $names,
                $dvrProduct,
                $videoSurveillanceProduct,
                $switchProduct,
                $routerProduct,
                $surveillanceKitProduct,
                $solarMountProduct,
                $oltProduct,
                $handToolKitProduct,
                $standaloneCameraProduct,
                $antennaProduct
            );
            if (is_string($bestId) && $bestId !== '') {
                $rankedIds = array_keys($scores);
                $bestScore = (int) ($scores[$bestId] ?? PHP_INT_MIN);
                $secondScore = PHP_INT_MIN;

                foreach ($rankedIds as $candidateId) {
                    if ((string) $candidateId === $bestId) {
                        continue;
                    }
                    $secondScore = (int) ($scores[$candidateId] ?? PHP_INT_MIN);
                    break;
                }

                $minimumScore = max(1, (int) config('syscom.meli_category_min_score', 18));
                $minimumGap = max(0, (int) config('syscom.meli_category_min_gap', 6));
                $gap = $secondScore === PHP_INT_MIN ? PHP_INT_MAX : $bestScore - $secondScore;

                if ($bestScore < $minimumScore || $gap < $minimumGap) {
                    $top = [];
                    foreach (array_slice($scores, 0, 3, true) as $id => $score) {
                        $top[] = $id.' ('.($names[$id] ?? 'Categoría ML').', puntaje '.$score.')';
                    }

                    throw new \RuntimeException(
                        'No se publicó porque Mercado Libre devolvió categorías ambiguas o con poca confianza. '.
                        'Captura manualmente una categoría final MLM en el campo «Categoría ML». '.
                        'Mejores coincidencias: '.implode('; ', $top).'.'
                    );
                }

                Log::info('SyscomMeliPublish: categoría ML por votación domain_discovery', [
                    'category_id' => $bestId,
                    'category_name' => $names[$bestId] ?? '',
                    'score' => $bestScore,
                    'gap' => $gap,
                    'top' => array_slice($scores, 0, 5, true),
                ]);

                return $bestId;
            }
        }

        $fallback = trim((string) config('syscom.meli_fallback_category_id', ''));
        $allowFallback = (bool) config('syscom.meli_allow_fallback_category', false);
        if ($allowFallback && $fallback !== '') {
            Log::info('SyscomMeliPublish: categoría por SYSCOM_MELI_FALLBACK_CATEGORY_ID', [
                'category_id' => $fallback,
            ]);

            return $fallback;
        }

        throw new \RuntimeException(
            'Mercado Libre no sugirió categoría (domain_discovery vacío o error). '.
            'En la tabla, campo “Categoría ML”, pegá el category_id de México (empieza con MLM; lo ves al publicar manual en ML o en la URL del listado de categorías). '.
            'Revisá también título/marca/modelo/descripción en SYSCOM y volvé a sincronizar el catálogo.'
        );
    }

    /**
     * @return list<string>
     */
    private function buildMeliDomainDiscoveryQueries(SyscomProduct $product): array
    {
        $out = [];
        $push = function (string $s) use (&$out): void {
            $s = trim(preg_replace('/\s+/u', ' ', $s));
            if ($s === '') {
                return;
            }
            $s = Str::limit($s, 120, '');
            if ($s === '' || in_array($s, $out, true)) {
                return;
            }
            $out[] = $s;
        };

        $title = trim((string) ($product->titulo ?? ''));
        $brand = trim((string) ($product->marca ?? ''));
        $model = trim((string) ($product->modelo ?? ''));
        $desc = $this->plainTextDescription($product);
        $catLine = $this->flattenSyscomCategoriesLine($product);
        $blobProbe = mb_strtolower($title.' '.$brand.' '.$model.' '.$desc.' '.$catLine);
        $dvrProbe = $this->productLooksLikeNvrDvr($blobProbe, $title);
        $kitProbe = $this->productLooksLikeKit($blobProbe, $title);
        $videoProbe = $dvrProbe || $this->productBlobSuggestsVideovigilancia($product, $blobProbe);
        $titleForDiscovery = $this->titleForMeliCategoryDiscovery($title, $dvrProbe || ($kitProbe && $videoProbe) || $videoProbe);

        if ($catLine !== '') {
            $push($catLine);
            $push(Str::limit($catLine.' '.$brand.' '.$model, 120, ''));
        }

        if ($this->productLooksLikeAudioVideoBalun($blobProbe, $title)) {
            $push(Str::limit('balun convertidor audio video cctv '.$brand.' '.$model, 120, ''));
            $push(Str::limit('accesorio videovigilancia audio analogico '.$brand.' '.$model, 120, ''));
            $sanitizedBalun = $this->titleForBalunCategoryDiscovery($title);
            if ($sanitizedBalun !== '') {
                $push(Str::limit($sanitizedBalun.' '.$brand.' '.$model, 120, ''));
            }
        }

        if ($this->productLooksLikeHandToolKit($blobProbe, $title)) {
            $push(Str::limit('juego kit herramientas manuales combinadas taller '.$brand.' '.$model, 120, ''));
            $push(Str::limit('kit combinadas herramientas maletin '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        }

        if ($this->productLooksLikeFlashlight($blobProbe, $title)) {
            $push(Str::limit('linterna led recargable camping '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        }

        if ($this->productLooksLikeHomeAlarmAccessory($blobProbe, $title)) {
            $push(Str::limit('modulo expansion alarma intrusion zonas cableadas '.$brand.' '.$model, 120, ''));
            $push(Str::limit('accesorio alarma hogar panel vista '.$brand.' '.$model, 120, ''));
            if ($model !== '') {
                $push(Str::limit('alarma '.$model.' '.$brand, 120, ''));
            }
        }

        if ($this->productLooksLikePowerSupply($blobProbe, $title)) {
            $push(Str::limit('fuente poder alimentacion conmutada videovigilancia '.$brand.' '.$model, 120, ''));
            $push(Str::limit('fuente conmutada epcom powerline '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        }

        if ($this->productLooksLikePdu($blobProbe, $title)) {
            $push(Str::limit('PDU barra multicontactos rack distribucion energia '.$brand.' '.$model, 120, ''));
            $push(Str::limit('unidad distribucion energia rack servidor '.$brand.' '.$model, 120, ''));
            if ($model !== '') {
                $push(Str::limit('multicontacto rack '.$model.' '.$brand, 120, ''));
            }
        }

        if ($this->productLooksLikeSolarOrControllerCable($blobProbe, $title)) {
            $push(Str::limit('cable controlador energia solar awg epcom '.$brand.' '.$model, 120, ''));
            $push(Str::limit('cable electrico panel solar fotovoltaico '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        }

        if ($this->productLooksLikeElectricalConnector($blobProbe, $title)) {
            $push(Str::limit('conector push contactos componente electronico '.$brand.' '.$model, 120, ''));
            $push(Str::limit('conector epcom powerline videovigilancia '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        }

        if ($kitProbe && $videoProbe) {
            $push(Str::limit('kit videovigilancia camaras seguridad '.$brand.' '.$model, 120, ''));
            $push(Str::limit('kit camaras grabador seguridad '.$brand.' '.$model, 120, ''));
        }

        if ($this->productIsStandaloneSurveillanceCamera($blobProbe, $title, $product, $dvrProbe, $kitProbe)) {
            $push(Str::limit('camara videovigilancia seguridad '.$brand.' '.$model, 120, ''));
            $push(Str::limit('camara eyeball exterior 4k '.$brand.' '.$model, 120, ''));
            $sanitized = $this->titleForCameraCategoryDiscovery($title);
            if ($sanitized !== '') {
                $push(Str::limit($sanitized.' '.$brand.' '.$model, 120, ''));
            }
        }

        if ($this->productLooksLikeVideoIntercom($blobProbe, $title)) {
            $push(Str::limit('videoportero portero electrico monitor '.$brand.' '.$model, 120, ''));
            $push(Str::limit('monitor adicional videoportero '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        }

        if ($dvrProbe || $videoProbe) {
            $push(Str::limit('videovigilancia DVR videograbador '.$brand.' '.$model, 120, ''));
            $push(Str::limit('grabador digital seguridad '.$brand.' '.$model, 120, ''));
            if ($catLine !== '') {
                $push(Str::limit($catLine.' '.$model, 120, ''));
            }
        }
        if ($this->productLooksLikeSolarMount($blobProbe, $title)
            && ! $this->productLooksLikeSolarPanel($blobProbe, $title)) {
            $push(Str::limit('riel estructura montaje energia solar accesorio '.$brand.' '.$model, 120, ''));
            $push(Str::limit('accesorio instalacion paneles solares aluminio '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($this->titleForSolarMountCategoryDiscovery($title), 120, ''));
            }
        } elseif ($this->productLooksLikeSolarEnergyMeter($blobProbe, $title)) {
            $push(Str::limit('medidor energia trifasico riel din exportacion cero solar '.$brand.' '.$model, 120, ''));
            $push(Str::limit('medidor inteligente fotovoltaico '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        } elseif ($this->productLooksLikePoeInjector($blobProbe, $title)) {
            $push(Str::limit('inyector poe adaptador pared conectividad redes '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        } elseif ($this->productLooksLikeSwitch($blobProbe, $title)) {
            $push(Str::limit('switch de red '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        } elseif ($this->productLooksLikeOlt($blobProbe, $title)) {
            $push(Str::limit('olt gpon ftth terminal linea optica '.$brand.' '.$model, 120, ''));
            $push(Str::limit('equipo redes fibra optica conectividad '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        } elseif ($this->productLooksLikeWirelessAntenna($blobProbe, $title)) {
            $push(Str::limit('antena direccional inalámbrica wifi '.$brand.' '.$model, 120, ''));
            $push(Str::limit('antena conectividad redes '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        } elseif ($this->productLooksLikeRouterOnly($blobProbe, $title)) {
            $push(Str::limit('router red wifi gpon ont '.$brand.' '.$model, 120, ''));
            $push(Str::limit('router wifi '.$brand.' '.$model, 120, ''));
            if ($title !== '') {
                $push(Str::limit($title, 120, ''));
            }
        } elseif ($this->productLooksLikeNetworkingEquipment($blobProbe, $title)) {
            $push(Str::limit('redes networking '.$brand.' '.$model, 120, ''));
        }

        if ($titleForDiscovery !== '' && $desc !== '') {
            $push(Str::limit($titleForDiscovery.' '.$brand.' '.$model.' '.Str::limit($desc, 280, ''), 120, ''));
        }
        if ($brand !== '' && $model !== '' && $desc !== '') {
            $push(Str::limit($brand.' '.$model.' '.Str::limit($desc, 220, ''), 120, ''));
        }
        if ($desc !== '' && strlen($desc) > 40) {
            $push(Str::limit($brand.' '.$model.' '.$desc, 120, ''));
        }

        if ($titleForDiscovery !== '') {
            $push(Str::limit($titleForDiscovery, 120, ''));
        }
        if ($brand !== '' && $model !== '') {
            $push(Str::limit($brand.' '.$model, 120, ''));
        }
        if ($model !== '') {
            $push(Str::limit($model, 80, ''));
        }
        if ($brand !== '') {
            $push(Str::limit($brand, 60, ''));
        }
        if ($title === '' && $model === '') {
            $push('producto');
        }

        return $out;
    }

    private function plainTextDescription(SyscomProduct $product): string
    {
        $html = (string) ($product->descripcion ?? '');
        if ($html === '') {
            return '';
        }
        $t = strip_tags($html);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t);

        return trim((string) $t);
    }

    /**
     * Jerarquía de categorías SYSCOM (texto) para enriquecer la búsqueda en ML.
     */
    private function flattenSyscomCategoriesLine(SyscomProduct $product): string
    {
        $c = $product->categorias;
        if (! is_array($c) || $c === []) {
            return '';
        }
        $parts = [];
        $walk = function (mixed $node) use (&$walk, &$parts): void {
            if (is_string($node)) {
                $t = trim($node);
                if (strlen($t) > 1) {
                    $parts[] = $t;
                }

                return;
            }
            if (! is_array($node)) {
                return;
            }
            foreach (['nombre', 'name', 'titulo', 'title', 'descripcion', 'label'] as $k) {
                if (isset($node[$k]) && is_string($node[$k])) {
                    $walk($node[$k]);
                }
            }
            foreach ($node as $k => $v) {
                if (in_array($k, ['nombre', 'name', 'titulo', 'title', 'descripcion', 'label'], true)) {
                    continue;
                }
                $walk($v);
            }
        };
        $walk($c);
        $parts = array_values(array_unique(array_filter($parts)));

        return Str::limit(implode(' ', $parts), 200, '');
    }

    private function meliCategoryKeywordOverlapScore(string $blobLower, string $categoryNameLower): int
    {
        if ($blobLower === '' || $categoryNameLower === '') {
            return 0;
        }
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $blobLower, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens)) {
            return 0;
        }
        $stop = [
            'para', 'con', 'sin', 'como', 'esta', 'este', 'unos', 'unas', 'cada', 'todo', 'toda',
            'sobre', 'entre', 'desde', 'hasta', 'donde', 'producto', 'marca', 'modelo', 'pieza',
        ];
        $seen = [];
        $score = 0;
        foreach ($tokens as $tok) {
            $tok = mb_strtolower($tok);
            if (strlen($tok) < 4 || isset($seen[$tok]) || in_array($tok, $stop, true)) {
                continue;
            }
            $seen[$tok] = true;
            if (str_contains($categoryNameLower, $tok)) {
                $score += 2;
            }
            if ($score >= 14) {
                break;
            }
        }

        return $score;
    }

    private function productLooksLikeTire(string $title, string $model, string $blobLower): bool
    {
        if (preg_match('/\b\d{2,3}\/\d{2}\s*r\d{2}\b/i', $title.' '.$model)) {
            return true;
        }

        return str_contains($blobLower, 'llanta')
            || str_contains($blobLower, 'neum')
            || str_contains($blobLower, 'rin ')
            || str_contains($blobLower, 'neumatico');
    }

    private function blobSuggestsSecurityOrVehicularAccess(string $blobLower): bool
    {
        $keys = [
            'radar', 'vehicular', 'vehículo', 'sensor', 'presencia', 'barrera', 'acceso vehicular',
            'control de acceso', 'hikvision', 'ds-tmg', 'antifall', 'anti-fall', 'com y no', 'com y nc',
            'entrada y salida', 'lector', 'tag', 'tarjeta', 'videoporter', 'cctv', 'camara', 'cámara',
        ];
        foreach ($keys as $k) {
            if (str_contains($blobLower, $k)) {
                return true;
            }
        }

        return false;
    }

    private function categoryNameLooksLikeTire(string $nameLower): bool
    {
        return str_contains($nameLower, 'llanta')
            || str_contains($nameLower, 'neum')
            || str_contains($nameLower, 'rin ')
            || str_contains($nameLower, 'rin-')
            || str_contains($nameLower, 'neumatico');
    }

    private function categoryNameLooksLikeAccessOrSecurity(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeVehicleCatalog($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'acceso vehicular')
            || str_contains($nameLower, 'control de acceso')
            || (str_contains($nameLower, 'acceso') && ! str_contains($nameLower, 'vehículo') && ! str_contains($nameLower, 'vehiculo'))
            || str_contains($nameLower, 'seguridad')
            || str_contains($nameLower, 'sensor')
            || str_contains($nameLower, 'intercom')
            || str_contains($nameLower, 'cctv')
            || str_contains($nameLower, 'alarma');
    }

    /**
     * Evita confundir videovigilancia con "Accesorios para Vehículos" / bocinas (ML suele sugerir mal por "audio").
     */
    private function categoryNameLooksLikeVehicleCatalog(string $nameLower): bool
    {
        return str_contains($nameLower, 'accesorios para veh')
            || str_contains($nameLower, 'refacciones auto')
            || str_contains($nameLower, 'refacciones autos')
            || str_contains($nameLower, 'audio para veh')
            || str_contains($nameLower, 'bocina')
            || str_contains($nameLower, 'autoestéreo')
            || str_contains($nameLower, 'autoestereo')
            || str_contains($nameLower, 'estéreo para veh')
            || str_contains($nameLower, 'estereo para veh')
            || $this->categoryNameLooksLikeVehicleTurboMisleading($nameLower);
    }

    /**
     * Nombre corto de categoría ML (domain_discovery suele devolver solo «Turbos»).
     */
    private function categoryNameLooksLikeVehicleTurboMisleading(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeCamera($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'turbo')
            || str_contains($nameLower, 'supercargador')
            || str_contains($nameLower, 'turbos y super');
    }

    private function productBlobSuggestsVideovigilancia(SyscomProduct $product, string $blobLower): bool
    {
        $title = trim((string) ($product->titulo ?? ''));
        if ($this->productLooksLikeSolarMount($blobLower, $title)
            || $this->productLooksLikeSolarEnergyMeter($blobLower, $title)
            || $this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikeRouterOnly($blobLower, $title)
            || $this->productLooksLikeOlt($blobLower, $title)) {
            return false;
        }

        if (str_contains($blobLower, 'videovigil')
            || str_contains($blobLower, 'videograbador')
            || str_contains($blobLower, 'turbo hd')
            || str_contains($blobLower, 'turbohd')
            || preg_match('/\b(nvr|dvr)\b/u', $blobLower) === 1) {
            return true;
        }

        $cat = mb_strtolower($this->flattenSyscomCategoriesLine($product));

        return str_contains($cat, 'videovigil')
            || str_contains($cat, 'videograbador')
            || str_contains($cat, 'cctv')
            || str_contains($cat, 'dvr')
            || str_contains($cat, 'nvr');
    }

    /**
     * Quita palabras que desvían domain_discovery (ej. "Audio Bidireccional" en un DVR).
     */
    private function titleForMeliCategoryDiscovery(string $title, bool $stripMisleadingAudioWords): string
    {
        if (! $stripMisleadingAudioWords || $title === '') {
            return $title;
        }

        $t = preg_replace(
            '/\b(audio|bidireccional|coaxitron|parlante|bocina|estéreo|estereo|watts?|vatios?)\b/iu',
            ' ',
            $title
        );

        return trim((string) preg_replace('/\s+/u', ' ', (string) $t));
    }

    /**
     * Evita que «TURBOHD» en el título dispare domain_discovery en turbos de auto (MLM164668).
     */
    private function titleForCameraCategoryDiscovery(string $title): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $title));
        if ($t === '') {
            return '';
        }

        $t = preg_replace('/\bturbohd\b/iu', 'camara videovigilancia', $t) ?? $t;
        $t = preg_replace('/\bturbo\s*hd\b/iu', 'camara videovigilancia', $t) ?? $t;
        $t = preg_replace('/\bturbo\b/iu', ' ', $t) ?? $t;

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    private function productIsStandaloneSurveillanceCamera(
        string $blob,
        string $title,
        SyscomProduct $product,
        bool $dvrProduct,
        bool $isKitProduct
    ): bool {
        if ($dvrProduct || $isKitProduct) {
            return false;
        }

        if ($this->productLooksLikeSwitch($blob, $title)
            || $this->productLooksLikeRouterOnly($blob, $title)
            || $this->productLooksLikeOlt($blob, $title)
            || $this->productLooksLikePoeInjector($blob, $title)
            || $this->productLooksLikePowerSupply($blob, $title)) {
            return false;
        }

        if ($this->productLooksLikeVideoIntercom($blob, $title)) {
            return false;
        }

        if ($this->productLooksLikeSurveillanceCamera($blob, $title)) {
            return true;
        }

        if ($this->productBlobSuggestsVideovigilancia($product, $blob)
            && ! $this->productLooksLikeNvrDvr($blob, $title)) {
            return str_contains($blob, 'turbohd')
                || str_contains($blob, 'turbo hd')
                || str_contains($blob, 'eyeball')
                || str_contains($blob, 'turret')
                || str_contains($blob, 'bullet')
                || preg_match('/\b(tvi|ahd|cvi|cvbs)\b/u', $blob) === 1;
        }

        return false;
    }

    /**
     * @param  array<string, int|float>  $scores
     * @param  array<string, string>  $names
     */
    private function pickBestScoredMeliCategoryId(
        array $scores,
        array $names,
        bool $dvrProduct,
        bool $videoSurveillanceProduct,
        bool $switchProduct = false,
        bool $routerProduct = false,
        bool $surveillanceKitProduct = false,
        bool $solarMountProduct = false,
        bool $oltProduct = false,
        bool $handToolKitProduct = false,
        bool $standaloneCameraProduct = false,
        bool $antennaProduct = false
    ): ?string {
        $preferredSolarMountId = $solarMountProduct ? $this->configuredSolarMountCategoryId() : '';
        $preferredOltId = $oltProduct ? $this->configuredOltCategoryId() : '';
        $preferredAntennaId = $antennaProduct ? $this->configuredAntennaCategoryId() : '';
        $preferredToolKitId = $handToolKitProduct ? $this->configuredToolKitCategoryId() : '';
        $preferredCameraId = $standaloneCameraProduct ? $this->configuredCameraCategoryId() : '';

        foreach ($scores as $id => $score) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $nameLower = mb_strtolower((string) ($names[$id] ?? ''));

            if ($standaloneCameraProduct) {
                if ($this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    && ! $this->categoryNameLooksLikeKit($nameLower)) {
                    continue;
                }
                if ($preferredCameraId !== '' && $id === $preferredCameraId) {
                    return $id;
                }
                if ($this->categoryNameLooksLikeCamera($nameLower)) {
                    return $id;
                }
            }

            if ($handToolKitProduct) {
                if ($this->categoryNameLooksLikeHuntingOrSportMisleadingCategory($nameLower)
                    || $this->categoryNameLooksLikeToyToolSetMisleadingCategory($nameLower)
                    || ($this->categoryNameLooksLikeCamera($nameLower)
                        && ! $this->categoryNameLooksLikeHandToolKitFriendlyCategory($nameLower))) {
                    continue;
                }
                if ($preferredToolKitId !== '' && $id === $preferredToolKitId) {
                    return $id;
                }
                if ($this->categoryNameLooksLikeHandToolKitFriendlyCategory($nameLower)) {
                    return $id;
                }
            }

            if ($solarMountProduct) {
                if ($this->categoryNameLooksLikeSolarPanelCategory($nameLower)
                    || $this->categoryNameLooksLikeVehicleSunroofRiel($nameLower)
                    || $this->categoryNameLooksLikeSoftwareCategory($nameLower)
                    || $this->categoryNameLooksLikeCameraMountCategory($nameLower)
                    || $this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    || ($this->categoryNameLooksLikeCamera($nameLower)
                        && ! $this->categoryNameLooksLikeSolarMountFriendlyCategory($nameLower))) {
                    continue;
                }
                if ($preferredSolarMountId !== '' && $id === $preferredSolarMountId) {
                    return $id;
                }
                if ($this->categoryNameLooksLikeSolarMountFriendlyCategory($nameLower)) {
                    return $id;
                }
            }

            if ($videoSurveillanceProduct && $this->categoryNameLooksLikeVehicleCatalog($nameLower)) {
                continue;
            }

            if ($surveillanceKitProduct) {
                if ($this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    && ! $this->categoryNameLooksLikeKit($nameLower)) {
                    continue;
                }
                if ($this->categoryNameLooksLikeSurveillanceKitCategory($nameLower)) {
                    return $id;
                }
            }

            if ($dvrProduct) {
                if ($this->categoryNameLooksLikeNvrDvrOnly($nameLower) || $this->categoryNameLooksLikeCamera($nameLower)) {
                    return $id;
                }

                continue;
            }

            if ($oltProduct) {
                if ($this->categoryNameLooksLikeRouterCategory($nameLower)
                    || $this->categoryNameLooksLikeModemCategory($nameLower)) {
                    continue;
                }
                if ($this->categoryNameLooksLikeSwitchCategory($nameLower)
                    && ! $this->categoryNameLooksLikeOltFriendlyCategory($nameLower)) {
                    continue;
                }
                if ($preferredOltId !== '' && $id === $preferredOltId) {
                    return $id;
                }
                if ($this->categoryNameLooksLikeOltFriendlyCategory($nameLower)) {
                    return $id;
                }
            }

            if ($antennaProduct) {
                if ($this->categoryNameLooksLikeRouterCategory($nameLower)
                    || $this->categoryNameLooksLikeModemCategory($nameLower)
                    || ($this->categoryNameLooksLikeSwitchCategory($nameLower)
                        && ! $this->categoryNameLooksLikeAntennaCategory($nameLower))) {
                    continue;
                }
                if ($preferredAntennaId !== '' && $id === $preferredAntennaId) {
                    return $id;
                }
                if ($this->categoryNameLooksLikeAntennaCategory($nameLower)) {
                    return $id;
                }
            }

            if ($switchProduct) {
                if ($this->categoryNameLooksLikeSurveillanceKitCategory($nameLower)) {
                    continue;
                }
                if ($this->categoryNameLooksLikeCamera($nameLower)
                    && ! $this->categoryNameLooksLikeSwitchCategory($nameLower)) {
                    continue;
                }
                if ($this->categoryNameLooksLikeRouterCategory($nameLower)
                    && ! $this->categoryNameLooksLikeSwitchCategory($nameLower)) {
                    continue;
                }
                if ($this->categoryNameLooksLikeUsbHubCategory($nameLower)) {
                    continue;
                }
                $preferredSwitchId = $this->configuredSwitchCategoryId();
                if ($preferredSwitchId !== '' && $id === $preferredSwitchId) {
                    return $id;
                }
                if ($this->categoryNameLooksLikeSwitchCategory($nameLower)) {
                    return $id;
                }
            }

            if ($routerProduct) {
                if ($this->categoryNameLooksLikeSwitchCategory($nameLower)
                    && ! $this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    continue;
                }
                if ($this->categoryNameLooksLikeRouterCategory($nameLower)) {
                    return $id;
                }
            }

            if ($switchProduct) {
                continue;
            }

            return $id;
        }

        if ($switchProduct) {
            return $this->configuredSwitchCategoryId();
        }

        return null;
    }

    private function resolveMeliCategoryIdFromSyscomHierarchy(
        User $user,
        SyscomProduct $product,
        string $catLine,
        bool $dvrProduct
    ): ?string {
        $catLower = mb_strtolower($catLine);
        if ($catLower === '') {
            return null;
        }

        [$title, $blob] = $this->productClassificationContext($product);
        $isKitProduct = $this->productLooksLikeKit($blob, $title);
        $standaloneCameraProduct = $this->productIsStandaloneSurveillanceCamera(
            $blob,
            $title,
            $product,
            $dvrProduct,
            $isKitProduct
        );
        $solarMountProduct = ! $this->productLooksLikeSolarPanel($blob, $title)
            && ! $this->productLooksLikeSolarEnergyMeter($blob, $title)
            && $this->productLooksLikeSolarMount($blob, $title);

        $queries = [
            Str::limit($catLine, 120, ''),
        ];
        if ($dvrProduct || str_contains($catLower, 'videograbador') || str_contains($catLower, 'dvr')) {
            $brand = trim((string) ($product->marca ?? ''));
            $model = trim((string) ($product->modelo ?? ''));
            $queries[] = Str::limit('videovigilancia videograbador DVR '.$brand.' '.$model, 120, '');
        }

        foreach (array_unique(array_filter($queries)) as $q) {
            try {
                $suggestions = $this->meli->suggestCategories($user, $q, 6);
            } catch (\Throwable) {
                continue;
            }

            foreach ($suggestions as $sug) {
                if (! is_array($sug)) {
                    continue;
                }
                $id = (string) ($sug['id'] ?? '');
                $name = (string) ($sug['name'] ?? '');
                if ($id === '') {
                    continue;
                }
                $nameLower = mb_strtolower($name);
                if ($this->categoryNameLooksLikeVehicleCatalog($nameLower)) {
                    continue;
                }
                if ($solarMountProduct
                    && ($this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                        || $this->categoryNameLooksLikeCamera($nameLower))) {
                    continue;
                }
                if ($standaloneCameraProduct
                    && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    && ! $this->categoryNameLooksLikeKit($nameLower)) {
                    continue;
                }
                if ($standaloneCameraProduct && $this->categoryNameLooksLikeCamera($nameLower)) {
                    Log::info('SyscomMeliPublish: categoría ML por jerarquía SYSCOM (cámara)', [
                        'category_id' => $id,
                        'category_name' => $name,
                        'query' => $q,
                    ]);

                    return $id;
                }
                if (! $dvrProduct
                    && $this->categoryNameLooksLikeNvrDvrOnly($nameLower)
                    && ! $this->categoryNameLooksLikeSurveillanceKitCategory($nameLower)) {
                    continue;
                }
                if ($dvrProduct && ! $this->categoryNameLooksLikeNvrDvrOnly($nameLower) && ! $this->categoryNameLooksLikeCamera($nameLower)) {
                    continue;
                }

                Log::info('SyscomMeliPublish: categoría ML por jerarquía SYSCOM', [
                    'category_id' => $id,
                    'category_name' => $name,
                    'query' => $q,
                ]);

                return $id;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string} title, blob (minúsculas)
     */
    private function productClassificationContext(SyscomProduct $product): array
    {
        $title = $this->buildPublishTitle($product);
        $brand = trim((string) ($product->marca ?? ''));
        $model = trim((string) ($product->modelo ?? ''));
        $rawTitulo = trim((string) ($product->titulo ?? ''));
        $descPlain = $this->plainTextDescription($product);
        $catLine = $this->flattenSyscomCategoriesLine($product);
        $blob = mb_strtolower($title.' '.$brand.' '.$model.' '.$descPlain.' '.$catLine);
        if ($rawTitulo !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($rawTitulo))) {
            $blob .= ' '.mb_strtolower($rawTitulo);
        }

        return [$title, $blob];
    }

    /**
     * Corrige categorías fijas cuando resolve/domain_discovery devolvió una hoja incorrecta.
     */
    private function applyAutoCategoryFixes(SyscomProduct $product, string $categoryId): string
    {
        [$title, $blob] = $this->productClassificationContext($product);

        // Fase 3: si el producto es una Dash Cam, resolveMeliCategoryId() ya buscó
        // y validó una categoría vehicular específica. No permitimos que las reglas
        // heredadas de CCTV/DVR sustituyan después esa categoría por Cámaras de Seguridad.
        $dashCamProduct = $this->productLooksLikeDashCam($blob, $title);
        if ($dashCamProduct) {
            Log::info('SyscomMeliPublish: se conserva categoría vehicular de Dash Cam', [
                'phase' => 3,
                'category_id' => $categoryId,
                'syscom_producto_id' => $product->syscom_producto_id,
                'titulo' => $title,
            ]);

            return $categoryId;
        }

        $dvrProduct = $this->productLooksLikeNvrDvr($blob, $title);
        $isKitProduct = $this->productLooksLikeKit($blob, $title);
        $videoSurveillance = $dvrProduct || $this->productBlobSuggestsVideovigilancia($product, $blob);
        $balunProduct = $this->productLooksLikeAudioVideoBalun($blob, $title);
        $surveillanceKitProduct = $isKitProduct && $videoSurveillance && ! $balunProduct;

        if ($this->productLooksLikeUps($blob, $title)) {
            return $this->configuredUpsCategoryId();
        }

        if ($this->productLooksLikeSolarEnergyMeter($blob, $title)) {
            return $this->configuredSolarMeterCategoryId();
        }

        if ($this->productLooksLikePoeInjector($blob, $title)) {
            return $this->configuredPoeInjectorCategoryId();
        }

        if ($this->productLooksLikeHomeAlarmAccessory($blob, $title)) {
            return $this->configuredAlarmCategoryId();
        }

        if ($this->productLooksLikeRouterOnly($blob, $title)) {
            return $this->configuredRouterCategoryId();
        }

        if ($this->productLooksLikeHandToolKit($blob, $title)) {
            return $this->configuredToolKitCategoryId();
        }

        if ($this->productLooksLikePowerSupply($blob, $title)) {
            return $this->configuredPowerSupplyCategoryId();
        }

        if ($this->productLooksLikePdu($blob, $title)) {
            return $this->configuredPduCategoryId();
        }

        if (! $this->productLooksLikeSolarPanel($blob, $title)
            && ! $this->productLooksLikeSolarEnergyMeter($blob, $title)
            && $this->productLooksLikeSolarMount($blob, $title)) {
            return $this->configuredSolarMountCategoryId();
        }

        if ($this->productLooksLikeSolarOrControllerCable($blob, $title)) {
            return $this->configuredSolarCableCategoryId();
        }

        if ($this->productLooksLikeElectricalConnector($blob, $title)) {
            return $this->configuredConnectorCategoryId();
        }

        if ($this->productLooksLikeVideoIntercom($blob, $title)) {
            return $this->configuredVideoporteroCategoryId();
        }

        if ($this->productLooksLikeSwitch($blob, $title)) {
            return $this->configuredSwitchCategoryId();
        }

        if ($dvrProduct && ! $isKitProduct) {
            $standaloneCameraProduct = $this->productIsStandaloneSurveillanceCamera(
                $blob,
                $title,
                $product,
                $dvrProduct,
                $isKitProduct
            );
            if (! $standaloneCameraProduct) {
                $dvrId = trim((string) config('syscom.meli_dvr_category_id', ''));
                if ($dvrId !== '') {
                    return $dvrId;
                }
            }
        }

        if ($this->productLooksLikeOlt($blob, $title)) {
            return $this->configuredOltCategoryId();
        }

        if ($this->productLooksLikeWirelessAntenna($blob, $title)) {
            return $this->configuredAntennaCategoryId();
        }

        if ($this->productIsStandaloneSurveillanceCamera($blob, $title, $product, $dvrProduct, $isKitProduct)) {
            return $this->configuredCameraCategoryId();
        }

        if ($balunProduct) {
            return $this->configuredBalunCategoryId();
        }

        $kitVideoId = $this->configuredKitVideoCategoryId();
        if ($surveillanceKitProduct && $kitVideoId !== '') {
            return $kitVideoId;
        }

        if ($this->productLooksLikeHandToolKit($blob, $title)) {
            return $this->configuredToolKitCategoryId();
        }

        if ($this->productLooksLikeFlashlight($blob, $title)) {
            return $this->configuredFlashlightCategoryId();
        }

        return $categoryId;
    }

    /**
     * Indica si la categoría ML coincide exactamente con el mapping aprobado
     * de la categoría SYSCOM primaria del producto.
     */
    /**
     * Indica si la categoría ML coincide exactamente con un
     * override aprobado para este producto específico.
     */
    private function hasApprovedProductOverride(
        SyscomProduct $product,
        string $categoryId
    ): bool {
        $productId = (int) ($product->id ?? 0);
        $categoryId = strtoupper(trim($categoryId));

        if (
            $productId <= 0
            || ! preg_match('/^MLM\d+$/', $categoryId)
        ) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table(
            'syscom_meli_product_category_overrides'
        )
            ->where('syscom_product_id', $productId)
            ->where('approved', true)
            ->whereRaw(
                'UPPER(TRIM(meli_category_id)) = ?',
                [$categoryId]
            )
            ->exists();
    }

    private function hasApprovedSyscomMapping(
        SyscomProduct $product,
        string $categoryId
    ): bool {
        $primaryCategoryId = (int) (
            $product->syscom_primary_category_id ?? 0
        );

        $categoryId = strtoupper(trim($categoryId));

        if (
            $primaryCategoryId <= 0
            || ! preg_match('/^MLM\d+$/', $categoryId)
        ) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table(
            'syscom_meli_category_maps'
        )
            ->where('syscom_category_id', $primaryCategoryId)
            ->where('approved', true)
            ->whereRaw(
                'UPPER(TRIM(meli_category_id)) = ?',
                [$categoryId]
            )
            ->exists();
    }

    private function assertMeliCategoryPlausibleForProduct(
        User $user,
        SyscomProduct $product,
        string $categoryId,
        bool $categoryManual
    ): void {
        $categoryId = strtoupper(trim($categoryId));
        if (! preg_match('/^MLM\d+$/', $categoryId)) {
            throw new \RuntimeException(
                'Categoría de Mercado Libre inválida: «'.$categoryId.'». Debe ser un category_id de México con formato MLM seguido de números; no pegues el id de una publicación.'
            );
        }

        try {
            $categoryMeta = $this->meli->getCategory($user, $categoryId);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'No se pudo validar la categoría '.$categoryId.' con Mercado Libre. Por seguridad no se publicó. Detalle: '.$e->getMessage(),
                0,
                $e
            );
        }

        $settings = is_array($categoryMeta['settings'] ?? null) ? $categoryMeta['settings'] : [];
        if (array_key_exists('listing_allowed', $settings) && $settings['listing_allowed'] !== true) {
            throw new \RuntimeException(
                'La categoría '.$categoryId.' no permite crear publicaciones. Selecciona una categoría final distinta.'
            );
        }

        $children = is_array($categoryMeta['children_categories'] ?? null)
            ? $categoryMeta['children_categories']
            : [];
        if ($children !== []) {
            throw new \RuntimeException(
                'La categoría '.$categoryId.' todavía contiene subcategorías y no es una categoría final segura. Selecciona una categoría hija final.'
            );
        }

        /*
         * Si la equivalencia SYSCOM -> Mercado Libre está aprobada y coincide
         * exactamente con esta categoría, ya no usamos regex de título o
         * descripción para contradecirla.
         *
         * IMPORTANTE: antes de llegar aquí ya se validó:
         * - formato MLM;
         * - existencia de la categoría;
         * - listing_allowed;
         * - que sea una categoría final sin hijos.
         */
        $approvedProductOverride =
            $this->hasApprovedProductOverride(
                $product,
                $categoryId
            );

        $approvedSyscomMapping =
            $this->hasApprovedSyscomMapping(
                $product,
                $categoryId
            );

        if (
            $approvedProductOverride
            || $approvedSyscomMapping
        ) {
            Log::info(
                'SyscomMeliPublish: autoridad de categoría aprobada; heurísticas omitidas',
                [
                    'syscom_producto_id' =>
                        $product->syscom_producto_id,
                    'modelo' =>
                        $product->modelo,
                    'category_id' =>
                        $categoryId,
                    'source' =>
                        $approvedProductOverride
                            ? 'product_category_override'
                            : 'syscom_category_map',
                ]
            );

            return;
        }

        [$title, $blob] = $this->productClassificationContext($product);

        $dashCamProduct = $this->productLooksLikeDashCam($blob, $title);

        $dvrProduct = ! $dashCamProduct
            && $this->productLooksLikeNvrDvr($blob, $title);

        $videoSurveillance = ! $dashCamProduct
            && (
                $dvrProduct
                || $this->productBlobSuggestsVideovigilancia($product, $blob)
            );
        $isKitProduct = $this->productLooksLikeKit($blob, $title);
        $switchProduct = $this->productLooksLikeSwitch($blob, $title);
        $oltProduct = $this->productLooksLikeOlt($blob, $title);
        $antennaProduct = $this->productLooksLikeWirelessAntenna($blob, $title);
        $routerProduct = $this->productLooksLikeRouterOnly($blob, $title);
        $poeInjectorProduct = $this->productLooksLikePoeInjector($blob, $title);
        $balunProduct = $this->productLooksLikeAudioVideoBalun($blob, $title);
        $surveillanceKitProduct = $isKitProduct && $videoSurveillance && ! $switchProduct && ! $routerProduct && ! $balunProduct;
        $solarPanelProduct = $this->productLooksLikeSolarPanel($blob, $title);
        $solarMeterProduct = $this->productLooksLikeSolarEnergyMeter($blob, $title);
        $solarMountProduct = ! $solarPanelProduct && ! $solarMeterProduct && $this->productLooksLikeSolarMount($blob, $title);
        $handToolKitProduct = $this->productLooksLikeHandToolKit($blob, $title);
        $flashlightProduct = $this->productLooksLikeFlashlight($blob, $title);
        $standaloneCameraProduct = $this->productIsStandaloneSurveillanceCamera(
            $blob,
            $title,
            $product,
            $dvrProduct,
            $isKitProduct
        );
        $alarmAccessoryProduct = $this->productLooksLikeHomeAlarmAccessory($blob, $title);
        $powerSupplyProduct = $this->productLooksLikePowerSupply($blob, $title);
        $pduProduct = $this->productLooksLikePdu($blob, $title);
        $solarCableProduct = $this->productLooksLikeSolarOrControllerCable($blob, $title);
        $connectorProduct = $this->productLooksLikeElectricalConnector($blob, $title);
        $videoporteroProduct = $this->productLooksLikeVideoIntercom($blob, $title);

        if (! $videoSurveillance && ! $switchProduct && ! $poeInjectorProduct && ! $solarMountProduct && ! $solarMeterProduct && ! $handToolKitProduct && ! $flashlightProduct && ! $standaloneCameraProduct && ! $balunProduct && ! $oltProduct && ! $antennaProduct && ! $routerProduct && ! $alarmAccessoryProduct && ! $powerSupplyProduct && ! $pduProduct && ! $solarCableProduct && ! $connectorProduct && ! $videoporteroProduct) {
            return;
        }

        $cat = $categoryMeta;

        $path = [];
        foreach (is_array($cat['path_from_root'] ?? null) ? $cat['path_from_root'] : [] as $node) {
            if (is_array($node) && isset($node['name'])) {
                $path[] = (string) $node['name'];
            }
        }
        $pathStr = mb_strtolower(implode(' ', $path).' '.(string) ($cat['name'] ?? ''));

        if ($this->categoryNameLooksLikeVehicleCatalog($pathStr) && ! $dashCamProduct) {
            $hint = $this->productIsStandaloneSurveillanceCamera($blob, $title, $product, $dvrProduct, $isKitProduct)
                ? 'Cámaras de Seguridad ('.$this->configuredCameraCategoryId().', variable SYSCOM_MELI_CAMERA_CATEGORY_ID)'
                : 'Videograbadoras/DVR (SYSCOM_MELI_DVR_CATEGORY_ID)';
            throw new \RuntimeException(
                'La categoría ML elegida parece de vehículos/refacciones ('.$categoryId.': '.implode(' > ', $path).'), pero el producto SYSCOM es videovigilancia. '.
                'En «Categoría ML» pegá el MLM de '.$hint.' o republicá (el panel ya puede asignarlo solo).'
            );
        }

        if ($dvrProduct && ! $isKitProduct && ! $categoryManual && ! $switchProduct
            && ! $this->categoryNameLooksLikeNvrDvrOnly($pathStr)
            && ! $this->categoryNameLooksLikeCamera($pathStr)) {
            $dvrHint = trim((string) config('syscom.meli_dvr_category_id', ''));
            throw new \RuntimeException(
                'Mercado Libre sugirió una categoría que no coincide con un DVR/grabador ('.$categoryId.': '.implode(' > ', $path).'). '.
                ($dvrHint !== '' ? 'Usá Grabadores DVR ('.$dvrHint.', variable SYSCOM_MELI_DVR_CATEGORY_ID) o ' : '').
                '«Categoría ML» manual y republicá.'
            );
        }

        if ($switchProduct && ! $categoryManual
            && $this->categoryNameLooksLikeRouterCategory($pathStr)
            && ! $this->categoryNameLooksLikeSwitchCategory($pathStr)) {
            throw new \RuntimeException(
                'El producto es un switch de red, pero Mercado Libre asignó la categoría Routers ('.$categoryId.': '.implode(' > ', $path).'). '.
                'En «Categoría ML» pegá Interruptores de Red (por defecto '.$this->configuredSwitchCategoryId().', variable SYSCOM_MELI_SWITCH_CATEGORY_ID) y volvé a publicar.'
            );
        }

        if ($switchProduct && ! $categoryManual
            && $this->categoryNameLooksLikeUsbHubCategory($pathStr)) {
            throw new \RuntimeException(
                'El producto es un switch de red, pero Mercado Libre asignó Hubs USB ('.$categoryId.': '.implode(' > ', $path).'). '.
                'Usá Interruptores de Red ('.$this->configuredSwitchCategoryId().') o «Categoría ML» manual y republicá.'
            );
        }

        if ($switchProduct && ! $categoryManual
            && $this->categoryNameLooksLikeSurveillanceKitCategory($pathStr)) {
            throw new \RuntimeException(
                'El producto es un switch de red, pero Mercado Libre asignó Kits de Seguridad ('.$categoryId.': '.implode(' > ', $path).'). '.
                'Usá Interruptores de Red ('.$this->configuredSwitchCategoryId().', variable SYSCOM_MELI_SWITCH_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($switchProduct
            && $this->categoryNameLooksLikeCamera($pathStr)
            && ! $this->categoryNameLooksLikeSwitchCategory($pathStr)) {
            $switchId = $this->configuredSwitchCategoryId();
            throw new \RuntimeException(
                'El producto es un switch de red (ej. Hikvision PoE), pero Mercado Libre asignó Cámaras de Seguridad ('.$categoryId.': '.implode(' > ', $path).'). '.
                ($categoryManual ? 'Quitá MLM437575 del campo «Categoría ML» si lo dejaste de la cámara anterior. ' : '').
                'Usá Interruptores de Red (por defecto '.$switchId.', variable SYSCOM_MELI_SWITCH_CATEGORY_ID) o pegá '.$switchId.' en «Categoría ML» y republicá.'
            );
        }

        if ($oltProduct && ! $categoryManual
            && ! $this->categoryPathLooksLikeOltFriendly($pathStr, $categoryId)
            && ($this->categoryNameLooksLikeRouterCategory($pathStr)
                || $this->categoryNameLooksLikeModemCategory($pathStr)
                || ($this->categoryNameLooksLikeSwitchCategory($pathStr)
                    && ! $this->categoryNameLooksLikeOltFriendlyCategory($pathStr)))) {
            throw new \RuntimeException(
                'El producto es una OLT GPON/FTTH (terminal de línea óptica), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Conectividad y Redes → Otros ('.$this->configuredOltCategoryId().', variable SYSCOM_MELI_OLT_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($antennaProduct && ! $categoryManual
            && ! $this->categoryNameLooksLikeAntennaCategory($pathStr)
            && ($this->categoryNameLooksLikeRouterCategory($pathStr)
                || $this->categoryNameLooksLikeModemCategory($pathStr)
                || ($this->categoryNameLooksLikeSwitchCategory($pathStr)
                    && ! $this->categoryNameLooksLikeAntennaCategory($pathStr)))) {
            $antennaId = $this->configuredAntennaCategoryId();
            throw new \RuntimeException(
                'El producto es una antena inalámbrica (no un router ni switch), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Antenas (por defecto '.$antennaId.', variable SYSCOM_MELI_ANTENNA_CATEGORY_ID) o pegá '.$antennaId.' en «Categoría ML» y republicá.'
            );
        }

        if ($poeInjectorProduct && ! $categoryManual
            && ($this->categoryNameLooksLikeSolarMountFriendlyCategory($pathStr)
                || $this->categoryNameLooksLikeSolarPanelCategory($pathStr)
                || ($this->categoryNameLooksLikeSwitchCategory($pathStr)
                    && ! $this->categoryNameLooksLikePoeInjectorFriendlyCategory($pathStr)))) {
            $poeId = $this->configuredPoeInjectorCategoryId();
            throw new \RuntimeException(
                'El producto es un inyector/adaptador PoE (redes), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Inyectores Poe (por defecto '.$poeId.', variable SYSCOM_MELI_POE_INJECTOR_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($alarmAccessoryProduct
            && ($this->categoryNameLooksLikeThermostatCategory($pathStr)
                || $this->categoryNameLooksLikeBarcodeScannerCategory($pathStr))) {
            $alarmId = $this->configuredAlarmCategoryId();
            throw new \RuntimeException(
                'El producto es un accesorio de alarma/intrusión (ej. módulo expansor Honeywell 4219), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                ($categoryManual ? 'Quitá categorías manuales de otro producto (termostato, cámara, switch). ' : '').
                'Usá Alarmas y Sensores (por defecto '.$alarmId.', variable SYSCOM_MELI_ALARM_CATEGORY_ID) o pegá '.$alarmId.' en «Categoría ML» y republicá.'
            );
        }

        if ($powerSupplyProduct
            && $this->categoryNameLooksLikeCamera($pathStr)
            && ! $this->categoryNameLooksLikePowerSupplyCategory($pathStr)) {
            $psuId = $this->configuredPowerSupplyCategoryId();
            throw new \RuntimeException(
                'El producto es una fuente de poder/alimentación (ej. Epcom PLK), pero Mercado Libre asignó Cámaras de Seguridad ('.$categoryId.': '.implode(' > ', $path).'). '.
                ($categoryManual ? 'Quitá MLM437575 u otra categoría manual que hayas dejado del producto anterior. ' : '').
                'Usá Fuentes Conmutadas (por defecto '.$psuId.', variable SYSCOM_MELI_POWER_SUPPLY_CATEGORY_ID) o pegá '.$psuId.' en «Categoría ML» y republicá.'
            );
        }

        if ($routerProduct && ! $categoryManual
            && $this->categoryNameLooksLikePowerSupplyCategory($pathStr)
            && ! $this->categoryNameLooksLikeRouterCategory($pathStr)) {
            $routerId = $this->configuredRouterCategoryId();
            throw new \RuntimeException(
                'El producto es un router / ONT con Wi‑Fi, pero Mercado Libre asignó Fuentes Conmutadas ('.
                $categoryId.': '.implode(' > ', $path).'). '.
                'Usá Routers (por defecto '.$routerId.', variable SYSCOM_MELI_ROUTER_CATEGORY_ID) o republicá.'
            );
        }

        if ($pduProduct
            && $this->categoryNameLooksLikeUpsCategory($pathStr)
            && ! $this->categoryNameLooksLikePduFriendlyCategory($pathStr)) {
            $pduId = $this->configuredPduCategoryId();
            throw new \RuntimeException(
                'El producto es un PDU / barra de distribución de energía para rack (no UPS/no-break), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Multicontactos (por defecto '.$pduId.', variable SYSCOM_MELI_PDU_CATEGORY_ID) o pegá '.$pduId.' en «Categoría ML» y republicá.'
            );
        }

        if ($solarCableProduct
            && ($this->categoryNameLooksLikeTabletChargerCategory($pathStr)
                || ($this->categoryNameLooksLikeElectronicsNotTire($pathStr)
                    && ! $this->categoryNameLooksLikeSolarCableCategory($pathStr)))) {
            $cableId = $this->configuredSolarCableCategoryId();
            throw new \RuntimeException(
                'El producto es un cable eléctrico para controlador/panel solar (ej. Epcom CBL-8AWG), no un cargador de tablet/celular, pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Cables para Paneles Solares (por defecto '.$cableId.', variable SYSCOM_MELI_SOLAR_CABLE_CATEGORY_ID) o pegá '.$cableId.' en «Categoría ML» y republicá.'
            );
        }

        if ($connectorProduct && ! $categoryManual
            && ($this->categoryNameLooksLikeRadioFrequencyCategory($pathStr)
                || ($this->categoryNameLooksLikeCellPhone($pathStr)
                    && ! $this->categoryNameLooksLikeElectricalConnectorCategory($pathStr)))) {
            $connectorId = $this->configuredConnectorCategoryId();
            throw new \RuntimeException(
                'El producto es un conector eléctrico / bloque push (ej. Epcom Powerline PCON), pero Mercado Libre asignó Radiofrecuencia o Celulares ('.
                $categoryId.': '.implode(' > ', $path).'). '.
                'Usá Componentes Electrónicos → Conectores (por defecto '.$connectorId.', variable SYSCOM_MELI_CONNECTOR_CATEGORY_ID) o republicá.'
            );
        }

        if ($surveillanceKitProduct && ! $categoryManual
            && $this->categoryNameLooksLikeNvrDvrOnly($pathStr)
            && ! $this->categoryNameLooksLikeKit($pathStr)
            && ! $this->categoryNameLooksLikeSurveillanceKitCategory($pathStr)) {
            throw new \RuntimeException(
                'El producto es un kit de videovigilancia (cámaras + grabador), pero ML asignó solo Grabadores DVR ('.$categoryId.': '.implode(' > ', $path).'). '.
                'Usá la categoría de kits de cámaras/seguridad (SYSCOM_MELI_KIT_VIDEO_CATEGORY_ID o «Categoría ML» manual) y republicá.'
            );
        }

        if ($solarMountProduct && ! $categoryManual
            && ($this->categoryNameLooksLikeSolarPanelCategory($pathStr)
                || $this->categoryNameLooksLikeSoftwareCategory($pathStr)
                || $this->categoryNameLooksLikeCameraMountCategory($pathStr)
                || $this->categoryNameLooksLikeNvrDvrOnly($pathStr)
                || ($this->categoryNameLooksLikeCamera($pathStr) && ! $this->categoryNameLooksLikeSolarMountFriendlyCategory($pathStr)))) {
            $mountId = $this->configuredSolarMountCategoryId();
            $reason = $this->categoryNameLooksLikeSoftwareCategory($pathStr)
                ? 'Software (hardware de montaje solar, no licencia ni red)'
                : ($this->categoryNameLooksLikeCameraMountCategory($pathStr)
                    ? 'soportes de cámara (es montaje fotovoltaico, no CCTV)'
                    : ($this->categoryNameLooksLikeNvrDvrOnly($pathStr) || $this->categoryNameLooksLikeCamera($pathStr)
                        ? 'videovigilancia/DVR (es estructura solar Epcom/riel, no cámara ni grabador)'
                        : 'Paneles Solares'));
            throw new \RuntimeException(
                'El producto es un riel/estructura de montaje solar, pero Mercado Libre asignó '.$reason.' ('.$categoryId.': '.implode(' > ', $path).'). '.
                'Usá Energía Solar → Otros (por defecto '.$mountId.', variable SYSCOM_MELI_SOLAR_MOUNT_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($flashlightProduct && ! $categoryManual
            && ($this->categoryNameLooksLikeSoftwareCategory($pathStr)
                || ! $this->categoryNameLooksLikeFlashlightFriendlyCategory($pathStr))) {
            $flashId = $this->configuredFlashlightCategoryId();
            throw new \RuntimeException(
                'El producto es una linterna (hardware), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Linternas (por defecto '.$flashId.', variable SYSCOM_MELI_FLASHLIGHT_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($standaloneCameraProduct && ! $categoryManual
            && $this->categoryNameLooksLikeNvrDvrOnly($pathStr)
            && ! $this->categoryNameLooksLikeKit($pathStr)) {
            $cameraId = $this->configuredCameraCategoryId();
            throw new \RuntimeException(
                'El producto es una cámara de videovigilancia, pero Mercado Libre asignó Grabadores DVR ('.
                $categoryId.': '.implode(' > ', $path).'). '.
                'Usá Cámaras de Seguridad (por defecto '.$cameraId.', variable SYSCOM_MELI_CAMERA_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($videoporteroProduct && ! $categoryManual
            && $this->categoryNameLooksLikeCamera($pathStr)
            && ! $this->categoryNameLooksLikeVideoIntercomCategory($pathStr)) {
            $vpId = $this->configuredVideoporteroCategoryId();
            throw new \RuntimeException(
                'El producto es un videoportero / monitor de portero eléctrico, pero Mercado Libre asignó Cámaras de Seguridad ('.
                $categoryId.': '.implode(' > ', $path).'). '.
                'Usá Porteros Eléctricos (por defecto '.$vpId.', variable SYSCOM_MELI_VIDEOPORTERO_CATEGORY_ID) o republicá.'
            );
        }

        if ($solarMeterProduct && ! $categoryManual
            && ($this->categoryNameLooksLikeSolarPanelCategory($pathStr)
                || ($this->categoryNameLooksLikeSolarMountFriendlyCategory($pathStr)
                    && ! $this->categoryNameLooksLikeSolarMeterFriendlyCategory($pathStr)))) {
            $meterId = $this->configuredSolarMeterCategoryId();
            throw new \RuntimeException(
                'El producto es un medidor inteligente / exportación cero (no panel ni inversor), pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Medidores de Energía (por defecto '.$meterId.', variable SYSCOM_MELI_SOLAR_METER_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($handToolKitProduct && ! $categoryManual
            && ($this->categoryNameLooksLikeHuntingOrSportMisleadingCategory($pathStr)
                || $this->categoryNameLooksLikeToyToolSetMisleadingCategory($pathStr)
                || ! str_contains($pathStr, 'herramient'))) {
            $toolKitId = $this->configuredToolKitCategoryId();
            throw new \RuntimeException(
                'El producto es un juego/kit de herramientas manuales, pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Kit Combinadas (por defecto '.$toolKitId.', variable SYSCOM_MELI_TOOL_KIT_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($balunProduct && ! $categoryManual
            && $this->categoryNameLooksLikeSoftwareCategory($pathStr)) {
            throw new \RuntimeException(
                'El producto es un balun/accesorio de audio-video (hardware), pero Mercado Libre asignó Software ('.$categoryId.': '.implode(' > ', $path).'). '.
                'Usá Convertidores de Audio y Video ('.$this->configuredBalunCategoryId().', variable SYSCOM_MELI_BALUN_CATEGORY_ID) o «Categoría ML» manual y republicá.'
            );
        }

        if ($balunProduct
            && $this->categoryNameLooksLikeSurveillanceKitCategory($pathStr)
            && ! $this->categoryNameLooksLikeAvConverterCategory($pathStr)) {
            $balunId = $this->configuredBalunCategoryId();
            throw new \RuntimeException(
                'El producto es un kit de baluns/transceptores (accesorio CCTV), no un kit de cámaras + grabador, pero Mercado Libre asignó '.
                implode(' > ', $path).' ('.$categoryId.'). '.
                'Usá Convertidores de Audio y Video (por defecto '.$balunId.', variable SYSCOM_MELI_BALUN_CATEGORY_ID) o pegá '.$balunId.' en «Categoría ML» y republicá.'
            );
        }
    }

    private function configuredBalunCategoryId(): string
    {
        return trim((string) config('syscom.meli_balun_category_id', 'MLM433590'));
    }

    /**
     * El campo «Categoría ML» espera un ID de categoría (ej. MLM1708), no el MLM de una publicación (ej. MLM14071161170).
     */
    private function assertManualCategoryIdIsNotListingMlm(string $categoryId): void
    {
        $categoryId = strtoupper(trim($categoryId));
        if ($categoryId === '') {
            return;
        }

        if (preg_match('/^MLM\d{10,}$/', $categoryId) === 1) {
            throw new \RuntimeException(
                '«Categoría ML» debe ser un ID de categoría (ej. MLM189825 herramientas, MLM1708 switches), no el MLM de una publicación existente ('.$categoryId.'). '.
                'Dejá el campo vacío para categoría automática o pegá el código de categoría correcto.'
            );
        }
    }

    private function configuredConnectorCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_connector_category_id', 'MLM44571'));

        return $id !== '' ? $id : 'MLM44571';
    }

    private function configuredRouterCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_router_category_id', 'MLM5015'));

        return $id !== '' ? $id : 'MLM5015';
    }

    private function configuredSolarMountCategoryId(): string
    {
        return trim((string) config('syscom.meli_solar_mount_category_id', 'MLM439043'));
    }

    private function configuredSolarCableCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_solar_cable_category_id', 'MLM455358'));

        return $id !== '' ? $id : 'MLM455358';
    }

    private function configuredSolarMeterCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_solar_meter_category_id', 'MLM189958'));

        return $id !== '' ? $id : 'MLM189958';
    }

    private function configuredToolKitCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_tool_kit_category_id', 'MLM189825'));

        return $id !== '' ? $id : 'MLM189825';
    }

    private function configuredFlashlightCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_flashlight_category_id', 'MLM47781'));

        return $id !== '' ? $id : 'MLM47781';
    }

    private function configuredSwitchCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_switch_category_id', 'MLM1708'));

        return $id !== '' ? $id : 'MLM1708';
    }

    private function configuredPoeInjectorCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_poe_injector_category_id', 'MLM190973'));

        return $id !== '' ? $id : 'MLM190973';
    }

    private function configuredAlarmCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_alarm_category_id', 'MLM168470'));

        return $id !== '' ? $id : 'MLM168470';
    }

    private function configuredPowerSupplyCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_power_supply_category_id', 'MLM420366'));

        return $id !== '' ? $id : 'MLM420366';
    }

    private function configuredPduCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_pdu_category_id', 'MLM171884'));

        return $id !== '' ? $id : 'MLM171884';
    }

    private function configuredOltCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_olt_category_id', 'MLM1711'));

        return $id !== '' ? $id : 'MLM1711';
    }

    private function configuredAntennaCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_antenna_category_id', 'MLM7642'));

        return $id !== '' ? $id : 'MLM7642';
    }

    private function configuredDashCamCategoryId(): string
    {
        // Se deja sin valor por defecto para no fijar una categoría incorrecta.
        // Si está vacío, domain_discovery resolverá la categoría vehicular.
        return trim((string) config('syscom.meli_dash_cam_category_id', ''));
    }

    private function configuredCameraCategoryId(): string
    {
        return trim((string) config('syscom.meli_camera_category_id', 'MLM437575'));
    }

    private function configuredVideoporteroCategoryId(): string
    {
        $id = trim((string) config('syscom.meli_videoportero_category_id', 'MLM437573'));

        return $id !== '' ? $id : 'MLM437573';
    }

    private function configuredKitVideoCategoryId(): string
    {
        return trim((string) config('syscom.meli_kit_videovigilancia_category_id', 'MLM417835'));
    }

    /**
     * Balun / par transceptor para audio o video analógico en CCTV (no licencias ni software).
     */
    private function productLooksLikeAudioVideoBalun(string $blobLower, string $title): bool
    {
        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(licencia|software|suscripcion|subscription|download)\b/u', $hay) === 1
            && preg_match('/\bbalun\b/u', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\b(balun|baluns|ballun|balluns)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\btransceptor\b/u', $hay) === 1
            && (preg_match('/\b(balun|baluns|ballun|balluns|cctv|videovigil|hikvision|hilook|turbo hd|turbohd)\b/u', $hay) === 1
                || preg_match('/\bbl[\-\s]?\d/ui', $hay) === 1)) {
            return true;
        }

        $keys = [
            'extensor de audio', 'transceptor de audio', 'par transceptor',
            'balun pasivo', 'balun activo', 'video balun', 'audio balun',
            'transceptor par', 'extensor audio analog', 'extensor audio análog',
        ];
        foreach ($keys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        return (str_contains($hay, 'extensor') || str_contains($hay, 'transceptor'))
            && str_contains($hay, 'audio')
            && (str_contains($hay, 'analog') || str_contains($hay, 'cctv') || str_contains($hay, 'videovigil'));
    }

    private function titleForBalunCategoryDiscovery(string $title): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $title));
        if ($t === '') {
            return '';
        }

        $t = preg_replace('/\b(software|servidor|servidores|redes y servidores)\b/iu', ' ', $t) ?? $t;

        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    private function categoryNameLooksLikeSoftwareCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'software')
            || str_contains($nameLower, 'licencia')
            || (str_contains($nameLower, 'redes') && str_contains($nameLower, 'servidor'));
    }

    private function categoryNameLooksLikeAvConverterCategory(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeSoftwareCategory($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'convertidor')
            || str_contains($nameLower, 'conector')
            || str_contains($nameLower, 'ficha')
            || (str_contains($nameLower, 'accesorio') && str_contains($nameLower, 'audio'));
    }

    /**
     * Módulo/panel solar generador (no riel, estructura ni accesorio de montaje).
     */
    private function productLooksLikeSolarPanel(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeSolarMount($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b\d{2,4}\s*w\b/u', $hay) === 1
            || preg_match('/\b\d{2,4}w\b/u', $hay) === 1) {
            return true;
        }

        $panelKeys = [
            'panel solar', 'paneles solares', 'modulo solar', 'módulo solar',
            'modulo fotovoltaico', 'módulo fotovoltaico', 'monocristalino', 'bifacial',
            'celdas solares', 'celda solar', 'photovoltaic module', 'solar panel',
        ];
        foreach ($panelKeys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        return preg_match('/\b(panel|modulo|módulo)\b.*\b(fotovolta|solar)\b/ui', $hay) === 1
            && ! preg_match('/\b(riel|montaje|estructura|abrazadera|cople|empalme)\b/ui', $hay);
    }

    /**
     * Medidor trifásico/monofásico, exportación cero, gestión de energía fotovoltaica (Hoymiles DTSU666, DTU-666, etc.).
     * No confundir con inversor, panel ni riel de montaje.
     */
    private function productLooksLikeSolarEnergyMeter(string $blobLower, string $title): bool
    {
        if ($this->productHasStrongSolarMeterSignals($blobLower, $title)) {
            return true;
        }

        if ($this->productLooksLikeSolarMount($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        return preg_match('/\bmedidor\b/u', $hay) === 1
            && (str_contains($hay, 'energ') || str_contains($hay, 'fotovolta') || str_contains($hay, 'solar')
                || str_contains($hay, 'export') || str_contains($hay, 'corriente alterna'));
    }

    private function productHasStrongSolarMeterSignals(string $blobLower, string $title): bool
    {
        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(dtsu|ddsu|dtu)\s*[-\s\/]?\d/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bhoymiles\b/u', $hay) === 1
            && (preg_match('/\b(dtsu|ddsu|dtu)\b/u', $hay) === 1
                || str_contains($hay, 'medidor') || str_contains($hay, 'export') || str_contains($hay, 'gestión')
                || str_contains($hay, 'gestion'))) {
            return true;
        }

        $meterKeys = [
            'medidor trifasico', 'medidor trifásico', 'medidor inteligente',
            'smart meter', 'smart power sensor',
            'exportación cero', 'exportacion cero', 'inyección cero', 'inyeccion cero',
            'zero export', 'net zero export',
            'gestión de energía', 'gestion de energia',
            'sistema de exportación', 'sistema de exportacion',
        ];
        foreach ($meterKeys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        return false;
    }

    private function categoryNameLooksLikeSolarMeterFriendlyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'medidor')
            && str_contains($nameLower, 'energ');
    }

    /**
     * Riel, estructura, abrazaderas y accesorios de instalación para sistemas fotovoltaicos.
     */
    private function productLooksLikeSolarOrControllerCable(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikePowerSupply($blobLower, $title)
            || $this->productLooksLikePoeInjector($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (! str_contains($hay, 'cable') && ! preg_match('/\bcbl[\-\s]/ui', $hay)) {
            return false;
        }

        if (preg_match('/\b(cargador|charger|adaptador usb|usb tipo|lightning|tablet|iphone|celular|laptop)\b/u', $hay) === 1
            && preg_match('/\b(\d+\s*awg|controlador|cbl[\-\s]|panel solar|fotovolta)\b/ui', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\bcable\s+para\s+controlador\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b\d+\s*awg\b/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bcbl[\-\s]/ui', $hay) === 1
            && preg_match('/\b(epcom|powerline|controlador|solar|fotovolta)\b/u', $hay) === 1) {
            return true;
        }

        return (str_contains($hay, 'energia solar') || str_contains($hay, 'energía solar'))
            && str_contains($hay, 'cable');
    }

    private function categoryNameLooksLikeTabletChargerCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'cargador')
            || str_contains($nameLower, 'charger')
            || (str_contains($nameLower, 'tablet') && str_contains($nameLower, 'accesorio'));
    }

    private function categoryNameLooksLikeSolarCableCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'cables para panel')
            || str_contains($nameLower, 'cables eléctric')
            || str_contains($nameLower, 'cables electric')
            || (str_contains($nameLower, 'cable') && str_contains($nameLower, 'solar'));
    }

    /**
     * Riel, estructura, abrazaderas y accesorios de instalación para sistemas fotovoltaicos.
     */
    private function productLooksLikeSolarMount(string $blobLower, string $title): bool
    {
        if ($this->productHasStrongSolarMeterSignals($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        $mountKeys = [
            'riel para', 'riel de', 'riel 7', 'riel 8', 'riel 9', 'mini riel',
            'montaje de panel', 'montaje de módulo', 'montaje de modulo',
            'montaje para panel', 'montaje para módulo', 'montaje para modulo',
            'estructura para panel', 'estructura para módulo', 'estructura para modulo',
            'estructura de montaje', 'perfil de aluminio', 'abrazadera final',
            'abrazadera medio', 'abrazadera para panel', 'cople para riel',
            'empalme para riel', 'union de riel', 'unión de riel', 'mid clamp',
            'end clamp', 'ground clip', 'soporte de panel solar',
        ];
        foreach ($mountKeys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        if (preg_match('/\briel\b/u', $hay) === 1
            && (str_contains($hay, 'solar') || str_contains($hay, 'fotovolta') || str_contains($hay, 'panel'))) {
            return true;
        }

        if (preg_match('/\b(montaje|estructura)\b/u', $hay) === 1
            && (str_contains($hay, 'fotovolta') || str_contains($hay, 'panel solar') || str_contains($hay, 'modulo'))) {
            return true;
        }

        if (preg_match('/\bvektor\w*/ui', $hay) === 1
            && (str_contains($hay, 'montaje') || str_contains($hay, 'epcom') || str_contains($hay, 'panel')
                || str_contains($hay, 'solar') || str_contains($hay, 'fotovolta') || str_contains($hay, 'riel'))) {
            return true;
        }

        if (preg_match('/\bepcom\b/u', $hay) === 1
            && preg_match('/\b(montaje|vektor|epl[\-\s]?(sr|am|mo|pswm)|pswm|riel)\b/ui', $hay) === 1
            && ! $this->productLooksLikePoeInjector($blobLower, $title)) {
            return true;
        }

        if (preg_match('/\bepl[\-\s]?(sr|am|mo|pswm)/ui', $hay) === 1) {
            return true;
        }

        $titleLower = mb_strtolower(trim($title));
        if (preg_match('/^montaje\b/u', $titleLower) === 1
            && (str_contains($hay, 'epcom') || str_contains($hay, 'fotovolta') || str_contains($hay, 'solar')
                || str_contains($hay, 'panel') || str_contains($hay, 'modulo') || str_contains($hay, 'módulo')
                || str_contains($hay, 'vektor') || str_contains($hay, 'powerline') || str_contains($hay, 'riel'))) {
            return true;
        }

        if ((str_contains($hay, 'montajes para modulos') || str_contains($hay, 'montajes para módulos')
                || str_contains($hay, 'energia solar') || str_contains($hay, 'energía solar'))
            && preg_match('/\b(montaje|riel|estructura|abrazadera|ground clip)\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\bepl[\-\s]?ar\d/ui', $hay) === 1
            || preg_match('/\briel\s*\d+\b/u', $hay) === 1;
    }

    /**
     * Linternas LED recargables o a pilas (Maglite, etc.). No luces de CCTV ni faros automotrices.
     */
    private function productLooksLikeFlashlight(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeSurveillanceCamera($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(luz|linterna|farol)\b.*\b(camara|cctv|seguridad|videovigil)\b/u', $hay) === 1) {
            return false;
        }

        if (str_contains($hay, 'automotriz') || str_contains($hay, 'automóvil') || str_contains($hay, 'automovil')
            || (str_contains($hay, 'vehiculo') && str_contains($hay, 'refaccion'))) {
            return false;
        }

        if (preg_match('/\bmaglite\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(linterna|linternas|flashlight)\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\bfarol(es)?\b/u', $hay) === 1
            && (str_contains($hay, 'led') || str_contains($hay, 'recarg') || str_contains($hay, 'camping'));
    }

    private function categoryNameLooksLikeFlashlightFriendlyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'lintern')
            || str_contains($nameLower, 'farol');
    }

    /**
     * Módulos expansores, teclados y accesorios de alarmas e intrusión (Honeywell 4219, VISTA, etc.).
     * No termostatos Honeywell Home ni lectores de código de barras.
     */
    private function productLooksLikeHomeAlarmAccessory(string $blobLower, string $title): bool
    {
        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\btermostat/u', $hay) === 1
            && preg_match('/\b(expans|alarma|intrusi|zonas?|vista|panel)\b/u', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\b(modulo|módulo|modul)\b/u', $hay) === 1
            && preg_match('/\b(expans|expansor|expander)\b/u', $hay) === 1
            && preg_match('/\b(alarma|intrusi[oó]n|zonas?|vista|panel|cablead)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(4219|4229|4232|4204|4100)\b/u', $hay) === 1
            && preg_match('/\b(honeywell|resideo|ademco)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(vista[\-\s]?\d|panel vista|central alarma|panel alarma)\b/ui', $hay) === 1) {
            return true;
        }

        if (str_contains($hay, 'automatizacion e intrusion')
            || str_contains($hay, 'automatización e intrusión')
            || str_contains($hay, 'modulos de expansion')
            || str_contains($hay, 'módulos de expansión')
            || str_contains($hay, 'modulo de expansion')
            || str_contains($hay, 'módulo de expansión')) {
            return true;
        }

        return preg_match('/\b(expansor|expander)\b/u', $hay) === 1
            && preg_match('/\b(alarma|intrusi[oó]n|zonas?)\b/u', $hay) === 1;
    }

    private function categoryNameLooksLikeHomeAlarmCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'alarmas y sensores')
            || (str_contains($nameLower, 'alarma') && str_contains($nameLower, 'sensor'))
            || str_contains($nameLower, 'sistemas de alarma')
            || str_contains($nameLower, 'intrus');
    }

    private function categoryNameLooksLikeThermostatCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'termostat')
            || (str_contains($nameLower, 'temperatura') && str_contains($nameLower, 'control'));
    }

    private function categoryNameLooksLikeBarcodeScannerCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'codigo de barras')
            || str_contains($nameLower, 'código de barras')
            || str_contains($nameLower, 'lector de codigo')
            || str_contains($nameLower, 'bar code scanner');
    }

    /**
     * Fuentes de poder conmutadas / alimentación (Epcom PLK, fuentes CCTV). No cámaras ni PC genéricas sin contexto.
     */
    private function productLooksLikePowerSupply(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikePoeInjector($blobLower, $title)
            || $this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikeRouterOnly($blobLower, $title)) {
            return false;
        }

        $titleLower = mb_strtolower($title);

        /*
         * Una cámara puede traer en su ficha técnica frases como:
         * "fuente de alimentación: 12 VDC".
         * Eso NO convierte al producto en una fuente de poder.
         *
         * Si el título identifica claramente una cámara y el propio título
         * no dice que sea una fuente/PSU, se descarta como power supply.
         */
        $titleLooksLikeCamera =
            preg_match('/\b(c[aá]mara|camera|bala\s+ip|bullet(?:\s+camera)?|domo|dome|turret|eyeball)\b/u', $titleLower) === 1
            || preg_match('/\b\d+(?:\.\d+)?\s*(?:mp|megap[ií]xel(?:es)?)\b/u', $titleLower) === 1;

        $titleExplicitlyPowerSupply =
            preg_match('/\b(fuente|fonte|power\s+supply|psu|smps)\b/u', $titleLower) === 1;

        if ($titleLooksLikeCamera && ! $titleExplicitlyPowerSupply) {
            return false;
        }

        $hay = $titleLower.' '.$blobLower;

        if (preg_match('/\b(fuente|fonte)\b/u', $hay) === 1
            && preg_match('/\b(poder|alimentaci[oó]n|power supply|conmutad|switching)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bplk[\-\s]?\d+/ui', $hay) === 1
            && preg_match('/\b(epcom|powerline|videovigil|cctv|seguridad)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(fuente|fonte)\b/u', $hay) === 1
            && preg_match('/\b(24v|12v|amp|amper|amperios?|salidas?)\b/u', $hay) === 1) {
            return true;
        }

        return str_contains($hay, 'fuentes de poder')
            || str_contains($hay, 'fuente de alimentacion')
            || str_contains($hay, 'fuente de alimentación')
            || preg_match('/\b(smps|psu)\b/u', $hay) === 1;
    }

    /**
     * Conectores eléctricos / bloques push para CCTV y alimentación (Epcom Powerline PCON, etc.).
     * No fuentes completas, cables, baluns ni conectores de red RJ45/Keystone.
     */
    private function productLooksLikeElectricalConnector(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikePowerSupply($blobLower, $title)
            || $this->productLooksLikeSolarOrControllerCable($blobLower, $title)
            || $this->productLooksLikeAudioVideoBalun($blobLower, $title)
            || $this->productLooksLikePoeInjector($blobLower, $title)
            || $this->productLooksLikeNetworkModularPlug($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\bpcon\d+/ui', $hay) === 1
            && preg_match('/\b(epcom|powerline)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bconector\b/u', $hay) === 1
            && preg_match('/\b(push|tipo push|bloque terminal|terminal block)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bconector\b/u', $hay) === 1
            && preg_match('/\b\d+\s*contactos?\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\b(bloque|distribucion|distribución)\b/u', $hay) === 1
            && preg_match('/\b(terminal|contacto|push|powerline|videovigil|cctv)\b/u', $hay) === 1;
    }

    /**
     * Jack Keystone / RJ45 / plug modular de red (categoría distinta a conectores eléctricos CCTV).
     */
    private function productLooksLikeNetworkModularPlug(string $blobLower, string $title): bool
    {
        $hay = mb_strtolower($title).' '.$blobLower;

        return preg_match('/\b(rj45|rj-45|keystone|cat5e?|cat6a?|modular plug)\b/u', $hay) === 1
            && preg_match('/\b(conector|plug|jack)\b/u', $hay) === 1;
    }

    private function categoryNameLooksLikeElectricalConnectorCategory(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeRadioFrequencyCategory($nameLower)) {
            return false;
        }

        return (str_contains($nameLower, 'componentes electr') && str_contains($nameLower, 'conector'))
            || preg_match('/\bconectores\b/u', $nameLower) === 1;
    }

    private function categoryNameLooksLikeRadioFrequencyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'radiofrecuencia')
            || str_contains($nameLower, 'radiocomunic')
            || (str_contains($nameLower, 'celulares') && str_contains($nameLower, 'telefon'));
    }

    private function categoryNameLooksLikePowerSupplyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'fuentes conmutadas')
            || (str_contains($nameLower, 'fuente') && str_contains($nameLower, 'conmutad'))
            || (str_contains($nameLower, 'fuente') && str_contains($nameLower, 'aliment'))
            || str_contains($nameLower, 'componentes electr');
    }

    /**
     * Detecta UPS / no-break con batería.
     */
    private function productLooksLikeUps(string $blob, string $title): bool
    {
        $hay = mb_strtolower($title.' '.$blob);

        // Evita confundir barras PDU y fuentes de alimentación con un UPS.
        if (preg_match(
            '/\b(pdu|power distribution unit|unidad de distribuci[oó]n|barra de distribuci[oó]n|fuente de poder|fuente conmutada)\b/u',
            $hay
        ) === 1) {
            return false;
        }

        if (preg_match(
            '/\b(no[\-\s]?break|nobreak|ups|uninterruptible power supply|sistema de alimentaci[oó]n ininterrumpida)\b/u',
            $hay
        ) === 1) {
            return true;
        }

        // Algunos productos no dicen UPS explícitamente, pero sí incluyen
        // topología interactiva, potencia VA y respaldo de batería.
        return preg_match(
            '/\b(l[ií]nea interactiva|line interactive|respaldo de energ[ií]a|respaldo de bater[ií]a)\b/u',
            $hay
        ) === 1
            && preg_match('/\b\d{3,5}\s*va\b/u', $hay) === 1;
    }

    /**
     * Categoría final de Mercado Libre México para UPS / No Breaks.
     */
    private function configuredUpsCategoryId(): string
    {
        $categoryId = strtoupper(trim((string) config(
            'syscom.meli_ups_category_id',
            env('SYSCOM_MELI_UPS_CATEGORY_ID', 'MLM1720')
        )));

        return preg_match('/^MLM\d+$/', $categoryId) === 1
            ? $categoryId
            : 'MLM1720';
    }

    /**
     * PDU / barra de distribución de energía para rack. No UPS/no-break con batería.
     */
    private function productLooksLikePdu(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikePowerSupply($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(no[\-\s]?break|ups|bater[ií]a|line interactive|standby|sin interrupci)\b/u', $hay) === 1
            && preg_match('/\bpdu\b/u', $hay) !== 1
            && preg_match('/\bpdu\d+/ui', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\bpdu\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bpdu\d+/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(distribuci[oó]n|distribucion)\b/u', $hay) === 1
            && preg_match('/\b(energ[ií]a|power)\b/u', $hay) === 1
            && preg_match('/\b(rack|tomacorriente|contactos?|1u|horizontal|servidor)\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\bpower distribution unit\b/u', $hay) === 1
            || (preg_match('/\b(cyberpower|apc)\b/u', $hay) === 1
                && preg_match('/\b(b[aá]sico|basico|rack|barra)\b/u', $hay) === 1
                && preg_match('/\b(distribuci[oó]n|distribucion|pdu)\b/u', $hay) === 1);
    }

    private function categoryNameLooksLikeUpsCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'no break')
            || str_contains($nameLower, 'nobreak')
            || str_contains($nameLower, 'no-break')
            || (str_contains($nameLower, 'ups') && ! str_contains($nameLower, 'multicontact'));
    }

    private function categoryNameLooksLikePduFriendlyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'multicontact')
            || str_contains($nameLower, 'regleta')
            || (str_contains($nameLower, 'distrib') && str_contains($nameLower, 'energ'))
            || str_contains($nameLower, 'gabinetes para servidores');
    }

    private function categoryNameLooksLikeCameraMountCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'montura')
            || str_contains($nameLower, 'cabezal')
            || (str_contains($nameLower, 'soporte') && str_contains($nameLower, 'cámara'))
            || (str_contains($nameLower, 'soporte') && str_contains($nameLower, 'camara'));
    }

    private function titleForSolarMountCategoryDiscovery(string $title): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $title));
        $t = preg_replace('/\b(m[oó]dulos?\s+fotovoltaicos?)\b/ui', 'montaje solar', $t) ?? $t;
        $t = preg_replace('/\b(panel(?:es)?\s+solares?)\b/ui', 'accesorio instalacion solar', $t) ?? $t;

        return trim($t);
    }

    private function categoryNameLooksLikeSolarPanelCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'panel solar')
            || str_contains($nameLower, 'paneles solares');
    }

    private function categoryNameLooksLikeVehicleSunroofRiel(string $nameLower): bool
    {
        return str_contains($nameLower, 'techo solar')
            || str_contains($nameLower, 'sunroof')
            || (str_contains($nameLower, 'riel') && str_contains($nameLower, 'veh'));
    }

    private function categoryNameLooksLikeSolarMountFriendlyCategory(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeSolarPanelCategory($nameLower)
            || $this->categoryNameLooksLikeVehicleSunroofRiel($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'otros')
            || str_contains($nameLower, 'cables para panel')
            || str_contains($nameLower, 'accesorio');
    }

    private function categoryNameLooksLikeElectronicsNotTire(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeTire($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'electronic')
            || str_contains($nameLower, 'cable')
            || str_contains($nameLower, 'computo')
            || str_contains($nameLower, 'celular');
    }

    private function productLooksLikeSurveillanceCamera(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeTire($title, '', $blobLower)) {
            return false;
        }

        if ($this->productLooksLikeVideoIntercom($blobLower, $title)) {
            return false;
        }

        if ($this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikeRouterOnly($blobLower, $title)
            || $this->productLooksLikeOlt($blobLower, $title)
            || $this->productLooksLikePoeInjector($blobLower, $title)) {
            return false;
        }

        $keys = [
            'camara de vigilancia', 'cámara de vigilancia', 'camara ip', 'cámara ip', 'cctv', 'videovigil',
            'turret', 'bullet', 'domo', 'dome cam', 'hikvision', 'dahua', '8mp', '4k turret', 'ip camera',
            'cámara de seguridad', 'camara de seguridad', 'eyeball', 'turbohd', 'turbo hd', 'megapixel',
            'megapixeles', 'min domo', 'coaxitron',
        ];
        foreach ($keys as $k) {
            if (str_contains($blobLower, $k)) {
                return true;
            }
        }

        return preg_match('/\b(camara|cámara)\b.*\b(ip|wifi|4k|8mp|vigilancia)\b/ui', $blobLower) === 1
            || preg_match('/\b(ip|wifi)\b.*\b(camara|cámara)\b/ui', $blobLower) === 1
            || preg_match('/\b(tvi|ahd|cvi|cvbs)\b/u', $blobLower) === 1
            || preg_match('/\bturbohd\b/u', $blobLower) === 1
            || preg_match('/\bds-2c[dpte]/ui', $blobLower) === 1;
    }

    /**
     * Videoporteros, monitores de portero eléctrico e interfonos (Hikvision DS-KH, etc.). No son cámaras CCTV.
     */
    private function productLooksLikeVideoIntercom(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikeRouterOnly($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\bds-kh[\-\w]*/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(videoportero|video portero|portero electrico|portero eléctrico|portero electricos|portero eléctricos)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(intercomunicador|interfono|interfón|interfon)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bmonitor\b/u', $hay) === 1
            && preg_match('/\b(videoportero|portero|intercom)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(placa|panel|estacion|estación)\b/u', $hay) === 1
            && preg_match('/\b(videoportero|portero|intercom|citofono|citófono)\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\bkh\d{3,5}\b/ui', $hay) === 1
            && preg_match('/\b(hikvision|hilook|ds-kh)\b/ui', $hay) === 1;
    }

    private function categoryNameLooksLikeVideoIntercomCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'portero')
            || str_contains($nameLower, 'porteros')
            || str_contains($nameLower, 'videoportero')
            || str_contains($nameLower, 'intercom')
            || str_contains($nameLower, 'interfono')
            || str_contains($nameLower, 'citofono')
            || str_contains($nameLower, 'citófono');
    }

    private function productLooksLikeDashCam(string $blobLower, string $title): bool
    {
        $hay = mb_strtolower(trim($title).' '.$blobLower);

        $strong = preg_match('/\b(dash[\s-]?cam|dashcam|camara de tablero|cámara de tablero|camara vehicular|cámara vehicular|camara para vehiculo|cámara para vehículo|camara movil.*vehiculo|cámara móvil.*vehículo|mobile dvr|mdvr)\b/u', $hay) === 1;
        if ($strong) {
            return true;
        }

        $vehicleSignal = preg_match('/\b(vehiculo|vehículo|vehicular|automovil|automóvil|auto|carro|camion|camión|autobus|autobús|tablero|parabrisas|conductor|adas|dsm)\b/u', $hay) === 1;
        $cameraOrRecorder = preg_match('/\b(camara|cámara|grabador|grabadora|dvr|video)\b/u', $hay) === 1;
        $fixedCctv = preg_match('/\b(cctv|turbohd|colorvu|acusense|camara fija|cámara fija|kit de seguridad|rack|nvr de red)\b/u', $hay) === 1;

        return $vehicleSignal && $cameraOrRecorder && ! $fixedCctv;
    }

    private function productLooksLikeNvrDvr(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeDashCam($blobLower, $title)) {
            return false;
        }
        if ($this->productHasStrongSolarMeterSignals($blobLower, $title)
            || $this->productLooksLikeSolarMount($blobLower, $title)
            || $this->productLooksLikeSurveillanceCamera($blobLower, $title)) {
            return false;
        }

        if ($this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikePoeInjector($blobLower, $title)
            || $this->productLooksLikeOlt($blobLower, $title)) {
            return false;
        }

        $titleLower = mb_strtolower(trim($title));

        /*
         * Un router puede mencionar NVR/DVR en descripción, aplicaciones
         * o compatibilidades. Si el TÍTULO identifica explícitamente al
         * producto como router/enrutador, no debe clasificarse como NVR/DVR.
         */
        if (preg_match('/\b(router|enrutador)\b/u', $titleLower) === 1
            || str_contains($titleLower, 'router wifi')
            || str_contains($titleLower, 'router wi-fi')
            || str_contains($titleLower, 'router inal')) {
            return false;
        }

        if (preg_match('/\b(nvr|dvr)\b/u', $titleLower) === 1) {
            return true;
        }

        if (str_contains($titleLower, 'videograbador')
            || str_contains($titleLower, 'grabador digital')
            || (str_contains($titleLower, 'grabador') && ! preg_match('/\b(camara|cámara)\b/u', $titleLower))) {
            return true;
        }

        $modelHay = '';
        if (preg_match('/\bmodelo[:\s]+([^\n|]+)/u', $blobLower, $m)) {
            $modelHay = mb_strtolower(trim($m[1]));
        }

        if ($modelHay !== ''
            && (preg_match('/\b(nvr|dvr)\b/u', $modelHay) === 1 || str_contains($modelHay, 'videograbador'))) {
            return true;
        }

        return preg_match('/\b(nvr|dvr)\b/u', $blobLower) === 1
            && preg_match('/\b(camara|cámara)\b/u', $blobLower) !== 1
            && preg_match('/\b(hikvision|dahua|ds-2c|colorvu|turret|bullet|eyeball)\b/u', $blobLower) !== 1;
    }

    private function productLooksLikeKit(string $blobLower, string $title): bool
    {
        $hay = $blobLower.' '.mb_strtolower($title);

        return str_contains($hay, ' kit')
            || str_contains($hay, 'kit ')
            || str_contains($hay, 'combo')
            || preg_match('/\bpack\b/u', $hay) === 1;
    }

    /**
     * Maletín/juego con llaves, desarmadores, pinzas, etc. (no kits de videovigilancia ni juguetes).
     */
    private function productLooksLikeHandToolKit(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeNvrDvr($blobLower, $title)
            || $this->productLooksLikeSurveillanceCamera($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        foreach (['videovigil', 'cctv', 'cámara', 'camara', 'dvr', 'nvr', 'videograbador'] as $k) {
            if (str_contains($hay, $k)) {
                return false;
            }
        }

        $toolKitKeys = [
            'juego de herramientas', 'juego herramientas', 'kit de herramientas',
            'set de herramientas', 'tool set', 'mechanics tool set', "mechanic's tool set",
            'kits de herramientas', 'juego completo de herramientas', 'caja de herramientas',
            'bolsa portaherramient', 'portaherramient', 'porta herramient', 'tool bag',
            'bolsa de herramient', 'organizador de herramient', 'funda para herramient',
            'cinturon portaherramient', 'cinturón portaherramient', 'billetera de herramient',
        ];
        foreach ($toolKitKeys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        if (preg_match('/\b(maletin|maletín|bolsa|cinturon|cinturón|funda)\b/u', $hay) === 1
            && preg_match('/\bherramient/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(juego|kit|set)\b/u', $hay) === 1
            && preg_match('/\bherramient/u', $hay) === 1
            && preg_match('/\b\d+\s*(pcs|piezas|pzas|pc\.?)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b\d+\s*(pcs|piezas|pzas)\b/u', $hay) === 1
            && (str_contains($hay, 'desarmador') || str_contains($hay, 'llave') || str_contains($hay, 'pinza')
                || str_contains($hay, 'dado') || str_contains($hay, 'matraca') || str_contains($hay, 'maletin')
                || str_contains($hay, 'maletín'))) {
            return true;
        }

        return str_contains($hay, 'herramientas manuales')
            && ($this->categoryNameLooksLikeKit($hay) || str_contains($hay, 'juego'));
    }

    private function categoryNameLooksLikeHandToolKitFriendlyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'herramient')
            && ($this->categoryNameLooksLikeKit($nameLower)
                || str_contains($nameLower, 'combinad')
                || str_contains($nameLower, 'desarmador')
                || str_contains($nameLower, 'llave')
                || str_contains($nameLower, 'pinza')
                || str_contains($nameLower, 'manual'));
    }

    private function categoryNameLooksLikeHuntingOrSportMisleadingCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'caza')
            || str_contains($nameLower, 'pesca')
            || (str_contains($nameLower, 'camping') && str_contains($nameLower, 'repuesto'))
            || (str_contains($nameLower, 'deportes') && str_contains($nameLower, 'caza'));
    }

    private function categoryNameLooksLikeToyToolSetMisleadingCategory(string $nameLower): bool
    {
        return (str_contains($nameLower, 'juegos') || str_contains($nameLower, 'juguetes'))
            && ! str_contains($nameLower, 'herramient');
    }

    private function categoryNameLooksLikeKit(string $nameLower): bool
    {
        return str_contains($nameLower, 'kit')
            || str_contains($nameLower, 'paquete')
            || str_contains($nameLower, 'combo');
    }

    /**
     * Kits de cámaras + grabador (no la hoja "Grabadores DVR" sin "kit" en el nombre).
     */
    private function categoryNameLooksLikeSurveillanceKitCategory(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeNvrDvrOnly($nameLower) && ! $this->categoryNameLooksLikeKit($nameLower)) {
            return false;
        }

        if (! $this->categoryNameLooksLikeKit($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'videovigil')
            || str_contains($nameLower, 'vigilancia')
            || str_contains($nameLower, 'cctv')
            || str_contains($nameLower, 'cámara')
            || str_contains($nameLower, 'camara')
            || str_contains($nameLower, 'seguridad')
            || str_contains($nameLower, 'monitoreo');
    }

    private function categoryNameLooksLikeCamera(string $nameLower): bool
    {
        return str_contains($nameLower, 'cámara')
            || str_contains($nameLower, 'camara')
            || str_contains($nameLower, 'cctv')
            || str_contains($nameLower, 'videovigil')
            || (str_contains($nameLower, 'vigilancia') && ! str_contains($nameLower, 'kit'));
    }

    private function categoryNameLooksLikeNvrDvrOnly(string $nameLower): bool
    {
        return str_contains($nameLower, 'nvr')
            || str_contains($nameLower, 'dvr')
            || str_contains($nameLower, 'grabador')
            || str_contains($nameLower, 'videograbador');
    }

    /**
     * OLT / terminal de línea óptica GPON-EPON (lado central/ISP). No ONU/ONT del abonado.
     */
    private function productLooksLikeOlt(string $blobLower, string $title): bool
    {
        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(onu|ont)\b/u', $hay) === 1
            && preg_match('/\bolt\b/u', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\b(router|modem|wifi|inalambric|inalámbric)\b/u', $hay) === 1
            && preg_match('/\bolt\b/u', $hay) !== 1
            && ! str_contains($hay, 'deltastream')
            && ! str_contains($hay, 'delta stream')) {
            return false;
        }

        if (preg_match('/\bolt\b/u', $hay) === 1) {
            return true;
        }

        return str_contains($hay, 'optical line terminal')
            || str_contains($hay, 'terminal de linea optica')
            || str_contains($hay, 'terminal de línea óptica')
            || str_contains($hay, 'terminal de linea óptica')
            || preg_match('/\buf-olt\b/u', $hay) === 1
            || ((str_contains($hay, 'deltastream') || str_contains($hay, 'delta stream'))
                && str_contains($hay, 'gpon'));
    }

    private function productLooksLikeSwitch(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeOlt($blobLower, $title)
            || $this->productLooksLikePoeInjector($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(nvr|dvr)\b/u', $hay) === 1
            || preg_match('/\b(videograbador|grabador digital|network video recorder)\b/u', $hay) === 1
            || preg_match('/\bds-7[0-9]{3}/ui', $hay) === 1) {
            return false;
        }

        if (preg_match('/\bswitch\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bds-3e/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bpoe\+?\b/u', $hay) === 1
            && (preg_match('/\b(gigabit|10\/100|10\/100\/1000|no administrable|unmanaged|administrable|manageable|managed|gestionable)\b/u', $hay) === 1
                || preg_match('/\b\d+\s*(puertos?|ports?)\b/u', $hay) === 1)) {
            return true;
        }

        if (preg_match('/\b(administrable|manageable|managed|gestionable)\b/u', $hay) === 1
            && preg_match('/\b\d+\s*(puertos?|ports?)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\brg-es[0-9a-z\-]+/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(ruijie|reyee)\b/ui', $hay) === 1
            && preg_match('/\b(es|rg|ws)[\-]?\d{2,4}[a-z0-9\-]*gc\b/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(usw-|tl-sg|gs110tp|sg[0-9]{3,4}|cbs[0-9]{3,4}|es[0-9]{2,4}gc)\b/ui', $hay) === 1
            && preg_match('/\b(poe|gigabit|puertos?|ports?|switch|ruijie|ubiquiti|tp-link|tplink|cisco|netgear|hikvision)\b/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(ruijie|tp-link|tplink|ubiquiti|cisco catalyst|netgear|d-link)\b/ui', $hay) === 1
            && preg_match('/\bpoe\+?\b/u', $hay) === 1
            && preg_match('/\b(puertos?|ports?|gigabit|10\/100\/1000|layer\s*[23]|capa\s*[23])\b/u', $hay) === 1) {
            return true;
        }

        return str_contains($hay, 'switch de red')
            || str_contains($hay, 'switch de acceso')
            || (str_contains($hay, 'no administrable') && str_contains($hay, 'puerto'));
    }

    /**
     * Adaptador/inyector PoE de pared o midspan (no switch administrable ni montaje solar).
     */
    private function productLooksLikePoeInjector(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeOlt($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\b(switch|router)\b/u', $hay) === 1
            && preg_match('/\binyector\b/u', $hay) !== 1
            && preg_match('/\binjector\b/u', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\badpoe\d+/ui', $hay) === 1) {
            return true;
        }

        if ((preg_match('/\binyector\b/u', $hay) === 1 || preg_match('/\binjector\b/u', $hay) === 1)
            && preg_match('/\bpoe\+?\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\bpoe\b/u', $hay) === 1
            && (preg_match('/\b(adaptador|inyector|injector|inject|midspan|inyecci[oó]n)\b/u', $hay) === 1
                || str_contains($hay, 'de pared'));
    }

    private function categoryNameLooksLikePoeInjectorFriendlyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'inyector')
            || str_contains($nameLower, 'injector')
            || (str_contains($nameLower, 'poe') && str_contains($nameLower, 'red'));
    }

    private function productLooksLikeRouterOnly(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikeOlt($blobLower, $title)
            || $this->productLooksLikeWirelessAntenna($blobLower, $title)) {
            return false;
        }

        $hay = mb_strtolower($title).' '.$blobLower;

        if (preg_match('/\brouter\b/u', $hay) === 1
            || str_contains($hay, 'enrutador')
            || str_contains($hay, 'router wifi')
            || str_contains($hay, 'router inal')
            || preg_match('/\brepetidor de ont\b/u', $hay) === 1
            || preg_match('/\bmodem router\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(ont|xgpon|egpon|gpon)\b/u', $hay) === 1
            && preg_match('/\b(router|wifi|wi-fi|repetidor|banda dual|dual band)\b/u', $hay) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Antenas Wi‑Fi / enlaces inalámbricos sueltas (Mikrotik MTAD/MANT, Ubiquiti dish, etc.). No routers ni AP completos.
     */
    private function productLooksLikeWirelessAntenna(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeSwitch($blobLower, $title)
            || $this->productLooksLikeOlt($blobLower, $title)) {
            return false;
        }

        $titleLower = mb_strtolower($title);
        $hay = $titleLower.' '.$blobLower;

        if (preg_match('/\b(router|enrutador)\b/u', $hay) === 1
            && preg_match('/\b(antena|antenas|antenna|antennas)\b/u', $titleLower) !== 1
            && preg_match('/\b(mtad|mant\d)\b/ui', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\b(access point|punto de acceso|punto acceso)\b/u', $hay) === 1
            && preg_match('/\b(antena|antenas|antenna|antennas)\b/u', $titleLower) !== 1
            && preg_match('/\b(mtad|mant\d|litebeam|powerbeam|nanobeam)\b/ui', $hay) !== 1) {
            return false;
        }

        if (preg_match('/\b(antena|antenas|antenna|antennas)\b/u', $titleLower) === 1) {
            return true;
        }

        if (preg_match('/\b(antena direccional|antena omnidireccional|antena sectorial|antena parab|antena yagi|antena wifi|antena inal)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bmtad[\-\s]/ui', $hay) === 1) {
            return true;
        }

        if (preg_match('/\bmant\d/ui', $hay) === 1
            && preg_match('/\b(mikrotik|antena|dish|direccional|5ghz|5\.8|2\.4ghz|wireless|inalambric|inalámbric)\b/u', $hay) === 1) {
            return true;
        }

        if (preg_match('/\b(litebeam|powerbeam|nanobeam|airgrid|isostation|hornet|symmetrise|twistport|sxt|acprism)\b/ui', $hay) === 1
            && preg_match('/\b(antena|antenna|direccional|sectorial|dish|5ghz|5\.8|2\.4|inalambric|inalámbric)\b/u', $hay) === 1) {
            return true;
        }

        return preg_match('/\b(radome|reflector|parabolica|parabólica|yagi)\b/u', $hay) === 1
            && preg_match('/\b(antena|antenna|wifi|5ghz|5\.8|2\.4|inalambric|inalámbric|mikrotik|ubiquiti|tp-link|cambium)\b/u', $hay) === 1;
    }

    private function categoryNameLooksLikeAntennaCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'antena')
            || str_contains($nameLower, 'antenas')
            || str_contains($nameLower, 'antenna')
            || str_contains($nameLower, 'antennas');
    }

    private function categoryNameLooksLikeSwitchCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'switch')
            || str_contains($nameLower, 'switches')
            || str_contains($nameLower, 'interruptor')
            || str_contains($nameLower, 'interruptores')
            || (str_contains($nameLower, 'hub') && str_contains($nameLower, 'switch'));
    }

    private function categoryNameLooksLikeUsbHubCategory(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeSwitchCategory($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'hub usb')
            || str_contains($nameLower, 'hubs usb')
            || (str_contains($nameLower, 'hub') && str_contains($nameLower, 'usb'));
    }

    private function categoryNameLooksLikeRouterCategory(string $nameLower): bool
    {
        if ($this->categoryNameLooksLikeSwitchCategory($nameLower)) {
            return false;
        }

        return str_contains($nameLower, 'router')
            || str_contains($nameLower, 'routers')
            || str_contains($nameLower, 'enrutador');
    }

    private function categoryNameLooksLikeModemCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'modem')
            || str_contains($nameLower, 'modems')
            || str_contains($nameLower, 'módem')
            || str_contains($nameLower, 'módems');
    }

    private function categoryNameLooksLikeOltFriendlyCategory(string $nameLower): bool
    {
        return str_contains($nameLower, 'otros')
            && (str_contains($nameLower, 'conectividad')
                || str_contains($nameLower, 'redes')
                || str_contains($nameLower, 'networking'));
    }

    private function categoryPathLooksLikeOltFriendly(string $pathStr, string $categoryId): bool
    {
        if ($categoryId === $this->configuredOltCategoryId()) {
            return true;
        }

        return $this->categoryNameLooksLikeOltFriendlyCategory($pathStr);
    }

    private function productLooksLikeNetworkingEquipment(string $blobLower, string $title): bool
    {
        if ($this->productLooksLikeWirelessAntenna($blobLower, $title)) {
            return false;
        }

        if ($this->productLooksLikeSwitch($blobLower, $title) || $this->productLooksLikeRouterOnly($blobLower, $title)) {
            return true;
        }

        $hay = $blobLower.' '.mb_strtolower($title);

        $keys = [
            'switch', 'router', 'access point', 'punto de acceso', 'punto acceso',
            'firewall', 'gateway', 'gigabit', 'poe', 'sfp', 'ethernet', 'rj45',
            'administrable', 'manageable', 'capa 2', 'layer 2', 'layer2', 'l2 switch',
            'l3 switch', 'hub ', ' hub', 'mesh wifi', 'access point', 'wifi 6', 'wi-fi 6',
            'redes', 'networking', 'switch de red', 'switch de acceso', 'unifi', 'mikrotik',
            'cisco catalyst', 'tp-link', 'tplink', 'aruba', 'ruckus', 'fortigate',
        ];
        foreach ($keys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        return preg_match('/\bswitch\b/u', $hay) === 1
            || preg_match('/\brouter\b/u', $hay) === 1
            || preg_match('/\b\d+p\d+[gjx]?\b/u', $hay) === 1;
    }

    private function productLooksLikeCellPhone(string $blobLower, string $title): bool
    {
        $hay = $blobLower.' '.mb_strtolower($title);

        if (preg_match('/\b(switch|router|access point|punto de acceso|firewall|gigabit|poe|sfp|ethernet)\b/ui', $hay) === 1) {
            return false;
        }

        $keys = [
            'smartphone', 'celular', 'teléfono', 'telefono', 'iphone', 'android',
            'dual sim', 'pantalla oled', 'mpx camara frontal', 'mah bateria',
            'samsung galaxy', 'redmi', 'motorola edge',
        ];
        foreach ($keys as $k) {
            if (str_contains($hay, $k)) {
                return true;
            }
        }

        return preg_match('/\b(gsm|lte|5g)\b.*\b(celular|smartphone)\b/ui', $hay) === 1;
    }

    private function categoryNameLooksLikeNetworking(string $nameLower): bool
    {
        return str_contains($nameLower, 'switch')
            || str_contains($nameLower, 'router')
            || str_contains($nameLower, 'redes')
            || str_contains($nameLower, 'networking')
            || str_contains($nameLower, 'punto de acceso')
            || str_contains($nameLower, 'access point')
            || str_contains($nameLower, 'firewall')
            || str_contains($nameLower, 'hub')
            || (str_contains($nameLower, 'conectividad') && str_contains($nameLower, 'red'));
    }

    private function categoryNameLooksLikeCellPhone(string $nameLower): bool
    {
        return str_contains($nameLower, 'celular')
            || str_contains($nameLower, 'smartphone')
            || str_contains($nameLower, 'telefon')
            || str_contains($nameLower, 'teléfon')
            || (str_contains($nameLower, 'telefonía') && str_contains($nameLower, 'celular'));
    }

    private function isMeliAttributeNumberFormatError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'number_invalid_format')
            || str_contains($m, 'was omitted. The provided number is not valid');
    }

    private function isMeliAttributeNormalizableUnitError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'item.attributes.normalizable.invalid')
            || str_contains($m, 'was omitted. The provided unit is not valid');
    }

    /**
     * ML exige número + unidad válida (ej. "2.8 mm"), sin texto extra del datasheet SYSCOM.
     *
     * @param  array<int, array<string, mixed>>  $attrs
     * @param  array<int, array<string, mixed>>  $catAttrs
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeNumericAttributesForMeli(array $attrs, array $catAttrs): array
    {
        $catById = [];
        foreach ($catAttrs as $ca) {
            if (! is_array($ca)) {
                continue;
            }
            $aid = strtoupper(trim((string) ($ca['id'] ?? '')));
            if ($aid !== '') {
                $catById[$aid] = $ca;
            }
        }

        $out = [];
        foreach ($attrs as $a) {
            if (! is_array($a)) {
                continue;
            }
            $aid = strtoupper(trim((string) ($a['id'] ?? '')));
            $def = $catById[$aid] ?? null;
            $vname = trim((string) ($a['value_name'] ?? ''));
            if ($def !== null && in_array((string) ($def['value_type'] ?? ''), ['number_unit', 'number'], true)) {
                $formatted = $this->formatAttributeValueForMeli($def, $vname);
                if ($formatted === null && (string) ($def['value_type'] ?? '') === 'number_unit') {
                    $formatted = $this->formatBareNumberWithDefaultUnit($def, $vname);
                }
                if ($formatted === null) {
                    continue;
                }
                $a['value_name'] = $formatted;
            }
            $out[] = $a;
        }

        return $this->dedupeAttributes($out);
    }

    /**
     * @param  array<string, mixed>  $attr
     */
    private function formatAttributeValueForMeli(array $attr, string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        $type = (string) ($attr['value_type'] ?? '');
        if ($type === 'number') {
            if (preg_match('/^(\d+(?:[.,]\d+)?)/u', $value, $m)) {
                return str_replace(',', '.', $m[1]);
            }

            return null;
        }

        if ($type === 'number_unit' || $type === 'string_unit') {
            $normalized = $this->normalizeMeliNumberUnitValue($value);
            if ($normalized !== null) {
                return $normalized;
            }

            return $this->formatBareNumberWithDefaultUnit($attr, $value);
        }

        return Str::limit($value, 120, '');
    }

    /**
     * Cuando ML exige number_unit pero solo hay un número (ej. RMS_POWER = "1"), agrega unidad válida (ej. "1 W").
     *
     * @param  array<string, mixed>  $attr
     */
    private function formatBareNumberWithDefaultUnit(array $attr, string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(\d+(?:[.,]\d+)?)/u', $value, $m)) {
            return null;
        }

        $num = str_replace(',', '.', $m[1]);
        $unit = $this->defaultMeliUnitForAttribute($attr);
        if ($unit === null || $unit === '') {
            return null;
        }

        return $num.' '.$unit;
    }

    /**
     * Unidad por defecto según allowed_units de la categoría ML o el id del atributo (RMS_POWER → W).
     *
     * @param  array<string, mixed>  $attr
     */
    private function defaultMeliUnitForAttribute(array $attr): ?string
    {
        $id = strtoupper(trim((string) ($attr['id'] ?? '')));
        $allowed = [];

        foreach (is_array($attr['allowed_units'] ?? null) ? $attr['allowed_units'] : [] as $u) {
            if (is_array($u)) {
                $uid = trim((string) ($u['id'] ?? $u['name'] ?? ''));
            } else {
                $uid = trim((string) $u);
            }
            if ($uid !== '') {
                $allowed[] = $uid;
            }
        }

        if ($allowed !== []) {
            $preferred = $this->preferredMeliUnitForAttributeId($id, $allowed);

            return $preferred ?? $allowed[0];
        }

        return $this->fallbackMeliUnitByAttributeId($id);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function preferredMeliUnitForAttributeId(string $id, array $allowed): ?string
    {
        $prefs = [];
        if (str_contains($id, 'POWER') || str_contains($id, 'RMS') || str_contains($id, 'WATT')) {
            $prefs = ['W', 'kW', 'mW', 'hp', 'VA', 'BTU', 'kcal/h', 'Cal/h'];
        } elseif (str_contains($id, 'VOLT')) {
            $prefs = ['V', 'kV', 'mV'];
        } elseif (str_contains($id, 'WEIGHT') || str_contains($id, 'MASS')) {
            $prefs = ['kg', 'g'];
        } elseif (str_contains($id, 'LENGTH') || str_contains($id, 'HEIGHT') || str_contains($id, 'WIDTH') || str_contains($id, 'DIAM')) {
            $prefs = ['cm', 'mm', 'm', 'in', 'pulgadas'];
        }

        foreach ($prefs as $p) {
            foreach ($allowed as $a) {
                if (strcasecmp($a, $p) === 0) {
                    return $a;
                }
            }
        }

        return null;
    }

    private function fallbackMeliUnitByAttributeId(string $id): ?string
    {
        if (str_contains($id, 'POWER') || str_contains($id, 'RMS') || str_contains($id, 'WATT')) {
            return 'W';
        }
        if (str_contains($id, 'VOLT')) {
            return 'V';
        }

        return null;
    }

    /**
     * Extrae "2.8 mm" o "100 W" de textos del datasheet SYSCOM.
     */
    private function normalizeMeliNumberUnitValue(string $raw): ?string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));
        if ($raw === '') {
            return null;
        }

        $units = [
            'mm', 'cm', 'm', 'km', 'yd', 'ft', 'in', '"', 'µm', 'μm', 'nm', 'mil', 'manos', 'millas', 'U', 'pulgadas',
            'mW', 'kW', 'W', 'hp', 'VA', 'cv', 'TR', 'fg', 'BTU/s', 'kcal/h', 'Cal/h', 'BTU',
        ];
        $unitPattern = implode('|', array_map(
            static fn (string $u): string => preg_quote($u, '#'),
            $units
        ));

        // Delimitador # (no /): unidades como BTU/s y Cal/h rompen regex con /.
        if (preg_match('#^(\d+(?:[.,]\d+)?)\s*('.$unitPattern.')\b#iu', $raw, $m)) {
            $num = str_replace(',', '.', $m[1]);
            $unit = $this->canonicalMeliMeasureUnit(mb_strtolower($m[2]));

            return $num.' '.$unit;
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(watts?|vatios?)\b/iu', $raw, $m)) {
            return str_replace(',', '.', $m[1]).' W';
        }

        return null;
    }

    private function canonicalMeliMeasureUnit(string $unit): string
    {
        return match ($unit) {
            'pulgadas' => 'pulgadas',
            'u' => 'U',
            'μm' => 'µm',
            'w', 'watt', 'watts', 'vatio', 'vatios' => 'W',
            'kw', 'kilowatt', 'kilowatts' => 'kW',
            'mw', 'milliwatt', 'milliwatts' => 'mW',
            'btu/s' => 'BTU/s',
            'kcal/h' => 'kcal/h',
            'cal/h' => 'Cal/h',
            'btu' => 'BTU',
            default => $unit,
        };
    }

    private function isMeliPictureSizeError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'item.pictures')
            && (str_contains($m, 'invalid_size') || str_contains($m, 'pictures.invalid'));
    }

    private function isMissingConditionalGtinError(\RuntimeException $e): bool
    {
        $m = $e->getMessage();
        if ($m === '') {
            return false;
        }

        return str_contains($m, 'item.attribute.missing_conditional_required')
            && str_contains($m, 'GTIN');
    }

    /**
     * @param  array<string, mixed>  $attr
     * @return array<string, mixed>|null
     */
    private function fillAttribute(
        array $attr,
        SyscomProduct $product,
        string $sku,
        bool $isUserProductSeller,
        array $facts = [],
        bool $allowDefaultFallback = true
    ): ?array
    {
        $id = strtoupper((string) ($attr['id'] ?? ''));

        if ($id === 'NAME' && $isUserProductSeller) {
            $t = Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($product->titulo ?: 'Producto'))), 60, '');

            return ['id' => 'NAME', 'value_name' => $t];
        }

        $values = is_array($attr['values'] ?? null) ? $attr['values'] : [];

        $marca = Str::limit(trim((string) ($product->marca ?: 'Genérico')), 60, '');
        $modelo = Str::limit(trim((string) ($product->modelo ?: $product->titulo ?: '—')), 60, '');

        if ($id === 'BRAND' || $id === 'BRANDS') {
            return ['id' => $id, 'value_name' => $marca];
        }

        if (in_array($id, ['MODEL', 'ALPHANUMERIC_MODEL', 'PART_NUMBER', 'MPN'], true)) {
            return ['id' => $id, 'value_name' => $modelo];
        }

        if ($id === 'FIBERS_NUMBER') {
            return $this->fillFibersNumberAttribute($attr, $product, $facts);
        }

        if ($id === 'COLOR' || $id === 'COLOUR') {
            $detectedColor = $this->detectColorValue($product);
            if ($detectedColor !== null && $detectedColor !== '') {
                $pick = $this->pickValueIdByName($values, [$detectedColor]);
                if ($pick !== null) {
                    return ['id' => $id, 'value_id' => $pick['id'], 'value_name' => $pick['name']];
                }

                return ['id' => $id, 'value_name' => $detectedColor];
            }
        }

        if ($id === 'SELLER_SKU') {
            return ['id' => 'SELLER_SKU', 'value_name' => Str::limit($sku, 120, '')];
        }

        if ($id === 'GTIN' || $id === 'EAN' || $id === 'UPC') {
            $code = $this->extractBarcodeDigitsFromSyscomProduct($product);
            if ($code !== null && $code !== '') {
                return ['id' => $id, 'value_name' => $code];
            }

            return null;
        }

        if ($id === 'EMPTY_EAN_REASON' || $id === 'EMPTY_GTIN_REASON') {
            if ($this->extractBarcodeDigitsFromSyscomProduct($product) !== null) {
                return null;
            }

            $pick = $this->pickValueIdByName($values, [
                'Otra razón', 'Otra', 'no tiene código', 'sin código', 'kit', 'artesanal',
            ]);
            if ($pick !== null) {
                return ['id' => $id, 'value_id' => $pick['id'], 'value_name' => $pick['name']];
            }

            $name = $this->firstValueNameMatching($values, ['Otra', 'Otro', 'no', 'N/A', 'Razón']);
            if ($name !== null) {
                return ['id' => $id, 'value_name' => $name];
            }

            if ($values !== []) {
                $first = (string) ($values[0]['name'] ?? '');

                return $first !== '' ? ['id' => $id, 'value_name' => $first] : null;
            }

            return ['id' => $id, 'value_name' => 'Otra razón'];
        }

        if (str_contains($id, 'ITEM_CONDITION') || $id === 'CONDITION') {
            $pick = $this->pickValueIdByName($values, ['Nuevo', 'Nueva', 'new']);
            if ($pick !== null) {
                return ['id' => $id, 'value_id' => $pick['id'], 'value_name' => $pick['name']];
            }
        }

        if (str_contains($id, 'PACKAGE') || str_contains($id, 'SELLER_PACKAGE')) {
            $defaults = $this->defaultPackageAttribute($id);
            if ($defaults !== null) {
                return $defaults;
            }
        }

        /*
         * Nunca resolver una medida física para un atributo booleano.
         *
         * Ejemplo problemático:
         *
         * WITH_ADJUSTABLE_HEIGHT
         *
         * contiene la palabra HEIGHT, pero ML espera Sí/No.
         * measureForAttributeId() podía interpretarlo como altura física
         * y terminar enviando valores como "7.31 cm".
         */
        if (
            ($attr['value_type'] ?? '') !== 'boolean'
            && $id !== 'BANDWIDTH'
        ) {
            $physical = $this->measureForAttributeId(
                $id,
                $product
            );

            if ($physical !== null) {
                $formatted =
                    $this->formatAttributeValueForMeli(
                        $attr,
                        $physical
                    );

                if ($formatted !== null) {
                    return [
                        'id' => $id,
                        'value_name' => $formatted,
                    ];
                }

                return null;
            }
        }

        $fromFacts = $this->fillAttributeFromFacts($attr, $facts);
        if ($fromFacts !== null) {
            return $fromFacts;
        }

        if (! $allowDefaultFallback) {
            return null;
        }

        if ($values === []) {
            if (($attr['value_type'] ?? '') === 'string' || ($attr['value_type'] ?? '') === 'string_unit') {
                return ['id' => $id, 'value_name' => '—'];
            }

            if (($attr['value_type'] ?? '') === 'number' || ($attr['value_type'] ?? '') === 'number_unit') {
                if (! $allowDefaultFallback) {
                    return null;
                }
                $fallback = $this->fillRequiredAttributeFallback($attr, $product, $facts);
                if ($fallback !== null) {
                    return $fallback;
                }

                $fallback = $this->fillRequiredAttributeFallback($attr, $product, $facts);
                if ($fallback !== null) {
                    return $fallback;
                }

                $bare = $this->formatBareNumberWithDefaultUnit($attr, '1');

                return ['id' => $id, 'value_name' => $bare ?? '1'];
            }

            if (($attr['value_type'] ?? '') === 'boolean') {
                return ['id' => $id, 'value_name' => 'No'];
            }

            return null;
        }

        $first = $values[0];
        $vid = $first['id'] ?? null;
        $vname = (string) ($first['name'] ?? '');

        if ($vid !== null && $vid !== '' && is_scalar($vid)) {
            return ['id' => $id, 'value_id' => (string) $vid, 'value_name' => $vname];
        }

        if ($vname !== '') {
            $formatted = $this->formatAttributeValueForMeli($attr, $vname);

            return $formatted !== null ? ['id' => $id, 'value_name' => $formatted] : null;
        }

        return null;
    }

    private function isAttributeRequired(array $attr): bool
    {
        $tags = is_array($attr['tags'] ?? null) ? $attr['tags'] : [];
        if (! empty($tags['required']) || ! empty($tags['catalog_required']) || ! empty($tags['new_required'])) {
            return true;
        }
        if (! empty($tags['required_for_up']) && ! empty($tags['new_required'])) {
            return true;
        }

        // Par GTIN / motivo GTIN vacío: suelen llegar sólo como conditional_required (sin flag "required"),
        // pero igual hay que enviar uno u otro o ML falla con item.attribute.missing_conditional_required (7810).
        $aid = strtoupper(trim((string) ($attr['id'] ?? '')));
        $pair = ['GTIN', 'EAN', 'UPC', 'EMPTY_GTIN_REASON', 'EMPTY_EAN_REASON'];
        if (in_array($aid, $pair, true) && ! empty($tags['conditional_required'])) {
            return true;
        }

        return false;
    }

    /**
     * Intenta leer EAN/UPC/GTIN desde lista/detalle SYSCOM (claves conocidas + especificaciones).
     *
     * @see https://api.mercadolibre.com/categories/MLM9726 (product_identifier / conditional_required GTIN pair)
     */
    private function extractBarcodeDigitsFromSyscomProduct(SyscomProduct $product): ?string
    {
        $bags = [];
        if (is_array($product->raw_detail)) {
            $bags[] = $product->raw_detail;
        }
        if (is_array($product->raw_list)) {
            $bags[] = $product->raw_list;
        }

        foreach ($bags as $row) {
            foreach ([
                'codigo_barras', 'codigoBarras', 'codigos_barras', 'codigo_barra',
                'ean', 'EAN', 'upc', 'UPC', 'gtin', 'GTIN', 'clave_gtin', 'clave_ean',
            ] as $k) {
                if (! isset($row[$k])) {
                    continue;
                }
                $code = $this->normalizeBarcodeDigits((string) $row[$k]);
                if ($code !== null) {
                    return $code;
                }
            }

            if (isset($row['especificaciones']) && is_array($row['especificaciones'])) {
                foreach ($row['especificaciones'] as $spec) {
                    if (! is_array($spec)) {
                        continue;
                    }
                    $label = mb_strtolower((string) ($spec['nombre'] ?? $spec['titulo'] ?? $spec['label'] ?? ''));
                    if ($label === '' || ! preg_match('/(ean|gtin|upc|c[oó]digo\s+de\s+barras|barras)/u', $label)) {
                        continue;
                    }
                    $valor = trim((string) ($spec['valor'] ?? $spec['value'] ?? $spec['descripcion'] ?? ''));
                    $code = $this->normalizeBarcodeDigits($valor);
                    if ($code !== null) {
                        return $code;
                    }
                }
            }
        }

        return null;
    }

    private function normalizeBarcodeDigits(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        $len = strlen($digits);
        if (in_array($len, [8, 12, 13, 14], true)) {
            return $digits;
        }

        return null;
    }

    private function isReadOnlyAttribute(array $attr): bool
    {
        $tags = is_array($attr['tags'] ?? null) ? $attr['tags'] : [];
        if (! empty($tags['read_only'])) {
            return true;
        }

        return false;
    }

    private function detectColorValue(SyscomProduct $product): ?string
    {
        $source = implode(' ', array_filter([
            (string) ($product->titulo ?? ''),
            (string) ($product->descripcion ?? ''),
            (string) ($product->modelo ?? ''),
        ]));
        $s = mb_strtolower($source);
        if ($s === '') {
            return null;
        }

        $map = [
            'negro' => 'Negro',
            'blanco' => 'Blanco',
            'gris' => 'Gris',
            'plata' => 'Plateado',
            'plateado' => 'Plateado',
            'rojo' => 'Rojo',
            'azul' => 'Azul',
            'verde' => 'Verde',
            'amarillo' => 'Amarillo',
            'naranja' => 'Naranja',
            'rosa' => 'Rosa',
            'dorado' => 'Dorado',
            'marron' => 'Marrón',
            'marrón' => 'Marrón',
            'beige' => 'Beige',
            'transparente' => 'Transparente',
        ];

        foreach ($map as $needle => $value) {
            if (str_contains($s, $needle)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     */
    private function firstValueNameMatching(array $values, array $needles): ?string
    {
        foreach ($values as $v) {
            if (! is_array($v)) {
                continue;
            }
            $name = (string) ($v['name'] ?? '');
            foreach ($needles as $n) {
                if ($name !== '' && str_contains(mb_strtolower($name), mb_strtolower($n))) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @return array{id: string, name: string}|null
     */
    private function pickValueIdByName(array $values, array $candidates): ?array
    {
        foreach ($values as $v) {
            if (! is_array($v)) {
                continue;
            }
            $name = (string) ($v['name'] ?? '');
            $id = (string) ($v['id'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $l = mb_strtolower($name);
            foreach ($candidates as $c) {
                if (str_contains($l, mb_strtolower($c))) {
                    return ['id' => $id, 'name' => $name];
                }
            }
        }

        if (isset($values[0]) && is_array($values[0])) {
            $id = (string) ($values[0]['id'] ?? '');
            if ($id !== '') {
                return [
                    'id' => $id,
                    'name' => (string) ($values[0]['name'] ?? ''),
                ];
            }
        }

        return null;
    }

    private function defaultPackageAttribute(string $id): ?array
    {
        $idU = strtoupper($id);
        if (str_contains($idU, 'HEIGHT')) {
            return ['id' => $id, 'value_name' => '25 cm'];
        }
        if (str_contains($idU, 'WIDTH')) {
            return ['id' => $id, 'value_name' => '25 cm'];
        }
        if (str_contains($idU, 'LENGTH')) {
            return ['id' => $id, 'value_name' => '20 cm'];
        }
        if (str_contains($idU, 'WEIGHT') && ! str_contains($idU, 'VOLUME')) {
            return ['id' => $id, 'value_name' => '2000 g'];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAttributes(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $i = (string) ($r['id'] ?? '');
            if ($i === '') {
                continue;
            }
            $by[$i] = $r;
        }

        $hasCode = isset($by['GTIN']) || isset($by['EAN']) || isset($by['UPC']);
        if ($hasCode) {
            unset($by['EMPTY_GTIN_REASON'], $by['EMPTY_EAN_REASON']);
        }

        return array_values($by);
    }

    /**
     * Construye el array `pictures` del item ML.
     *
     * Estrategia (en orden):
     *  1) Si `image_normalizer.use_meli_upload=true` y nos pasaron `$user`:
     *     normalizamos en memoria → POST /pictures/items/upload → usamos {id: ...}.
     *     Ventaja: ML aloja la foto, no depende de mrpoolhmo.com y no rebota por "tamaño/posición/proporción".
     *  2) Si el upload directo falla o está deshabilitado, normalizamos a URL pública (storage/app/public/...).
     *  3) Si el normalizador está apagado, mandamos las URLs originales de SYSCOM filtradas por extensión.
     */
    public function buildPicturePayload(SyscomProduct $p, ?User $user = null): array
    {
        $urls = $this->collectSyscomImageUrls($p);
        $urls = array_slice($urls, 0, 12);

        if ($urls === []) {
            return [];
        }

        $useNormalizer = (bool) config('syscom.image_normalizer.enabled', true);

        if (! $useNormalizer) {
            $out = [];
            foreach ($urls as $u) {
                if (! preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $u)) {
                    continue;
                }
                $out[] = ['source' => $u];
            }

            return $out;
        }

        $useMeliUpload = (bool) config('syscom.image_normalizer.use_meli_upload', true);
        $cacheKey = 'syscom-' . (int) $p->syscom_producto_id;

        // 1) Intento preferido: normalizar y subir cada imagen a ML para usar `pictures: [{id: ...}]`.
        if ($useMeliUpload && $user !== null) {
            $payload = [];
            $failed = 0;
            foreach ($urls as $i => $url) {
                $bytes = $this->imageNormalizer->normalizeToBytes((string) $url);
                if ($bytes === null) {
                    $failed++;
                    continue;
                }

                $picId = $this->meli->uploadPictureBytes(
                    $user,
                    $bytes,
                    'syscom-' . (int) $p->syscom_producto_id . '-' . ((int) $i + 1) . '.jpg',
                    'image/jpeg'
                );

                if ($picId !== null && $picId !== '') {
                    $payload[] = ['id' => $picId];
                } else {
                    $failed++;
                }
            }

            if ($payload !== []) {
                Log::info('SyscomMeliPublish: imágenes subidas a ML', [
                    'producto' => (int) $p->syscom_producto_id,
                    'subidas' => count($payload),
                    'fallidas' => $failed,
                ]);

                return $payload;
            }

            Log::warning('SyscomMeliPublish: no se pudo subir ninguna imagen a ML, fallback a URL pública.', [
                'producto' => (int) $p->syscom_producto_id,
                'urls' => count($urls),
            ]);
        }

        // 2) Fallback: normalizamos a archivo en disk público y mandamos `source`.
        $normalized = [];
        foreach ($urls as $i => $url) {
            $publicUrl = $this->imageNormalizer->normalizeUrlForMeli((string) $url, $cacheKey, (int) $i);
            if ($publicUrl !== null && $publicUrl !== '') {
                $normalized[] = $publicUrl;
            }
        }

        if ($normalized === [] && $urls !== []) {
            Log::warning('SyscomMeliPublish: ninguna imagen pasó normalización; no se usan URLs SYSCOM crudas (ML exige ≥500 px).', [
                'producto' => (int) $p->syscom_producto_id,
                'urls_intentadas' => count($urls),
            ]);
        }

        $out = [];
        foreach ($normalized as $u) {
            $out[] = ['source' => $u];
        }

        return $out;
    }

    /**
     * Reúne todas las URLs candidatas de SYSCOM en orden de preferencia, sin duplicados.
     *
     * @return list<string>
     */
    private function collectSyscomImageUrls(SyscomProduct $p): array
    {
        $portada = ! empty($p->img_portada) ? trim((string) $p->img_portada) : '';
        $gallery = [];

        if (is_array($p->imagenes)) {
            foreach ($p->imagenes as $img) {
                $u = $this->extractImageUrl($img);
                if ($u !== '') {
                    $gallery[] = $u;
                }
            }
        }

        if (is_array($p->raw_detail)) {
            $imgs = is_array($p->raw_detail['imagenes'] ?? null) ? $p->raw_detail['imagenes'] : [];
            foreach ($imgs as $img) {
                $u = $this->extractImageUrl($img);
                if ($u !== '') {
                    $gallery[] = $u;
                }
            }
        }

        $gallery = array_values(array_unique(array_filter($gallery)));
        if ($portada !== '') {
            $gallery = array_values(array_filter(
                $gallery,
                static fn (string $u): bool => $u !== $portada
            ));
        }

        $preferGallery = (bool) config('syscom.image_normalizer.prefer_gallery_over_portada', true);
        $omitMarketingPortada = (bool) config('syscom.image_normalizer.omit_marketing_portada', true);
        $portadaLooksMarketing = $portada !== '' && $this->imageUrlLooksLikeMarketingBanner($portada);

        $urls = [];
        if ($preferGallery && $gallery !== []) {
            $urls = $gallery;
            if ($portada !== '' && ! ($omitMarketingPortada && $portadaLooksMarketing)) {
                $urls[] = $portada;
            }
        } else {
            if ($portada !== '' && ! ($omitMarketingPortada && $portadaLooksMarketing)) {
                $urls[] = $portada;
            }
            $urls = array_merge($urls, $gallery);
        }

        if ($urls === [] && $portada !== '') {
            $urls[] = $portada;
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Heurística: portadas SYSCOM con logos/texto promocional (ML: "La portada tiene logos y/o textos").
     */
    private function imageUrlLooksLikeMarketingBanner(string $url): bool
    {
        $u = mb_strtolower($url);

        $needles = [
            'portada', 'banner', 'promo', 'promocion', 'marketing', 'watermark', 'logo',
            'texto', 'flyer', 'catalogo', 'cover_', '_cover', 'thumb_banner',
        ];
        foreach ($needles as $n) {
            if (str_contains($u, $n)) {
                return true;
            }
        }

        return false;
    }

    private function extractImageUrl(mixed $img): string
    {
        if (is_string($img)) {
            return trim($img);
        }

        if (is_array($img)) {
            // Orden: primero variantes grandes (en SYSCOM `zoom` a veces apunta a miniatura).
            foreach (['imagen_grande', 'grande', 'imagen', 'zoom', 'url', 'src', 'mediana', 'chica'] as $k) {
                $v = trim((string) ($img[$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    private function buildPublishTitle(SyscomProduct $p): string
    {
        $brand = trim((string) ($p->marca ?? ''));
        $model = trim((string) ($p->modelo ?? ''));
        $title = trim((string) ($p->titulo ?? ''));

        $parts = [];
        if ($brand !== '') {
            $parts[] = $brand;
        }
        if ($model !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($model))) {
            $parts[] = $model;
        }
        if ($title !== '') {
            $parts[] = $title;
        }

        $raw = trim(implode(' ', $parts));
        $raw = preg_replace('/\s+/', ' ', $raw) ?: '';
        $raw = trim($raw);
        if ($raw === '') {
            $raw = 'Producto';
        }

        return Str::limit($raw, 60, '');
    }

    private function extractFirstVideoIdForMeli(SyscomProduct $p): ?string
    {
        foreach ($this->collectVideoUrls($p) as $url) {
            $id = $this->extractYoutubeId($url);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function collectVideoUrls(SyscomProduct $p): array
    {
        $out = [];

        $bags = [
            is_array($p->raw_detail) ? $p->raw_detail : [],
            is_array($p->raw_list) ? $p->raw_list : [],
        ];

        foreach ($bags as $bag) {
            foreach (['videos', 'video', 'recursos', 'resources'] as $k) {
                $v = $bag[$k] ?? null;
                if (is_string($v)) {
                    $out[] = trim($v);
                    continue;
                }
                if (is_array($v)) {
                    foreach ($v as $r) {
                        if (is_string($r)) {
                            $out[] = trim($r);
                            continue;
                        }
                        if (is_array($r)) {
                            foreach (['url', 'link', 'path', 'src'] as $rk) {
                                $u = trim((string) ($r[$rk] ?? ''));
                                if ($u !== '') {
                                    $out[] = $u;
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    private function extractYoutubeId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~i', $url, $m)) {
            return trim((string) $m[1]) ?: null;
        }

        return null;
    }

    /**
     * @return array{height: string, width: string, length: string, weight: string}
     */
    private function fallbackTirePackageMeasures(): array
    {
        $c = config('syscom.fallback_package_tire', []);

        return [
            'height' => $this->formatSellerPackageCm((float) ($c['height_cm'] ?? 28)),
            'width' => $this->formatSellerPackageCm((float) ($c['width_cm'] ?? 72)),
            'length' => $this->formatSellerPackageCm((float) ($c['length_cm'] ?? 72)),
            'weight' => $this->formatSellerPackageGrams((float) ($c['weight_g'] ?? 12000)),
        ];
    }

    /**
     * @param  array{height?: string, width?: string, length?: string, weight?: string}  $d
     */
    private function sellerPackageMeasuresIncomplete(array $d): bool
    {
        foreach (['height', 'width', 'length', 'weight'] as $k) {
            if (! isset($d[$k]) || trim((string) $d[$k]) === '') {
                return true;
            }
        }

        return false;
    }

    private function looksLikeTireProduct(?SyscomProduct $p): bool
    {
        if (! $p) {
            return false;
        }

        $blob = mb_strtolower(trim(
            (string) ($p->titulo ?? '')
            .' '.(string) ($p->modelo ?? '')
            .' '.(is_array($p->categorias) ? json_encode($p->categorias, JSON_UNESCAPED_UNICODE) : '')
        ));

        if (preg_match('/\b(llanta|neum[aá]t|neumatic|tire|radial)\b/u', $blob) === 1) {
            return true;
        }

        return preg_match('/\b\d{3}\s*\/\s*\d{2}\s*R\s*\d{2}\b/i', $blob) === 1;
    }

    private function formatSellerPackageCm(float $cm): string
    {
        $cm = max(1.0, $cm);
        $s = rtrim(rtrim(number_format($cm, 2, '.', ''), '0'), '.');

        return ($s === '' ? '1' : $s).' cm';
    }

    private function formatSellerPackageGrams(float $grams): string
    {
        $g = max(1, (int) round($grams));

        return (string) $g.' g';
    }

    /**
     * @return array{cm: float, unit: string}|null  unit in cm, mm, m, in
     */
    private function parseScalarDimensionToCm(string $numRaw, string $unitRaw): ?array
    {
        $numRaw = str_replace(',', '.', trim($numRaw));
        if ($numRaw === '' || ! is_numeric($numRaw)) {
            return null;
        }
        $n = (float) $numRaw;
        $u = strtolower(trim($unitRaw));
        if ($u === '"') {
            $u = 'in';
        }
        $cm = match ($u) {
            'mm' => $n / 10.0,
            'm' => $n * 100.0,
            'in', 'inch', 'pulgadas' => $n * 2.54,
            default => $n,
        };

        return ['cm' => $cm, 'unit' => $u === 'mm' || $u === 'm' || $u === 'in' || $u === 'inch' || $u === 'pulgadas' ? $u : 'cm'];
    }

    /**
     * @return array{g: float}|null
     */
    private function parseScalarWeightToGrams(string $numRaw, string $unitRaw): ?array
    {
        $numRaw = str_replace(',', '.', trim($numRaw));
        if ($numRaw === '' || ! is_numeric($numRaw)) {
            return null;
        }
        $n = (float) $numRaw;
        $u = strtolower(trim($unitRaw));
        if ($u === 'lbs') {
            $u = 'lb';
        }
        $g = match ($u) {
            'kg', 'kgs' => $n * 1000.0,
            'lb', 'lbs' => $n * 453.59237,
            'oz' => $n * 28.3495,
            default => $n,
        };

        return ['g' => $g];
    }

    /**
     * Detecta patrones "A x B x C cm|mm" (orden arbitrario; asignamos menor→alto, mayor→largo).
     *
     * @return array{height: string, width: string, length: string}|null
     */
    private function parseTripleBoxDimensions(string $textLower): ?array
    {
        $re = '/(?:(?:dimension(?:es)?|medidas?|empaque|paquete|caja)\b[^\d\n]{0,80}?)?(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)(?:\s*(cm|mm|m))?\b/u';
        if (preg_match($re, $textLower, $m) !== 1) {
            return null;
        }
        $u = strtolower(trim((string) ($m[4] ?? '')));
        if ($u === '') {
            $u = 'cm';
        }
        $dims = [];
        for ($i = 1; $i <= 3; $i++) {
            $conv = $this->parseScalarDimensionToCm((string) $m[$i], $u);
            if ($conv === null) {
                return null;
            }
            $dims[] = $conv['cm'];
        }
        sort($dims);

        return [
            'height' => $this->formatSellerPackageCm($dims[0]),
            'width' => $this->formatSellerPackageCm($dims[1]),
            'length' => $this->formatSellerPackageCm($dims[2]),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function collectRawKeyValueLinesForMeasures(?array $node, int $depth = 0): array
    {
        if ($node === null || $depth > 3) {
            return [];
        }
        $lines = [];
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                foreach ($this->collectRawKeyValueLinesForMeasures($v, $depth + 1) as $sub) {
                    $lines[] = $sub;
                }

                continue;
            }
            if (! is_scalar($v)) {
                continue;
            }
            $ks = mb_strtolower(trim((string) $k));
            $vs = trim((string) $v);
            if ($ks === '' || $vs === '') {
                continue;
            }
            if (strlen($vs) > 200) {
                continue;
            }
            $lines[] = $ks.': '.$vs;
        }

        return $lines;
    }

    /**
     * Rellena medidas desde claves tipo peso_bruto, alto_cm, etc.
     *
     * @param  array{height?: string, width?: string, length?: string, weight?: string}  $out
     * @param  array<string, string>  $byKey
     */
    private function enrichMeasuresFromStructuredKeys(array &$out, array $byKey): void
    {
        foreach ($byKey as $rawKey => $rawVal) {
            $k = mb_strtolower(trim($rawKey));
            $v = trim($rawVal);
            if ($k === '' || $v === '') {
                continue;
            }
            if (preg_match('/precio|costo|volumen|capacidad|vel|rpm|volt|watt|mah|mhz|ghz|db\b|dpi|px\b|pulgada de diam|diametro exterior de llanta/i', $k) === 1) {
                continue;
            }

            if (! isset($out['weight']) && $this->looksLikeWeightAttributeKey($k)) {
                if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|g|lb|lbs|oz)?\b/u', mb_strtolower($v), $wm) === 1) {
                    $unit = strtolower(trim((string) ($wm[2] ?? '')));
                    if ($unit === '') {
                        $n = (float) str_replace(',', '.', (string) $wm[1]);
                        $unit = ($n > 0 && $n < 90) ? 'kg' : 'g';
                    }
                    $g = $this->parseScalarWeightToGrams((string) $wm[1], $unit === '' ? 'kg' : $unit);
                    if ($g !== null) {
                        $out['weight'] = $this->formatSellerPackageGrams($g['g']);
                    }
                }
            }

            if (preg_match('/dimension|medidas?\s*de\s*empaque|empaque|paquete|caja/i', $k) === 1 && str_contains($v, 'x')) {
                $triple = $this->parseTripleBoxDimensions(mb_strtolower($v));
                if ($triple !== null) {
                    $out['height'] = $out['height'] ?? $triple['height'];
                    $out['width'] = $out['width'] ?? $triple['width'];
                    $out['length'] = $out['length'] ?? $triple['length'];
                }
            }

            $dimPair = '/^(\d+(?:[.,]\d+)?)\s*(cm|mm|m)?\b/u';
            if (! isset($out['height']) && preg_match('/\b(alto|altura|height)\b/i', $k) === 1 && preg_match($dimPair, mb_strtolower($v), $dm) === 1) {
                $unitDim = trim((string) ($dm[2] ?? ''));
                if ($unitDim === '') {
                    $unitDim = 'cm';
                }
                $c = $this->parseScalarDimensionToCm((string) $dm[1], $unitDim);
                if ($c !== null) {
                    $out['height'] = $this->formatSellerPackageCm($c['cm']);
                }
            }
            if (! isset($out['width']) && preg_match('/\b(ancho|anchura|width)\b/i', $k) === 1 && preg_match($dimPair, mb_strtolower($v), $dm) === 1) {
                $unitDim = trim((string) ($dm[2] ?? ''));
                if ($unitDim === '') {
                    $unitDim = 'cm';
                }
                $c = $this->parseScalarDimensionToCm((string) $dm[1], $unitDim);
                if ($c !== null) {
                    $out['width'] = $this->formatSellerPackageCm($c['cm']);
                }
            }
            if (! isset($out['length']) && preg_match('/\b(largo|longitud|profundidad|fondo|depth|length)\b/i', $k) === 1 && preg_match($dimPair, mb_strtolower($v), $dm) === 1) {
                $unitDim = trim((string) ($dm[2] ?? ''));
                if ($unitDim === '') {
                    $unitDim = 'cm';
                }
                $c = $this->parseScalarDimensionToCm((string) $dm[1], $unitDim);
                if ($c !== null) {
                    $out['length'] = $this->formatSellerPackageCm($c['cm']);
                }
            }
        }
    }

    private function looksLikeWeightAttributeKey(string $k): bool
    {
        if (preg_match('/precio|presi[oó]n|compresi[oó]n|volumen|capacidad|velocidad/i', $k) === 1) {
            return false;
        }

        return str_contains($k, 'peso')
            || str_contains($k, 'weight')
            || str_contains($k, 'p_bruto')
            || str_contains($k, 'peso_bruto')
            || str_contains($k, 'peso_neto');
    }

    /**
     * @return array{height?: string, width?: string, length?: string, weight?: string}
     */
    private function extractPhysicalMeasures(?SyscomProduct $p): array
    {
        if (! $p) {
            return [];
        }

        $facts = $this->extractCharacteristicsFacts($p);
        $lines = is_array($facts['lines'] ?? null) ? $facts['lines'] : [];
        $byKey = is_array($facts['by_key'] ?? null) ? $facts['by_key'] : [];

        $rawLines = [];
        if (is_array($p->raw_detail)) {
            $rawLines = array_merge($rawLines, $this->collectRawKeyValueLinesForMeasures($p->raw_detail));
        }
        if (is_array($p->raw_list)) {
            $rawLines = array_merge($rawLines, $this->collectRawKeyValueLinesForMeasures($p->raw_list));
        }

        $source = implode("\n", array_filter([
            (string) ($p->titulo ?? ''),
            (string) ($p->descripcion ?? ''),
            is_array($p->raw_detail) ? (string) ($p->raw_detail['descripcion'] ?? '') : '',
            implode("\n", $lines),
            implode("\n", $rawLines),
        ]));
        $sourceLower = mb_strtolower($source);

        $out = [];

        $triple = $this->parseTripleBoxDimensions($sourceLower);
        if ($triple !== null) {
            $out['height'] = $triple['height'];
            $out['width'] = $triple['width'];
            $out['length'] = $triple['length'];
        }

        foreach ($lines as $line) {
            $tl = $this->parseTripleBoxDimensions(mb_strtolower($line));
            if ($tl !== null) {
                $out['height'] = $out['height'] ?? $tl['height'];
                $out['width'] = $out['width'] ?? $tl['width'];
                $out['length'] = $out['length'] ?? $tl['length'];
            }
        }

        $map = [
            'height' => '/(?:alto|altura)\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*(cm|mm|m|in|")/u',
            'width' => '/(?:ancho|anchura)\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*(cm|mm|m|in|")/u',
            'length' => '/(?:largo|longitud|profundidad|fondo)\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*(cm|mm|m|in|")/u',
            'weight' => '/(?:peso|p\.?\s*bruto|peso\s+bruto|peso\s+neto)\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*(kg|g|lb|lbs|oz)\b/u',
        ];

        foreach ($map as $k => $re) {
            if (isset($out[$k])) {
                continue;
            }
            if (preg_match($re, $sourceLower, $m) !== 1) {
                continue;
            }
            $num = str_replace(',', '.', (string) ($m[1] ?? ''));
            $unit = strtolower(trim((string) ($m[2] ?? '')));
            if ($num === '' || $unit === '') {
                continue;
            }
            if ($unit === '"') {
                $unit = 'in';
            }
            if ($unit === 'lbs') {
                $unit = 'lb';
            }
            if ($k === 'weight') {
                $g = $this->parseScalarWeightToGrams($num, $unit);
                if ($g !== null) {
                    $out[$k] = $this->formatSellerPackageGrams($g['g']);
                }
            } else {
                $c = $this->parseScalarDimensionToCm($num, $unit);
                if ($c !== null) {
                    $out[$k] = $this->formatSellerPackageCm($c['cm']);
                }
            }
        }

        $this->enrichMeasuresFromStructuredKeys($out, $byKey);

        foreach ($rawLines as $rl) {
            $pos = strpos($rl, ':');
            if ($pos === false) {
                continue;
            }
            $kRaw = mb_strtolower(trim(substr($rl, 0, $pos)));
            $vRaw = trim(substr($rl, $pos + 1));
            if ($kRaw !== '' && $vRaw !== '') {
                $this->enrichMeasuresFromStructuredKeys($out, [$kRaw => $vRaw]);
            }
        }

        foreach ($rawLines as $rl) {
            $lower = mb_strtolower($rl);
            if (! isset($out['weight']) && preg_match('/(?:peso|weight)\s*:\s*(\d+(?:[.,]\d+)?)\s*(kg|g|lb|lbs)\b/u', $lower, $wm) === 1) {
                $g = $this->parseScalarWeightToGrams((string) $wm[1], (string) $wm[2]);
                if ($g !== null) {
                    $out['weight'] = $this->formatSellerPackageGrams($g['g']);
                }
            }
        }

        return $out;
    }

    private function measureForAttributeId(string $id, SyscomProduct $p): ?string
    {
        $m = $this->extractPhysicalMeasures($p);
        $id = strtoupper($id);

        if (str_contains($id, 'HEIGHT')) {
            return $m['height'] ?? null;
        }
        if (str_contains($id, 'WIDTH')) {
            return $m['width'] ?? null;
        }
        if (str_contains($id, 'LENGTH')) {
            return $m['length'] ?? null;
        }
        if (str_contains($id, 'WEIGHT') && ! str_contains($id, 'VOLUME')) {
            return $m['weight'] ?? null;
        }

        return null;
    }

    /**
     * @return array{
     *   by_key: array<string,string>,
     *   lines: list<string>
     * }
     */
    private function extractCharacteristicsFacts(SyscomProduct $p): array
    {
        $lines = $this->collectCharacteristicsLines($p);
        $byKey = [];

        foreach ($lines as $line) {
            $s = trim($line);
            if ($s === '') {
                continue;
            }

            // Caso "Clave: valor"
            if (preg_match('/^\s*([^:]{2,80})\s*:\s*(.+)\s*$/u', $s, $m)) {
                $k = $this->normalizeKey((string) $m[1]);
                $v = trim((string) $m[2]);
                if ($k !== '' && $v !== '') {
                    $byKey[$k] = $v;
                    continue;
                }
            }

            // Caso "Bluetooth 5.0", "Batería 1200 mAh", etc.
            if (preg_match('/^\s*([^\d]{2,80}?)\s+(\d.+)\s*$/u', $s, $m)) {
                $k = $this->normalizeKey((string) $m[1]);
                $v = trim((string) $m[2]);
                if ($k !== '' && $v !== '') {
                    $byKey[$k] = $v;
                }
            }
        }

        return [
            'by_key' => $byKey,
            'lines' => $lines,
        ];
    }

    private function fillAttributeFromFacts(array $attr, array $facts): ?array
    {
        $id = strtoupper(trim((string) ($attr['id'] ?? '')));
        $name = trim((string) ($attr['name'] ?? ''));
        $values = is_array($attr['values'] ?? null) ? $attr['values'] : [];

        /*
         * BANDWIDTH no es una dimensión física.
         *
         * Solo aceptamos una frecuencia explícita obtenida
         * de características como:
         *
         *   Ancho de banda 2000 MHz
         *   Frecuencia hasta 250 MHz
         *   Rendimiento Cat6A hasta 500 MHz
         *   Ancho de banda 1.2 GHz
         *
         * Nunca valores como "20 cm".
         */
        if ($id === 'BANDWIDTH') {
            return $this->fillBandwidthAttributeFromFacts(
                $attr,
                $facts
            );
        }

        $candidateKeys = array_filter([
            $this->normalizeKey($name),
            $this->normalizeKey(str_replace('_', ' ', $id)),
        ]);
        $byKey = is_array($facts['by_key'] ?? null) ? $facts['by_key'] : [];
        $lines = is_array($facts['lines'] ?? null) ? $facts['lines'] : [];

        $valueType = (string) (
            $attr['value_type']
            ?? ''
        );

        /*
         * Para number/number_unit/boolean no usar coincidencias
         * parciales de nombres.
         *
         * Ejemplos de falsos positivos que esto evita:
         *
         * "Ancho" -> "Ancho de banda"
         * "Puertos 2.5G" -> "Cantidad de puertos LAN"
         * "Almacenamiento" -> capacidad de huellas/rostros
         *
         * En esos tipos solo aceptamos una clave exacta.
         */
        $strictFactMatch = in_array(
            $valueType,
            [
                'number',
                'number_unit',
                'boolean',
            ],
            true
        );

        $value = null;

        foreach ($candidateKeys as $ck) {
            if (isset($byKey[$ck])) {
                $value = trim(
                    (string) $byKey[$ck]
                );

                break;
            }

            if ($strictFactMatch) {
                continue;
            }

            foreach ($byKey as $k => $v) {
                if (
                    $k !== ''
                    && (
                        str_contains($k, $ck)
                        || str_contains($ck, $k)
                    )
                ) {
                    $value = trim(
                        (string) $v
                    );

                    break 2;
                }
            }
        }

        if ($value === null || $value === '') {
            $lname = mb_strtolower($name);
            foreach ($lines as $line) {
                $ll = mb_strtolower((string) $line);
                if ($lname !== '' && str_contains($ll, $lname)) {
                    $value = trim((string) $line);
                    break;
                }
            }
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($id, ['GTIN', 'EAN', 'UPC'], true)) {
            $code = $this->normalizeBarcodeDigits($value);
            if ($code === null || preg_match('/^(noespecificado|no\s*especificado|n\/a|na|sin\s+codigo)$/ui', trim($value))) {
                return null;
            }

            return ['id' => $id, 'value_name' => $code];
        }

        $formatted = $this->formatAttributeValueForMeli($attr, $value);
        if ($formatted === null && in_array((string) ($attr['value_type'] ?? ''), ['number_unit', 'number', 'string_unit'], true)) {
            return null;
        }
        if ($formatted !== null) {
            $value = $formatted;
        }

        if (($attr['value_type'] ?? '') === 'boolean') {
            /*
             * Los atributos booleanos de ML solamente aceptan
             * señales inequívocas de Sí/No.
             *
             * Nunca permitir que una medida física obtenida de
             * características SYSCOM, por ejemplo:
             *
             *   7.31 cm
             *   24.21 cm
             *   51 x 31
             *
             * termine enviada a un booleano como
             * WITH_ADJUSTABLE_HEIGHT.
             */
            $lv = mb_strtolower(trim($value));
            $lv = trim(
                preg_replace('/\s+/u', ' ', $lv)
                ?? $lv
            );

            $wantYes = preg_match(
                '/^(?:si|sí|yes|true|1)$/u',
                $lv
            ) === 1;

            $wantNo = preg_match(
                '/^(?:no|false|0)$/u',
                $lv
            ) === 1;

            if (! $wantYes && ! $wantNo) {
                return null;
            }

            $pick = $this->pickValueIdByName(
                $values,
                $wantYes
                    ? ['sí', 'si', 'yes', 'true']
                    : ['no', 'false']
            );

            if ($pick !== null) {
                return [
                    'id' => $id,
                    'value_id' => $pick['id'],
                    'value_name' => $pick['name'],
                ];
            }

            return [
                'id' => $id,
                'value_name' => $wantYes ? 'Sí' : 'No',
            ];
        }

        $pick = $this->pickValueIdByName($values, [$value]);
        if ($pick !== null) {
            return ['id' => $id, 'value_id' => $pick['id'], 'value_name' => $pick['name']];
        }

        return ['id' => $id, 'value_name' => Str::limit($value, 120, '')];
    }

    /**
     * Devuelve BANDWIDTH únicamente cuando SYSCOM contiene
     * una frecuencia inequívoca.
     *
     * No infiere ni convierte dimensiones físicas.
     *
     * @param array<string, mixed> $attr
     * @param array<string, mixed> $facts
     */
    private function fillBandwidthAttributeFromFacts(
        array $attr,
        array $facts
    ): ?array {
        $id = strtoupper(
            trim(
                (string) (
                    $attr['id']
                    ?? ''
                )
            )
        );

        if ($id !== 'BANDWIDTH') {
            return null;
        }

        $candidates = [];

        $addCandidate = function (
            string $text,
            int $priority
        ) use (&$candidates): void {
            $text = trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    $text
                )
                ?? $text
            );

            if ($text === '') {
                return;
            }

            /*
             * Exigir unidad real de frecuencia.
             * No aceptar cm/mm/m/Gbps como BANDWIDTH.
             */
            if (
                ! preg_match(
                    '/(?<![\d.])'
                    .'(\d+(?:[.,]\d+)?)'
                    .'\s*'
                    .'(GHz|MHz|kHz|Hz)'
                    .'\b/iu',
                    $text,
                    $m
                )
            ) {
                return;
            }

            $num = str_replace(
                ',',
                '.',
                $m[1]
            );

            $unitLower =
                mb_strtolower(
                    $m[2]
                );

            $unit = match ($unitLower) {
                'ghz' => 'GHz',
                'mhz' => 'MHz',
                'khz' => 'kHz',
                'hz' => 'Hz',
                default => null,
            };

            if ($unit === null) {
                return;
            }

            $candidates[] = [
                'priority' =>
                    $priority,

                'value' =>
                    $num.' '.$unit,

                'source' =>
                    $text,
            ];
        };

        $byKey = is_array(
            $facts['by_key']
            ?? null
        )
            ? $facts['by_key']
            : [];

        foreach ($byKey as $key => $value) {
            $hay = mb_strtolower(
                (string) $key
                .' '
                .(string) $value
            );

            $priority = 0;

            if (
                str_contains(
                    $hay,
                    'ancho de banda'
                )
                || str_contains(
                    $hay,
                    'bandwidth'
                )
            ) {
                $priority = 100;
            } elseif (
                str_contains(
                    $hay,
                    'frecuencia'
                )
                || str_contains(
                    $hay,
                    'frequency'
                )
            ) {
                $priority = 90;
            } elseif (
                str_contains(
                    $hay,
                    'rendimiento'
                )
                || str_contains(
                    $hay,
                    'certific'
                )
            ) {
                $priority = 80;
            }

            if ($priority > 0) {
                $addCandidate(
                    (string) $value,
                    $priority
                );
            }
        }

        $lines = is_array(
            $facts['lines']
            ?? null
        )
            ? $facts['lines']
            : [];

        foreach ($lines as $line) {
            $line = (string) $line;

            $hay = mb_strtolower(
                $line
            );

            $priority = 0;

            if (
                str_contains(
                    $hay,
                    'ancho de banda'
                )
                || str_contains(
                    $hay,
                    'bandwidth'
                )
            ) {
                $priority = 100;
            } elseif (
                str_contains(
                    $hay,
                    'frecuencia'
                )
                || str_contains(
                    $hay,
                    'frequency'
                )
            ) {
                $priority = 90;
            } elseif (
                str_contains(
                    $hay,
                    'rendimiento'
                )
                || str_contains(
                    $hay,
                    'certific'
                )
            ) {
                $priority = 80;
            }

            if ($priority > 0) {
                $addCandidate(
                    $line,
                    $priority
                );
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (
                array $a,
                array $b
            ): int =>
                $b['priority']
                <=>
                $a['priority']
        );

        $winner =
            $candidates[0];

        Log::debug(
            'SyscomMeliPublish: BANDWIDTH confiable',
            [
                'value' =>
                    $winner['value'],

                'source' =>
                    $winner['source'],
            ]
        );

        return [
            'id' =>
                'BANDWIDTH',

            'value_name' =>
                $winner['value'],
        ];
    }

    private function normalizeKey(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        if ($s === '') {
            return '';
        }
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?: '';
        $s = preg_replace('/\s+/', ' ', $s) ?: '';

        return trim($s);
    }

    /**
     * @return list<string>
     */
    private function collectCharacteristicsLines(SyscomProduct $p): array
    {
        $lines = [];

        $bags = [
            is_array($p->raw_detail) ? ($p->raw_detail['caracteristicas'] ?? null) : null,
            is_array($p->raw_list) ? ($p->raw_list['caracteristicas'] ?? null) : null,
        ];

        foreach ($bags as $bag) {
            if (is_array($bag)) {
                foreach ($bag as $row) {
                    if (is_string($row)) {
                        $s = trim(strip_tags($row));
                        if ($s !== '') {
                            $lines[] = $s;
                        }
                        continue;
                    }
                    if (is_array($row)) {
                        $nombre = trim((string) ($row['nombre'] ?? $row['atributo'] ?? $row['clave'] ?? $row['campo'] ?? $row['titulo'] ?? ''));
                        $s = trim((string) ($row['valor'] ?? $row['value'] ?? $row['descripcion'] ?? $row['name'] ?? ''));
                        if ($nombre !== '' && $s !== '') {
                            $lines[] = strip_tags($nombre . ': ' . $s);
                        } elseif ($s !== '') {
                            $lines[] = strip_tags($s);
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($lines)));
    }

    public function plainDescription(SyscomProduct $p): string
    {
        $d = (string) ($p->descripcion ?? '');
        if ($d === '' && is_array($p->raw_detail)) {
            $d = (string) ($p->raw_detail['descripcion'] ?? $p->raw_detail['description'] ?? '');
        }
        $d = html_entity_decode($d, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $d = str_replace(['<br>', '<br/>', '<br />'], "\n", $d);
        $d = trim(strip_tags($d));
        if ($d === '') {
            $d = trim((string) ($p->titulo ?? ''));
        }

        $d = $this->ensureParagraphSpacing($d);

        $chars = $this->collectCharacteristicsLines($p);
        if ($chars !== []) {
            $bullet = array_map(static fn (string $x) => '- ' . $x, array_slice($chars, 0, 12));
            $d = trim($d . "\n\nCaracterísticas:\n" . implode("\n", $bullet));
        }

        $d = $this->sanitizeDescriptionForMeliPolicy($d);

        return trim($d);
    }

    /**
     * Evita párrafos pegados: respeta bloques existentes y añade salto doble tras frases seguidas.
     */
    private function ensureParagraphSpacing(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace("/[ \t\f\v]+/u", ' ', $text) ?? $text;
        // Párrafos ya separados por línea en blanco: normalizar a máximo dos saltos.
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        // Texto corrido: separar oraciones; el punto solo si no es decimal (evita "3.5 mm").
        $text = preg_replace('/(?<!\d)\.\s+(?=[\p{Lu}])/u', ".\n\n", $text) ?? $text;
        $text = preg_replace('/([!?])\s+(?=[\p{Lu}\p{N}])/u', "$1\n\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Quita enlaces, correos, teléfonos y líneas tipo contacto/redes que suelen moderar o bajar en ML.
     */
    private function sanitizeDescriptionForMeliPolicy(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match(
                '/\b(whatsapp|wa\.me|w\.a\.|telegram\.me|t\.me\/|instagram\.com\/|facebook\.com\/|fb\.me\/|mailto:|contacto\s*directo|llamar\s+al|llámanos|cel\.?\s*:|telf\.?\s*:|tel\.?\s*:|phone\s*:)\b/iu',
                $line
            )) {
                continue;
            }
            $out[] = $line;
        }
        $text = implode("\n", $out);
        $text = preg_replace('#https?://\S+#iu', '', $text) ?? $text;
        $text = preg_replace('#\bwww\.\S+#iu', '', $text) ?? $text;
        $text = preg_replace('/\b[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}\b/u', '', $text) ?? $text;
        // Teléfonos con separadores (evita borrar enteros cortos de especificaciones).
        $text = preg_replace(
            '/\b\+?\d{1,3}[\s.\-]?\(?\d{2,4}\)?[\s.\-]?\d{2,4}[\s.\-]?\d{2,4}[\s.\-]?\d{2,6}\b/u',
            '',
            $text
        ) ?? $text;
        // Solo espacios/tabuladores repetidos (no tocar \n para conservar párrafos).
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}