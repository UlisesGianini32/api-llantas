<?php

namespace App\Services;

use App\Models\MeliPublication;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class MeliRepublishService
{
    public function client(): Client
    {
        return new Client([
            'base_uri' => 'https://api.mercadolibre.com/',
            'timeout'  => 25,
        ]);
    }

    protected function headers(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . $user->access_token,
            'Accept'        => 'application/json',
        ];
    }

    protected function throwClientException(ClientException $e, string $tag): void
    {
        $status = $e->getResponse()?->getStatusCode() ?? 0;
        $body   = (string) ($e->getResponse()?->getBody() ?? '');

        Log::warning("ML {$tag} error status={$status} body={$body}");
        throw new \RuntimeException("ML_ERROR:{$status}:{$body}");
    }

    // ==========================
    // API BASE PARA REPUBLISH
    // ==========================
    public function getItem(User $user, string $mlm): array
    {
        try {
            $res = $this->client()->get("items/{$mlm}", [
                'headers' => $this->headers($user),
            ]);

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (ClientException $e) {
            $this->throwClientException($e, 'republish.getItem');
        }

        return [];
    }

    public function getDescription(User $user, string $mlm): ?array
    {
        try {
            $res = $this->client()->get("items/{$mlm}/description", [
                'headers' => $this->headers($user),
            ]);

            $json = json_decode((string) $res->getBody(), true);
            return is_array($json) ? $json : null;
        } catch (ClientException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;

            if (in_array($status, [400, 404], true)) {
                return null;
            }

            $this->throwClientException($e, 'republish.getDescription');
        }

        return null;
    }

    public function createItem(User $user, array $payload): array
    {
        try {
            $res = $this->client()->post('items', [
                'headers' => $this->headers($user) + ['Content-Type' => 'application/json'],
                'json'    => $payload,
            ]);

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (ClientException $e) {
            $this->throwClientException($e, 'republish.createItem');
        }

        return [];
    }

    public function createDescription(User $user, string $mlm, string $plainText): array
    {
        try {
            $res = $this->client()->post("items/{$mlm}/description", [
                'headers' => $this->headers($user) + ['Content-Type' => 'application/json'],
                'json'    => ['plain_text' => $plainText],
            ]);

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (ClientException $e) {
            $this->throwClientException($e, 'republish.createDescription');
        }

        return [];
    }

    public function upsertPublication(User $user, string $sku, array $item): MeliPublication
    {
        $mlm = $item['id'] ?? null;
        if (!$mlm) {
            throw new \RuntimeException('Item sin id (MLM).');
        }

        $subStatus = $item['sub_status'] ?? null;

        if (is_string($subStatus) && $subStatus !== '') {
            $subStatus = [$subStatus];
        } elseif (!is_array($subStatus)) {
            $subStatus = null;
        }

        return MeliPublication::updateOrCreate(
            ['user_id' => $user->id, 'mlm' => $mlm],
            [
                'sku'          => $sku,
                'status'       => $item['status'] ?? null,
                'sub_status'   => $subStatus,
                'permalink'    => $item['permalink'] ?? null,
                'raw'          => $item,
                'last_sync_at' => now(),
            ]
        );
    }

    // ==========================
    // FORM DATA
    // ==========================
    public function getFormData(User $user, string $sourceMlm): array
    {
        $sourceMlm = trim($sourceMlm);
        if ($sourceMlm === '') {
            throw new \RuntimeException('MLM origen vacío.');
        }

        $item = $this->getItem($user, $sourceMlm);

        $pub = MeliPublication::where('user_id', $user->id)
            ->where('mlm', $sourceMlm)
            ->first();

        $isUserProduct = $this->isUserProductItem($item);

        $defaultLabel = $isUserProduct
            ? trim((string) ($item['family_name'] ?? $item['title'] ?? ''))
            : trim((string) ($item['title'] ?? $item['family_name'] ?? ''));

        $defaultOfficialStoreMode = $this->detectOfficialStoreModeFromItem($item);

        $rawAttrs = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
        $currentUniversalCode = $this->findAttributeTextByIds($rawAttrs, [
            'GTIN',
            'EAN',
            'UPC',
        ]) ?? '';

        return [
            'ml'                       => $sourceMlm,
            'item'                     => $item,
            'pub'                      => $pub,
            'isUserProduct'            => $isUserProduct,
            'defaultLabel'             => $defaultLabel,
            'defaultPrice'             => (float) ($item['price'] ?? 0),
            'defaultOfficialStoreMode' => $defaultOfficialStoreMode,
            'currentUniversalCode'     => $currentUniversalCode,
        ];
    }

    public function republishProductByMlm(
        User $user,
        string $sourceMlm,
        string $newLabel,
        float $newPrice,
        array $options = []
    ): MeliPublication {
        $sourceMlm = trim($sourceMlm);
        if ($sourceMlm === '') {
            throw new \RuntimeException('MLM origen vacío.');
        }

        $keepCatalog     = (bool) ($options['keep_catalog'] ?? false);
        $officialStoreId = isset($options['official_store_id']) && $options['official_store_id']
            ? (int) $options['official_store_id']
            : null;

        $sourceItem = $this->getItem($user, $sourceMlm);
        $desc       = $this->getDescription($user, $sourceMlm);

        $sourcePub = MeliPublication::where('user_id', $user->id)
            ->where('mlm', $sourceMlm)
            ->first();

        $newUniversalCode = isset($options['universal_code'])
            ? trim((string) $options['universal_code'])
            : '';

        $payload = $this->buildRepublishPayloadFromItem(
            $sourceItem,
            $newLabel,
            $newPrice,
            (string) ($sourceItem['seller_custom_field'] ?? ''),
            $keepCatalog,
            $officialStoreId,
            $newUniversalCode !== '' ? $newUniversalCode : null
        );

        Log::info('ML republish payload prepared', [
            'source_mlm'      => $sourceMlm,
            'is_user_product' => $this->isUserProductItem($sourceItem),
            'payload'         => $payload,
        ]);

        $created = $this->createItemWithFallbacks($user, $payload);

        $newMlm = (string) ($created['id'] ?? '');
        if ($newMlm === '') {
            throw new \RuntimeException('Mercado Libre no devolvió el nuevo MLM.');
        }

        $plainText = trim((string) ($desc['plain_text'] ?? ''));
        if ($plainText !== '') {
            try {
                $this->createDescription($user, $newMlm, $plainText);
            } catch (\Throwable $e) {
                Log::warning('ML createDescription on republish failed', [
                    'source_mlm' => $sourceMlm,
                    'new_mlm'    => $newMlm,
                    'err'        => $e->getMessage(),
                ]);
            }
        }

        $newItem = $this->getItem($user, $newMlm);

        $sku = (string) (
            $sourcePub?->sku
            ?? ($sourceItem['seller_custom_field'] ?? '')
            ?? ($newItem['seller_custom_field'] ?? '')
        );

        if ($sku === '') {
            $sku = $sourceMlm;
        }

        $newPub = $this->upsertPublication($user, $sku, $newItem);

        if ($sourcePub) {
            $raw = is_array($sourcePub->raw) ? $sourcePub->raw : [];
            $raw['republished_to'] = $newMlm;
            $raw['republished_at'] = now()->toDateTimeString();

            $sourcePub->update([
                'raw'          => $raw,
                'last_sync_at' => now(),
            ]);
        }

        return $newPub;
    }

    protected function detectOfficialStoreModeFromItem(array $item): string
    {
        $current = (int) ($item['official_store_id'] ?? 0);

        $marketmax = (int) (
            config('services.meli.official_store_id_marketmax')
            ?: config('services.meli.official_store_id')
            ?: 0
        );

        $tobeauty = (int) (
            config('services.meli.official_store_id_tobeauty')
            ?: config('services.meli.official_store_id')
            ?: 0
        );

        if ($current > 0 && $marketmax > 0 && $current === $marketmax) {
            return 'marketmax';
        }

        if ($current > 0 && $tobeauty > 0 && $current === $tobeauty) {
            return 'tobeauty';
        }

        return 'tobeauty';
    }

    // ==========================
    // CREATE WITH FALLBACKS
    // ==========================
    protected function createItemWithFallbacks(User $user, array $payload): array
    {
        $attemptPayload = $payload;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                return $this->createItem($user, $attemptPayload);
            } catch (\Throwable $e) {
                if ($attempt >= 4) {
                    throw $e;
                }

                $parsed = $this->parseMlRuntimeError($e);
                if (!$parsed) {
                    throw $e;
                }

                $adjusted = $this->adjustPayloadFromMlError($attemptPayload, $parsed);

                if ($adjusted == $attemptPayload) {
                    throw $e;
                }

                Log::warning('ML republish retry with adjusted payload', [
                    'attempt'          => $attempt + 1,
                    'original_error'   => $e->getMessage(),
                    'adjusted_payload' => $adjusted,
                ]);

                $attemptPayload = $adjusted;
            }
        }

        throw new \RuntimeException('No se pudo crear la publicación al republicar.');
    }

    protected function parseMlRuntimeError(\Throwable $e): ?array
    {
        $msg = (string) $e->getMessage();

        if (!str_starts_with($msg, 'ML_ERROR:')) {
            return null;
        }

        $parts = explode(':', $msg, 3);
        if (count($parts) < 3) {
            return null;
        }

        $json = $parts[2];
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    protected function adjustPayloadFromMlError(array $payload, array $error): array
    {
        $causes = is_array($error['cause'] ?? null) ? $error['cause'] : [];

        $removeIds = [];
        $forcePackOne = false;
        $forcePackage = false;
        $forcePackageStrong = false;
        $forceModel = false;
        $forceSaleFormatUnidad = false;
        $dropShippingMode = false;
        $dropUnitsPerPack = false;

        foreach ($causes as $cause) {
            $code = strtolower(trim((string) ($cause['code'] ?? '')));
            $msg = trim((string) ($cause['message'] ?? ''));
            $msgLower = strtolower($msg);

            if (
                $code === 'item.attribute.invalid_sale_units' ||
                str_contains($msgLower, 'unidades por pack') ||
                str_contains($msgLower, 'sale units')
            ) {
                $forcePackOne = true;
            }

            if (
                str_contains($code, 'seller.package.dimensions') ||
                str_contains($msgLower, 'seller_package_height') ||
                str_contains($msgLower, 'seller_package_width') ||
                str_contains($msgLower, 'seller_package_length') ||
                str_contains($msgLower, 'seller_package_weight')
            ) {
                $forcePackage = true;
            }

            if (
                $code === 'item.attribute.invalid.seller.package.dimensions' ||
                str_contains($msgLower, 'packaging attributes') ||
                str_contains($msgLower, 'too small for the product dimensions')
            ) {
                $forcePackageStrong = true;
            }

            if (
                $code === 'shipping.lost_me1_by_user' ||
                str_contains($msgLower, 'lost me1') ||
                str_contains($msgLower, 'user has not mode me1')
            ) {
                $dropShippingMode = true;
            }

            if (
                $code === 'item.attribute.missing_catalog_required' &&
                (str_contains($msgLower, 'modelo') || str_contains($msgLower, 'model'))
            ) {
                $forceModel = true;
            }

            if (
                $code === 'invalid.item.attribute.values' &&
                str_contains($msgLower, 'sale_format')
            ) {
                $forceSaleFormatUnidad = true;
            }

            if (
                $code === 'item.attribute.number_invalid_format' &&
                (
                    str_contains($msgLower, 'unidades por envase') ||
                    str_contains($msgLower, 'units per pack') ||
                    str_contains($msgLower, 'units per package')
                )
            ) {
                $dropUnitsPerPack = true;
            }

            if ($code === 'item.attribute.invalid') {
                $invalidId = $this->extractInvalidAttributeId($msg);
                if ($invalidId) {
                    $removeIds[] = $invalidId;
                }
            }

            $knownIds = [
                'HAIR_TREATMENT_FORMAT',
                'HAIR_TYPES',
                'NET_VOLUME',
                'NET_WEIGHT',
                'TOTAL_CONTENT_VOLUME',
                'TOTAL_CONTENT_WEIGHT',
                'TOTAL_CONTENT_VOLUME_KIT',
                'TOTAL_CONTENT_WEIGHT_KIT',
                'SHELF_LIFE',
                'HAZMAT_TRANSPORTABILITY',
                'PRODUCT_FEATURES',
                'PACKAGE_HEIGHT',
                'PACKAGE_WIDTH',
                'PACKAGE_LENGTH',
                'PACKAGE_WEIGHT',
                'PRESENTATION',
                'TECHNOLOGY_TYPE',
                'HAIR_TONE',
                'IS_HIGHLIGHT_BRAND',
                'IS_TOM_BRAND',
            ];

            foreach ($knownIds as $id) {
                $idLower = strtolower($id);

                if (
                    str_contains($msgLower, '[' . strtolower($id) . ']') ||
                    str_contains($msgLower, $idLower)
                ) {
                    $removeIds[] = $id;
                }
            }
        }

        if (!empty($removeIds) && !empty($payload['attributes']) && is_array($payload['attributes'])) {
            $removeIds = array_values(array_unique(array_map(
                fn ($x) => strtoupper(trim((string) $x)),
                $removeIds
            )));

            $payload['attributes'] = $this->removeAttributeIds($payload['attributes'], $removeIds);
        }

        if ($forcePackOne) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $attrs = $this->upsertAttributeValueName($attrs, 'UNITS_PER_PACK', '1');
            $payload['attributes'] = $attrs;
        }

        if ($forceSaleFormatUnidad) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $attrs = $this->upsertAttributeValueName($attrs, 'SALE_FORMAT', 'Unidad');
            $payload['attributes'] = $attrs;
        }

        if ($forceModel) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $attrs = $this->upsertAttributeValueName($attrs, 'MODEL', $this->guessModelFromPayload($payload));
            $payload['attributes'] = $attrs;
        }

        if ($forcePackage) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_HEIGHT', '25 cm');
            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_WIDTH', '8 cm');
            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_LENGTH', '8 cm');
            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_WEIGHT', '500 g');

            $payload['attributes'] = $attrs;
        }

        if ($forcePackageStrong) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_HEIGHT', '30 cm');
            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_WIDTH', '10 cm');
            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_LENGTH', '10 cm');
            $attrs = $this->upsertAttributeValueName($attrs, 'SELLER_PACKAGE_WEIGHT', '1000 g');

            $payload['attributes'] = $attrs;
        }

        if ($dropUnitsPerPack) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $attrs = $this->removeAttributeIds($attrs, ['UNITS_PER_PACK']);
            $attrs = $this->upsertAttributeValueName($attrs, 'SALE_FORMAT', 'Unidad');
            $payload['attributes'] = $attrs;
        }

        if ($dropShippingMode && !empty($payload['shipping']) && is_array($payload['shipping'])) {
            unset($payload['shipping']['mode']);

            if (empty($payload['shipping'])) {
                unset($payload['shipping']);
            }
        }

        return $payload;
    }

    // ==========================
    // PAYLOAD BUILD
    // ==========================
    protected function buildRepublishPayloadFromItem(
        array $item,
        string $newLabel,
        float $newPrice,
        ?string $sellerSku = null,
        bool $keepCatalog = false,
        ?int $officialStoreId = null,
        ?string $newUniversalCode = null
    ): array {
        $categoryId = trim((string) ($item['category_id'] ?? ''));
        if ($categoryId === '') {
            throw new \RuntimeException('La publicación origen no tiene category_id.');
        }

        $isUserProduct = $this->isUserProductItem($item);

        $baseLabel = $isUserProduct
            ? trim((string) ($item['family_name'] ?? $item['title'] ?? ''))
            : trim((string) ($item['title'] ?? $item['family_name'] ?? ''));

        $label = trim((string) preg_replace('/\s+/', ' ', $newLabel));
        if ($label === '') {
            $label = $baseLabel;
        }

        if (mb_strlen($label) > 60) {
            $label = mb_substr($label, 0, 60);
        }

        $familyName = $this->normalizeFamilyName($label, 60);

        $qty = (int) ($item['available_quantity'] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }

        $payload = [
            'category_id'        => $categoryId,
            'family_name'        => $familyName,
            'price'              => round($newPrice, 2),
            'currency_id'        => (string) ($item['currency_id'] ?? 'MXN'),
            'available_quantity' => $qty,
            'buying_mode'        => (string) ($item['buying_mode'] ?? 'buy_it_now'),
            'listing_type_id'    => (string) ($item['listing_type_id'] ?? 'gold_special'),
            'condition'          => (string) ($item['condition'] ?? 'new'),
        ];

        if (!$isUserProduct) {
            $payload['title'] = $label;
        }

        $pictures = $this->normalizeRepublishPictures($item['pictures'] ?? []);
        if (!empty($pictures)) {
            $payload['pictures'] = $pictures;
        }

        $attributes = $this->sanitizeRepublishAttributes(
            is_array($item['attributes'] ?? null) ? $item['attributes'] : [],
            $familyName,
            $isUserProduct,
            $item
        );

        if ($newUniversalCode !== null && $newUniversalCode !== '') {
            $attributes = $this->applyUniversalCodeToAttributes($attributes, $newUniversalCode);
        }

        if (!empty($attributes)) {
            $payload['attributes'] = $attributes;
        }

        $saleTerms = $this->filterRepublishSaleTerms(
            is_array($item['sale_terms'] ?? null) ? $item['sale_terms'] : []
        );

        if (!empty($saleTerms)) {
            $payload['sale_terms'] = $saleTerms;
        }

        $shipping = $this->normalizeRepublishShipping(
            is_array($item['shipping'] ?? null) ? $item['shipping'] : []
        );

        if (!empty($shipping)) {
            $payload['shipping'] = $shipping;
        }

        if ($officialStoreId && $officialStoreId > 0) {
            $payload['official_store_id'] = $officialStoreId;
        }

        if (!empty($item['warranty'])) {
            $payload['warranty'] = (string) $item['warranty'];
        }

        $sellerCustomField = trim((string) ($sellerSku ?: ($item['seller_custom_field'] ?? '')));
        if ($sellerCustomField !== '') {
            $payload['seller_custom_field'] = $sellerCustomField;
        }

        if ($keepCatalog) {
            $catalogProductId = trim((string) ($item['catalog_product_id'] ?? ''));
            if ($catalogProductId !== '') {
                $payload['catalog_product_id'] = $catalogProductId;
            }

            if (array_key_exists('catalog_listing', $item)) {
                $payload['catalog_listing'] = (bool) $item['catalog_listing'];
            }
        }

        return $payload;
    }

    // ==========================
    // HELPERS DE ATRIBUTOS
    // ==========================
    protected function normalizeBarcodeDigits(string $raw): ?string
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

    protected function applyUniversalCodeToAttributes(array $attrs, string $universalCode): array
    {
        $code = $this->normalizeBarcodeDigits($universalCode);
        if ($code === null || $code === '') {
            throw new \RuntimeException(
                'El código universal debe tener 8, 12, 13 o 14 dígitos (GTIN/EAN/UPC).'
            );
        }

        $attrs = $this->removeAttributeIds($attrs, [
            'GTIN',
            'EAN',
            'UPC',
            'EMPTY_GTIN_REASON',
            'EMPTY_EAN_REASON',
        ]);

        $attrs[] = [
            'id' => 'GTIN',
            'value_name' => $code,
        ];

        return array_values($attrs);
    }

    protected function isUsableMlValueId($value): bool
    {
        if ($value === null) {
            return false;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '-1' || $value === '0') {
            return false;
        }

        if (strtolower($value) === 'null') {
            return false;
        }

        return true;
    }

    protected function extractInvalidAttributeId(string $msg): ?string
    {
        if (preg_match('/attribute\s+([A-Z0-9_]+)/i', $msg, $m)) {
            return strtoupper(trim((string) $m[1]));
        }

        if (preg_match('/\[([A-Z0-9_]+)\]/i', $msg, $m)) {
            return strtoupper(trim((string) $m[1]));
        }

        return null;
    }

    protected function mlValueRow(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $out = ['id' => $id];

        $valueId = trim((string) ($row['value_id'] ?? ''));
        $valueName = trim((string) ($row['value_name'] ?? ''));

        if ($this->isUsableMlValueId($valueId)) {
            $out['value_id'] = $valueId;
            return $out;
        }

        if ($valueName !== '') {
            $out['value_name'] = $valueName;
            return $out;
        }

        $values = $row['values'] ?? null;
        if (is_array($values)) {
            foreach ($values as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $nestedId = trim((string) ($value['id'] ?? ''));
                $nestedName = trim((string) ($value['name'] ?? ''));

                if ($this->isUsableMlValueId($nestedId)) {
                    $out['value_id'] = $nestedId;
                    return $out;
                }

                if ($nestedName !== '') {
                    $out['value_name'] = $nestedName;
                    return $out;
                }
            }
        }

        return null;
    }

    protected function normalizeMlRows(array $rows, array $excludeIds = []): array
    {
        $out = [];
        $exclude = [];

        foreach ($excludeIds as $id) {
            $exclude[strtoupper(trim((string) $id))] = true;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = strtoupper(trim((string) ($row['id'] ?? '')));
            if ($id !== '' && isset($exclude[$id])) {
                continue;
            }

            $normalized = $this->mlValueRow($row);
            if ($normalized) {
                $out[] = $normalized;
            }
        }

        return array_values($out);
    }

    protected function removeAttributeIds(array $attrs, array $ids): array
    {
        $remove = [];
        foreach ($ids as $id) {
            $remove[strtoupper(trim((string) $id))] = true;
        }

        return array_values(array_filter($attrs, function ($attr) use ($remove) {
            if (!is_array($attr)) {
                return false;
            }

            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($id === '') {
                return false;
            }

            return !isset($remove[$id]);
        }));
    }

    protected function normalizeRepublishPictures(array $pictures): array
    {
        $out = [];

        foreach ($pictures as $pic) {
            if (!is_array($pic)) {
                continue;
            }

            $url = $pic['secure_url'] ?? $pic['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                $out[] = ['source' => trim($url)];
            }
        }

        return array_slice(array_values($out), 0, 12);
    }

    protected function normalizeRepublishShipping(array $shipping): array
    {
        $out = [];

        foreach (['local_pick_up', 'free_shipping', 'store_pick_up'] as $key) {
            if (array_key_exists($key, $shipping)) {
                $out[$key] = $shipping[$key];
            }
        }

        return $out;
    }

    protected function isUserProductItem(array $item): bool
    {
        $familyName    = trim((string) ($item['family_name'] ?? ''));
        $userProductId = trim((string) ($item['user_product_id'] ?? ''));

        return $familyName !== '' || $userProductId !== '';
    }

    protected function normalizeFamilyName(string $text, int $maxLen = 60): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            $text = 'PUBLICACION';
        }

        if (mb_strlen($text) > $maxLen) {
            $text = mb_substr($text, 0, $maxLen);
        }

        return $text;
    }

    protected function upsertAttributeValueName(array $attrs, string $id, string $valueName): array
    {
        $id = strtoupper(trim($id));
        $valueName = trim($valueName);

        if ($id === '' || $valueName === '') {
            return $attrs;
        }

        $updated = false;

        foreach ($attrs as &$attr) {
            if (!is_array($attr)) {
                continue;
            }

            $currentId = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($currentId === $id) {
                $attr = [
                    'id' => $id,
                    'value_name' => $valueName,
                ];
                $updated = true;
                break;
            }
        }
        unset($attr);

        if (!$updated) {
            $attrs[] = [
                'id' => $id,
                'value_name' => $valueName,
            ];
        }

        return array_values($attrs);
    }

    protected function hasAttribute(array $attrs, string $id): bool
    {
        $id = strtoupper(trim($id));

        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            if (strtoupper(trim((string) ($attr['id'] ?? ''))) === $id) {
                return true;
            }
        }

        return false;
    }

    protected function getAttributeText(array $attr): ?string
    {
        if (!empty($attr['value_name'])) {
            return trim((string) $attr['value_name']);
        }

        if (!empty($attr['value_id'])) {
            return trim((string) $attr['value_id']);
        }

        if (!empty($attr['values'][0]['name'])) {
            return trim((string) $attr['values'][0]['name']);
        }

        if (!empty($attr['values'][0]['id'])) {
            return trim((string) $attr['values'][0]['id']);
        }

        return null;
    }

    protected function findAttributeTextByIds(array $attrs, array $ids): ?string
    {
        $wanted = array_map(fn ($x) => strtoupper(trim((string) $x)), $ids);

        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($id === '' || !in_array($id, $wanted, true)) {
                continue;
            }

            $val = $this->getAttributeText($attr);
            if ($val !== null && $val !== '') {
                return $val;
            }
        }

        return null;
    }

    protected function parseNumberFromText(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $txt = strtolower(trim($raw));
        $txt = str_replace(',', '.', $txt);

        if (!preg_match('/(\d+(?:\.\d+)?)/', $txt, $m)) {
            return null;
        }

        $n = (float) $m[1];
        return $n > 0 ? $n : null;
    }

    protected function parseVolumeToMl(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $txt = strtolower(trim($raw));
        $txt = str_replace(',', '.', $txt);

        if (!preg_match('/(\d+(?:\.\d+)?)/', $txt, $m)) {
            return null;
        }

        $n = (float) $m[1];
        if ($n <= 0) {
            return null;
        }

        if (str_contains($txt, ' l') || str_ends_with($txt, 'l') || str_contains($txt, 'litro')) {
            if (!str_contains($txt, 'ml')) {
                return $n * 1000;
            }
        }

        return $n;
    }

    protected function parseWeightToGrams(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $txt = strtolower(trim($raw));
        $txt = str_replace(',', '.', $txt);

        if (!preg_match('/(\d+(?:\.\d+)?)/', $txt, $m)) {
            return null;
        }

        $n = (float) $m[1];
        if ($n <= 0) {
            return null;
        }

        if (str_contains($txt, 'kg')) {
            return (int) round($n * 1000);
        }

        return (int) round($n);
    }

    protected function resolveDimensionValue(?string $raw, float $minimumCm): string
    {
        $n = $this->parseNumberFromText($raw);
        $n = $n !== null ? max($n, $minimumCm) : $minimumCm;

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . ' cm';
    }

    protected function resolveWeightValue(?string $raw, int $minimumGrams): string
    {
        $grams = $this->parseWeightToGrams($raw);
        $grams = $grams !== null ? max($grams, $minimumGrams) : $minimumGrams;

        return $grams . ' g';
    }

    protected function detectSuggestedPackageProfile(array $rawAttrs): array
    {
        $volumeMl = $this->parseVolumeToMl($this->findAttributeTextByIds($rawAttrs, [
            'TOTAL_CONTENT_VOLUME_KIT',
            'TOTAL_CONTENT_VOLUME',
            'NET_VOLUME',
        ]));

        $weightGrams = $this->parseWeightToGrams($this->findAttributeTextByIds($rawAttrs, [
            'SELLER_PACKAGE_WEIGHT',
            'PACKAGE_WEIGHT',
            'TOTAL_CONTENT_WEIGHT_KIT',
            'TOTAL_CONTENT_WEIGHT',
            'NET_WEIGHT',
        ]));

        $height = 25.0;
        $width  = 8.0;
        $length = 8.0;
        $weight = 500;

        if ($volumeMl !== null) {
            if ($volumeMl <= 100) {
                $height = 18;
                $width  = 6;
                $length = 6;
                $weight = 250;
            } elseif ($volumeMl <= 300) {
                $height = 24;
                $width  = 8;
                $length = 8;
                $weight = 450;
            } elseif ($volumeMl <= 500) {
                $height = 26;
                $width  = 9;
                $length = 9;
                $weight = 700;
            } else {
                $height = 30;
                $width  = 10;
                $length = 10;
                $weight = 1000;
            }
        }

        if ($weightGrams !== null) {
            $weight = max($weight, $weightGrams);
        }

        return [
            'height_cm' => $height,
            'width_cm'  => $width,
            'length_cm' => $length,
            'weight_g'  => $weight,
        ];
    }

    protected function ensureSellerPackageDimensions(array $cleanAttrs, array $rawAttrs): array
    {
        $profile = $this->detectSuggestedPackageProfile($rawAttrs);

        $heightRaw = $this->findAttributeTextByIds($rawAttrs, [
            'SELLER_PACKAGE_HEIGHT',
            'PACKAGE_HEIGHT',
        ]);

        $widthRaw = $this->findAttributeTextByIds($rawAttrs, [
            'SELLER_PACKAGE_WIDTH',
            'PACKAGE_WIDTH',
        ]);

        $lengthRaw = $this->findAttributeTextByIds($rawAttrs, [
            'SELLER_PACKAGE_LENGTH',
            'PACKAGE_LENGTH',
        ]);

        $weightRaw = $this->findAttributeTextByIds($rawAttrs, [
            'SELLER_PACKAGE_WEIGHT',
            'PACKAGE_WEIGHT',
            'TOTAL_CONTENT_WEIGHT_KIT',
            'TOTAL_CONTENT_WEIGHT',
            'NET_WEIGHT',
        ]);

        $cleanAttrs = $this->upsertAttributeValueName(
            $cleanAttrs,
            'SELLER_PACKAGE_HEIGHT',
            $this->resolveDimensionValue($heightRaw, $profile['height_cm'])
        );

        $cleanAttrs = $this->upsertAttributeValueName(
            $cleanAttrs,
            'SELLER_PACKAGE_WIDTH',
            $this->resolveDimensionValue($widthRaw, $profile['width_cm'])
        );

        $cleanAttrs = $this->upsertAttributeValueName(
            $cleanAttrs,
            'SELLER_PACKAGE_LENGTH',
            $this->resolveDimensionValue($lengthRaw, $profile['length_cm'])
        );

        $cleanAttrs = $this->upsertAttributeValueName(
            $cleanAttrs,
            'SELLER_PACKAGE_WEIGHT',
            $this->resolveWeightValue($weightRaw, $profile['weight_g'])
        );

        return array_values($cleanAttrs);
    }

    protected function detectUnitsPerPackFromAttrs(array $rawAttrs): int
    {
        $txt = $this->findAttributeTextByIds($rawAttrs, [
            'UNITS_PER_PACK',
            'TIRES_NUMBER',
        ]);

        if ($txt === null) {
            return 1;
        }

        $txtLower = strtolower(trim($txt));

        foreach (['ml', 'm l', 'g', 'gr', 'kg', 'l ', 'litro', 'oz'] as $unitMarker) {
            if (str_contains($txtLower, $unitMarker)) {
                return 1;
            }
        }

        if (preg_match('/(\d+)/', $txtLower, $m)) {
            $n = (int) $m[1];

            if ($n < 1) {
                return 1;
            }

            if ($n > 24) {
                return 1;
            }

            return $n;
        }

        return 1;
    }

    protected function normalizeSaleFormatAttributes(array $clean, array $rawAttrs): array
    {
        if (!$this->hasAttribute($clean, 'SALE_FORMAT') && !$this->hasAttribute($clean, 'UNITS_PER_PACK')) {
            return $clean;
        }

        $units = $this->detectUnitsPerPackFromAttrs($rawAttrs);
        $saleFormat = $units > 1 ? 'Pack' : 'Unidad';

        $clean = $this->upsertAttributeValueName($clean, 'SALE_FORMAT', $saleFormat);

        if ($units > 1) {
            $clean = $this->upsertAttributeValueName($clean, 'UNITS_PER_PACK', (string) $units);
        } else {
            $clean = $this->removeAttributeIds($clean, ['UNITS_PER_PACK']);
        }

        return array_values($clean);
    }

    protected function normalizeModelAttribute(array $clean, array $rawAttrs): array
    {
        $model = $this->findAttributeTextByIds($rawAttrs, [
            'MODEL',
            'MODEL_NAME',
            'MODEL_ALPHANUMERIC',
            'MODEL_CODE',
        ]);

        if ($model !== null && $model !== '') {
            $clean = $this->upsertAttributeValueName($clean, 'MODEL', $model);
        }

        return array_values($clean);
    }

    protected function guessModelFromPayload(array $payload): string
    {
        $text = trim((string) ($payload['title'] ?? $payload['family_name'] ?? 'MODELO'));
        $text = preg_replace('/\s+/', ' ', $text ?? '');
        $text = trim((string) $text);

        if ($text === '') {
            $text = 'MODELO';
        }

        if (mb_strlen($text) > 60) {
            $text = mb_substr($text, 0, 60);
        }

        return $text;
    }

    protected function filterRepublishSaleTerms(array $saleTerms): array
    {
        return $this->normalizeMlRows($saleTerms, [
            'INSTALLMENTS_CAMPAIGN',
        ]);
    }

    protected function sanitizeRepublishAttributes(
        array $attrs,
        string $familyName,
        bool $isUserProduct,
        array $item = []
    ): array {
        $rawAttrs = is_array($attrs) ? $attrs : [];

        $clean = $this->normalizeMlRows($rawAttrs, [
            'ITEM_CONDITION',
            'CHANNEL',
            'DEAL_IDS',
            'FAMILY_NAME',
            'MANUAL_TITLE',
            'NAME',
            'PACKAGE_HEIGHT',
            'PACKAGE_LENGTH',
            'PACKAGE_WEIGHT',
            'PACKAGE_WIDTH',
            'PRODUCT_FEATURES',
            'TOTAL_CONTENT_VOLUME',
            'TOTAL_CONTENT_WEIGHT',
            'TOTAL_CONTENT_VOLUME_KIT',
            'TOTAL_CONTENT_WEIGHT_KIT',
            'NET_VOLUME',
            'NET_WEIGHT',
            'SHELF_LIFE',
            'HAZMAT_TRANSPORTABILITY',
            'IS_HIGHLIGHT_BRAND',
            'IS_TOM_BRAND',
        ]);

        if ($isUserProduct) {
            $clean = $this->upsertAttributeValueName($clean, 'NAME', $familyName);
        }

        $clean = $this->normalizeSaleFormatAttributes($clean, $rawAttrs);
        $clean = $this->normalizeModelAttribute($clean, $rawAttrs);
        $clean = $this->ensureSellerPackageDimensions($clean, $rawAttrs);

        return array_values($clean);
    }
}