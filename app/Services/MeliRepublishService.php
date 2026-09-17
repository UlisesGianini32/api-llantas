<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class MeliRepublishService
{
    /** @var array<string, array<int, array<string, mixed>>> */
    protected static array $runtimeCategoryAttributes = [];

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

    /**
     * Después de crear una publicación, Mercado Libre puede tardar unos segundos
     * en hacerla consultable mediante GET /items/{mlm}.
     *
     * Reintenta únicamente errores 404 temporales. Si el ítem todavía no está
     * disponible, utiliza la respuesta exitosa del POST /items para no marcar
     * como fallida una publicación que sí fue creada.
     */
    protected function getItemAfterCreate(
        User $user,
        string $mlm,
        array $createdResponse,
        string $context = 'republish'
    ): array {
        $mlm = strtoupper(trim($mlm));
        $delaysMicroseconds = [300000, 700000, 1200000, 2000000];
        $lastException = null;

        foreach ($delaysMicroseconds as $attemptIndex => $delayMicroseconds) {
            try {
                $item = $this->getItem($user, $mlm);

                if (! empty($item['id'])) {
                    return $item;
                }
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if (! $this->isMlNotFoundException($exception)) {
                    throw $exception;
                }

                Log::warning('ML item todavía no disponible después de crear', [
                    'context' => $context,
                    'mlm' => $mlm,
                    'attempt' => $attemptIndex + 1,
                    'wait_microseconds' => $delayMicroseconds,
                    'error' => $exception->getMessage(),
                ]);
            }

            usleep($delayMicroseconds);
        }

        $fallback = $createdResponse;
        $fallback['id'] = $fallback['id'] ?? $mlm;

        Log::warning('ML item no disponible tras reintentos; se usa respuesta del POST', [
            'context' => $context,
            'mlm' => $mlm,
            'last_error' => $lastException?->getMessage(),
        ]);

        return $fallback;
    }

    protected function isMlNotFoundException(\Throwable $exception): bool
    {
        return str_starts_with(trim($exception->getMessage()), 'ML_ERROR:404:');
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

    /**
     * Cierra una publicación y después la elimina definitivamente de Mercado Libre.
     * Mercado Libre exige que el ítem esté cerrado antes de marcarlo como eliminado.
     */
    public function deleteItemPermanently(User $user, string $mlm): array
    {
        $mlm = strtoupper(trim($mlm));

        if ($mlm === '') {
            throw new \RuntimeException('El MLM está vacío.');
        }

        $item = $this->getItem($user, $mlm);
        $sellerId = (string) ($item['seller_id'] ?? '');
        $expectedSellerId = (string) ($user->meli_id ?? '');

        if ($sellerId !== '' && $expectedSellerId !== '' && $sellerId !== $expectedSellerId) {
            throw new \RuntimeException('La publicación no pertenece a la cuenta secundaria seleccionada.');
        }

        $status = strtolower(trim((string) ($item['status'] ?? '')));

        if ($status !== 'closed') {
            $this->updateItem($user, $mlm, ['status' => 'closed'], 'delete.closeItem');
        }

        $deletedItem = $this->updateItem($user, $mlm, ['deleted' => true], 'delete.markDeleted');

        return [
            'mlm' => $mlm,
            'status' => (string) ($deletedItem['status'] ?? 'closed'),
            'deleted' => (bool) ($deletedItem['deleted'] ?? true),
        ];
    }

    protected function updateItem(User $user, string $mlm, array $payload, string $tag): array
    {
        try {
            $res = $this->client()->put("items/{$mlm}", [
                'headers' => $this->headers($user) + ['Content-Type' => 'application/json'],
                'json' => $payload,
            ]);

            return json_decode((string) $res->getBody(), true) ?: [];
        } catch (ClientException $e) {
            $this->throwClientException($e, $tag);
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

        $meliUserId = trim((string) ($item['seller_id'] ?? $user->meli_id ?? ''));
        $meliAccountId = null;

        if ($meliUserId !== '') {
            $meliAccountId = MeliAccount::query()
                ->where('user_id', $user->id)
                ->where('meli_user_id', $meliUserId)
                ->value('id');
        }

        if (! $meliAccountId) {
            $meliAccountId = MeliAccount::query()
                ->where('user_id', $user->id)
                ->orderByDesc('is_default')
                ->value('id');
        }

        return MeliPublication::updateOrCreate(
            ['user_id' => $user->id, 'mlm' => $mlm],
            [
                'meli_account_id' => $meliAccountId,
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

        /*
         * Atributos obligatorios de lista que faltan en la publicación origen.
         * Se muestran en el formulario para evitar reintentos con valores
         * inventados o incompatibles con la categoría vigente.
         */
        $requiredAttributes = $this->buildRepublishRequiredAttributeFields($item);

        return [
            'ml'                       => $sourceMlm,
            'item'                     => $item,
            'pub'                      => $pub,
            'isUserProduct'            => $isUserProduct,
            'defaultLabel'             => $defaultLabel,
            'defaultPrice'             => (float) ($item['price'] ?? 0),
            'defaultOfficialStoreMode' => $defaultOfficialStoreMode,
            'currentUniversalCode'     => $currentUniversalCode,
            'requiredAttributes'       => $requiredAttributes,
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

        /*
         * Conserva el contexto real de la publicación origen. El flujo
         * individual antes construía el payload sin contexto, por lo que los
         * reintentos no podían recuperar atributos obligatorios como BRAND.
         */
        $sourceAttributes = is_array($sourceItem['attributes'] ?? null)
            ? $sourceItem['attributes']
            : [];

        $sourceBrand = $this->findAttributeTextByIds(
            $sourceAttributes,
            ['BRAND']
        ) ?? '';

        $sourceLine = $this->findAttributeTextByIds(
            $sourceAttributes,
            ['LINE', 'PRODUCT_LINE']
        ) ?? '';

        $sourceModel = $this->findAttributeTextByIds(
            $sourceAttributes,
            ['MODEL', 'MODEL_NAME', 'MODEL_ALPHANUMERIC', 'MODEL_CODE']
        ) ?? '';

        $attributeOverrides = $this->normalizeRepublishAttributeOverrides(
            $sourceItem,
            is_array($options['attribute_overrides'] ?? null)
                ? $options['attribute_overrides']
                : []
        );

        $payloadContext = [
            'brand' => trim((string) ($options['brand'] ?? $sourceBrand)),
            'line' => trim((string) ($options['line'] ?? $sourceLine)),
            'model' => trim((string) ($options['model'] ?? ($sourceModel !== '' ? $sourceModel : $newLabel))),
            'title' => $newLabel,
            'source_attributes' => $sourceAttributes,
            'attribute_overrides' => $attributeOverrides,
        ];

        $payload = $this->buildRepublishPayloadFromItem(
            $sourceItem,
            $newLabel,
            $newPrice,
            (string) ($sourceItem['seller_custom_field'] ?? ''),
            $keepCatalog,
            $officialStoreId,
            $newUniversalCode !== '' ? $newUniversalCode : null,
            $payloadContext
        );

        Log::info('ML republish payload prepared', [
            'source_mlm'      => $sourceMlm,
            'is_user_product' => $this->isUserProductItem($sourceItem),
            'payload'         => $payload,
        ]);

        $created = $this->createItemWithFallbacks($user, $payload, $payloadContext);

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

        $newItem = $this->getItemAfterCreate(
            $user,
            $newMlm,
            $created,
            'same-account republish'
        );

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

    /**
     * Republica una publicación desde una cuenta Mercado Libre hacia otra.
     * La lectura se realiza con la cuenta de origen y la creación con la cuenta destino.
     */
    public function republishProductBetweenAccounts(
        User $ownerUser,
        User $sourceApiUser,
        User $destinationApiUser,
        string $sourceMlm,
        array $options = []
    ): array {
        $sourceMlm = strtoupper(trim($sourceMlm));

        if ($sourceMlm === '') {
            throw new \RuntimeException('MLM origen vacío.');
        }

        $sourceItem = $this->getItem($sourceApiUser, $sourceMlm);
        $description = $this->getDescription($sourceApiUser, $sourceMlm);

        if (empty($sourceItem['id'])) {
            throw new \RuntimeException('Mercado Libre no devolvió la publicación original.');
        }

        $sourcePublication = MeliPublication::query()
            ->where('user_id', $ownerUser->id)
            ->where('mlm', $sourceMlm)
            ->first();

        $defaultLabel = $this->isUserProductItem($sourceItem)
            ? trim((string) ($sourceItem['family_name'] ?? $sourceItem['title'] ?? ''))
            : trim((string) ($sourceItem['title'] ?? $sourceItem['family_name'] ?? ''));

        $newLabel = trim((string) ($options['title'] ?? $defaultLabel));
        if ($newLabel === '') {
            $newLabel = $sourceMlm;
        }

        $newPrice = (float) ($options['price'] ?? $sourceItem['price'] ?? 0);
        if ($newPrice <= 0) {
            throw new \RuntimeException('La publicación original no tiene un precio válido.');
        }

        $sku = trim((string) (
            $options['sku']
            ?? $sourcePublication?->sku
            ?? $sourceItem['seller_custom_field']
            ?? $sourceMlm
        ));

        if ($sku === '') {
            $sku = $sourceMlm;
        }

        $keepCatalog = (bool) ($options['keep_catalog'] ?? false);
        $officialStoreId = ! empty($options['official_store_id'])
            ? (int) $options['official_store_id']
            : null;
        $universalCode = trim((string) ($options['universal_code'] ?? ''));

        $payloadContext = [
            'brand' => trim((string) ($options['brand'] ?? '')),
            'line' => trim((string) ($options['line'] ?? '')),
            'model' => trim((string) ($options['model'] ?? $newLabel)),
            'title' => $newLabel,
            'source_attributes' => is_array($sourceItem['attributes'] ?? null) ? $sourceItem['attributes'] : [],
        ];

        $payload = $this->buildRepublishPayloadFromItem(
            $sourceItem,
            $newLabel,
            $newPrice,
            $sku,
            $keepCatalog,
            $officialStoreId,
            $universalCode !== '' ? $universalCode : null,
            $payloadContext
        );

        Log::info('ML cross-account republish payload prepared', [
            'owner_user_id' => $ownerUser->id,
            'source_mlm' => $sourceMlm,
            'source_meli_user_id' => $sourceApiUser->meli_id,
            'destination_meli_user_id' => $destinationApiUser->meli_id,
            'sku' => $sku,
            'payload' => $payload,
        ]);

        $created = $this->createItemWithFallbacks($destinationApiUser, $payload, $payloadContext);
        $newMlm = strtoupper(trim((string) ($created['id'] ?? '')));

        if ($newMlm === '') {
            throw new \RuntimeException('Mercado Libre no devolvió el nuevo MLM.');
        }

        $descriptionWarning = null;
        $plainText = trim((string) ($description['plain_text'] ?? ''));

        if ($plainText !== '') {
            try {
                $this->createDescription($destinationApiUser, $newMlm, $plainText);
            } catch (\Throwable $exception) {
                $descriptionWarning = $this->humanizeMlException($exception);

                Log::warning('ML cross-account description failed', [
                    'source_mlm' => $sourceMlm,
                    'new_mlm' => $newMlm,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $newItem = $this->getItemAfterCreate(
            $destinationApiUser,
            $newMlm,
            $created,
            'cross-account republish'
        );
        $newPublication = $this->upsertPublication($ownerUser, $sku, $newItem);

        $newRaw = is_array($newPublication->raw) ? $newPublication->raw : [];
        $newRaw['copied_from_mlm'] = $sourceMlm;
        $newRaw['copied_from_meli_user_id'] = (string) $sourceApiUser->meli_id;
        $newRaw['published_to_meli_user_id'] = (string) $destinationApiUser->meli_id;
        $newRaw['batch_republished_at'] = now()->toDateTimeString();

        $newPublication->update([
            'source_mlm' => $sourceMlm,
            'raw' => $newRaw,
            'last_sync_at' => now(),
        ]);

        if ($sourcePublication) {
            $sourceRaw = is_array($sourcePublication->raw) ? $sourcePublication->raw : [];
            $republishedItems = is_array($sourceRaw['republished_items'] ?? null)
                ? $sourceRaw['republished_items']
                : [];

            $alreadyRegistered = collect($republishedItems)->contains(
                fn ($row) => is_array($row)
                    && (string) ($row['mlm'] ?? '') === $newMlm
                    && (string) ($row['meli_user_id'] ?? '') === (string) $destinationApiUser->meli_id
            );

            if (! $alreadyRegistered) {
                $republishedItems[] = [
                    'mlm' => $newMlm,
                    'meli_user_id' => (string) $destinationApiUser->meli_id,
                    'created_at' => now()->toDateTimeString(),
                ];
            }

            $sourceRaw['republished_items'] = $republishedItems;
            $sourcePublication->update([
                'raw' => $sourceRaw,
                'last_sync_at' => now(),
            ]);
        }

        return [
            'success' => true,
            'source_mlm' => $sourceMlm,
            'destination_mlm' => $newMlm,
            'sku' => $sku,
            'title' => (string) ($newItem['title'] ?? $newItem['family_name'] ?? $newLabel),
            'status' => (string) ($newItem['status'] ?? 'unknown'),
            'permalink' => (string) ($newItem['permalink'] ?? ''),
            'description_warning' => $descriptionWarning,
        ];
    }

    /**
     * Convierte la excepción técnica de la API en información legible para el frontend.
     */
    public function formatMlException(\Throwable $exception): array
    {
        $originalMessage = trim($exception->getMessage());
        $result = [
            'http_status' => null,
            'code' => 'internal_error',
            'message' => $originalMessage !== '' ? $originalMessage : 'Ocurrió un error desconocido.',
            'causes' => [],
        ];

        if (! str_starts_with($originalMessage, 'ML_ERROR:')) {
            return $result;
        }

        $parts = explode(':', $originalMessage, 3);
        if (count($parts) < 3) {
            return $result;
        }

        $result['http_status'] = is_numeric($parts[1]) ? (int) $parts[1] : null;
        $decoded = json_decode($parts[2], true);

        if (! is_array($decoded)) {
            return $result;
        }

        $result['code'] = (string) ($decoded['error'] ?? $decoded['code'] ?? 'meli_error');
        $apiMessage = (string) ($decoded['message'] ?? $decoded['error'] ?? 'Mercado Libre rechazó la publicación.');
        $result['message'] = $this->translateMlErrorMessage($result['code'], $apiMessage);

        $causes = is_array($decoded['cause'] ?? null) ? $decoded['cause'] : [];
        foreach ($causes as $cause) {
            if (! is_array($cause)) {
                continue;
            }

            $causeCode = (string) ($cause['code'] ?? 'unknown');
            $causeMessage = (string) ($cause['message'] ?? 'Sin detalles adicionales.');

            $result['causes'][] = [
                'code' => $causeCode,
                'message' => $this->translateMlCauseMessage($causeCode, $causeMessage),
                'department' => (string) ($cause['department'] ?? ''),
            ];
        }

        return $result;
    }

    protected function translateMlErrorMessage(string $code, string $message): string
    {
        return match (strtolower(trim($code))) {
            'validation_error' => 'Mercado Libre rechazó uno o más datos de la publicación.',
            'bad_request' => 'La solicitud enviada a Mercado Libre contiene datos inválidos.',
            'forbidden' => 'La cuenta destino no tiene permiso para realizar esta publicación.',
            'not_found' => 'Mercado Libre no encontró el recurso solicitado.',
            default => trim($message) !== '' ? $message : 'Mercado Libre rechazó la publicación.',
        };
    }

    protected function translateMlCauseMessage(string $code, string $message): string
    {
        $attributeId = $this->extractInvalidAttributeId($message);
        $attributeLabel = $attributeId ? $this->attributeLabel($attributeId) : null;
        $lowerCode = strtolower(trim($code));
        $lowerMessage = strtolower($message);

        if (
            $lowerCode === 'invalid.item.attribute.values' ||
            str_contains($lowerMessage, 'item values ([null:null])')
        ) {
            return $attributeLabel
                ? "El atributo {$attributeLabel} ({$attributeId}) no tiene un valor válido. El sistema intentó retirarlo automáticamente; si vuelve a aparecer, deberás definirlo en la publicación original."
                : 'Uno de los atributos no tiene un valor válido. El sistema intentó retirarlo automáticamente.';
        }

        if (str_contains($lowerCode, 'missing') && $attributeLabel) {
            return "Mercado Libre exige completar el atributo {$attributeLabel} ({$attributeId}).";
        }

        return trim($message) !== '' ? $message : 'Sin detalles adicionales.';
    }

    protected function attributeLabel(string $attributeId): string
    {
        return match (strtoupper(trim($attributeId))) {
            'HAIR_SHAMPOO_AND_CONDITIONER_FORMAT' => 'Formato del shampoo o acondicionador',
            'HAIR_TREATMENT_FORMAT' => 'Formato del tratamiento capilar',
            'SALE_FORMAT' => 'Formato de venta',
            'UNITS_PER_PACK' => 'Unidades por paquete',
            'MODEL' => 'Modelo',
            'BRAND' => 'Marca',
            'GTIN', 'EAN', 'UPC' => 'Código universal',
            default => str_replace('_', ' ', $attributeId),
        };
    }

    protected function humanizeMlException(\Throwable $exception): string
    {
        $formatted = $this->formatMlException($exception);
        $messages = array_filter([
            trim((string) ($formatted['message'] ?? '')),
            ...array_map(
                fn (array $cause) => trim((string) ($cause['message'] ?? '')),
                $formatted['causes'] ?? []
            ),
        ]);

        return implode(' | ', array_values(array_unique($messages)));
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
    protected function createItemWithFallbacks(User $user, array $payload, array $context = []): array
    {
        $attemptPayload = $payload;

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            try {
                return $this->createItem($user, $attemptPayload);
            } catch (\Throwable $e) {
                if ($attempt >= 10) {
                    throw $e;
                }

                $parsed = $this->parseMlRuntimeError($e);
                if (!$parsed) {
                    throw $e;
                }

                $adjusted = $this->adjustPayloadFromMlError($attemptPayload, $parsed, $context);

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

    protected function adjustPayloadFromMlError(array $payload, array $error, array $context = []): array
    {
        $causes = is_array($error['cause'] ?? null) ? $error['cause'] : [];

        /* MELI_REPUBLISH_TITLE_USER_PRODUCT_V3
         * Algunas publicaciones antiguas de la cuenta principal usan title,
         * pero la cuenta destino exige User Products. Mercado Libre responde
         * body.invalid_fields con "The fields [title] are invalid..." y cause
         * vacío. Convertimos el payload a family_name + atributo NAME y
         * dejamos que el ciclo normal vuelva a intentar la creación.
         */
        $topLevelErrorText = mb_strtolower(trim(
            (string) ($error['message'] ?? '')
            .' '
            .(string) ($error['error'] ?? '')
        ));

        if (
            str_contains($topLevelErrorText, 'body.invalid_fields')
            && str_contains($topLevelErrorText, 'title')
            && str_contains($topLevelErrorText, 'invalid')
        ) {
            $label = trim((string) ($context['title'] ?? ''));

            if ($label === '') {
                $label = trim((string) ($payload['family_name'] ?? ''));
            }

            if ($label === '') {
                $label = trim((string) ($payload['title'] ?? ''));
            }

            $familyName = $this->normalizeFamilyName(
                $label !== '' ? $label : 'PUBLICACION',
                60
            );

            unset($payload['title']);
            $payload['family_name'] = $familyName;

            $attrs = is_array($payload['attributes'] ?? null)
                ? $payload['attributes']
                : [];

            $payload['attributes'] = $this->upsertAttributeValueName(
                $attrs,
                'NAME',
                $familyName
            );
        }


        $removeIds = [];
        $forcePackOne = false;
        $forcePackage = false;
        $forcePackageStrong = false;
        $forceModel = false;
        $forceSaleFormatUnidad = false;
        $dropShippingMode = false;
        $dropUnitsPerPack = false;
        $forceUnitsPerPackOne = false;
        $forceShippingMe2 = false;
        $removeSaleTermIds = [];
        $requiredAttributeIds = [];
        $discoverConditionalRequired = false;
        // MELI_REPUBLISH_SOURCE_TONE_V4
        $forceHairToneFromSource = false;

        foreach ($causes as $cause) {
            $code = strtolower(trim((string) ($cause['code'] ?? '')));
            $msg = trim((string) ($cause['message'] ?? ''));
            $msgLower = strtolower($msg);

            /*
             * MELI_REPUBLISH_SOURCE_TONE_V4
             * Algunas publicaciones antiguas sí tienen HAIR_TONE, pero el
             * saneamiento inicial puede omitirlo. Si Mercado Libre exige el
             * campo "Tono", lo recuperamos exactamente de la publicación
             * original en vez de inventar una opción.
             */
            if (
                $code === 'item.attribute.missing_catalog_required'
                && (
                    str_contains($msgLower, '"tono"')
                    || str_contains($msgLower, "'tono'")
                    || str_contains($msgLower, 'campo tono')
                    || str_contains($msgLower, 'field "tone"')
                    || str_contains($msgLower, "field 'tone'")
                )
            ) {
                $forceHairToneFromSource = true;
            }


            if ($code === 'sale_term.not_allowed') {
                if (preg_match('/sale term\s+([A-Z0-9_]+)/i', $msg, $match)) {
                    $removeSaleTermIds[] = strtoupper($match[1]);
                }
            }

            if ($code === 'item.attributes.ignored') {
                $ignoredId = $this->extractInvalidAttributeId($msg);
                if ($ignoredId && $ignoredId !== 'UNITS_PER_PACK') {
                    $removeIds[] = $ignoredId;
                }
            }

            if ($code === 'item.attribute.dropped' && str_contains($msgLower, 'units_per_pack')) {
                $forceUnitsPerPackOne = true;
            }

            if ($code === 'shipping.me2_adoption_mandatory' || str_contains($msgLower, 'me2 adoption is mandatory')) {
                $forceShippingMe2 = true;
            }

            if ($code === 'create.item.attribute.business_conditional' && str_contains($msgLower, 'units_per_pack')) {
                $forceUnitsPerPackOne = true;
            }

            if ($code === 'item.attribute.invalid_sale_units' || $code === 'item.attribute.number_invalid_format') {
                if (str_contains($msgLower, 'unidades por') || str_contains($msgLower, 'units per')) {
                    $forceUnitsPerPackOne = true;
                }
            }

            if (
                $code === 'item.attribute.missing_catalog_required' ||
                $code === 'item.attributes.missing_required' ||
                $code === 'item.attribute.missing_conditional_required'
            ) {
                $requiredId = $this->extractRequiredAttributeId($msg);
                if ($requiredId) {
                    $requiredAttributeIds[] = $requiredId;
                } else {
                    // Algunos mensajes de ML dicen literalmente "Attribute is required".
                    // La palabra inglesa "is" NO es el id de un atributo.
                    $discoverConditionalRequired = true;
                }
            }

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
                $forceShippingMe2 = true;
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

            if (
                $code === 'item.attribute.invalid' ||
                $code === 'invalid.item.attribute.values' ||
                str_contains($code, 'attribute.invalid') ||
                str_contains($code, 'invalid.item.attribute')
            ) {
                $invalidId = $this->extractInvalidAttributeId($msg);

                /*
                 * Mercado Libre puede devolver atributos heredados con valores
                 * vacíos, por ejemplo: item values ([null:null]). En ese caso
                 * retiramos únicamente ese atributo y reintentamos la creación.
                 * SALE_FORMAT conserva su corrección especial a "Unidad".
                 */
                if ($invalidId && !($forceSaleFormatUnidad && $invalidId === 'SALE_FORMAT')) {
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

        if ($forcePackOne || $forceUnitsPerPackOne) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $attrs = $this->upsertAttributeValueName($attrs, 'SALE_FORMAT', 'Unidad');
            $attrs = $this->upsertNumericAttribute($attrs, 'UNITS_PER_PACK', 1);
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
            $attrs = $this->upsertAttributeValueName($attrs, 'SALE_FORMAT', 'Unidad');
            $attrs = $this->upsertNumericAttribute($attrs, 'UNITS_PER_PACK', 1);
            $payload['attributes'] = $attrs;
        }

        if ($dropShippingMode && !empty($payload['shipping']) && is_array($payload['shipping'])) {
            unset($payload['shipping']['mode']);

            if (empty($payload['shipping'])) {
                unset($payload['shipping']);
            }
        }

        if ($forceShippingMe2) {
            $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
            $shipping['mode'] = 'me2';
            $payload['shipping'] = $shipping;
        }

        if (!empty($removeSaleTermIds) && is_array($payload['sale_terms'] ?? null)) {
            $payload['sale_terms'] = $this->removeRowsById($payload['sale_terms'], $removeSaleTermIds);
            if (empty($payload['sale_terms'])) {
                unset($payload['sale_terms']);
            }
        }

        if ($discoverConditionalRequired) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            $attrs = $this->fillMissingConditionalAttributes(
                $attrs,
                $context,
                (string) ($payload['category_id'] ?? '')
            );
            $payload['attributes'] = $attrs;
        }

        if (!empty($requiredAttributeIds)) {
            $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
            foreach (array_unique($requiredAttributeIds) as $requiredId) {
                $attrs = $this->fillRequiredAttribute($attrs, $requiredId, $context, (string) ($payload['category_id'] ?? ''));
            }
            $payload['attributes'] = $attrs;
        }


        /*
         * MELI_REPUBLISH_SOURCE_TONE_V4
         * Copia HAIR_TONE desde source_attributes conservando value_id y
         * value_name. Ejemplo real: 25453544 / 7 Tonos de aclaracion.
         */
        if ($forceHairToneFromSource) {
            $sourceAttributes = is_array($context['source_attributes'] ?? null)
                ? $context['source_attributes']
                : [];

            $sourceTone = null;

            foreach ($sourceAttributes as $sourceAttribute) {
                if (! is_array($sourceAttribute)) {
                    continue;
                }

                $sourceId = strtoupper(trim((string) ($sourceAttribute['id'] ?? '')));
                $sourceName = mb_strtolower(trim((string) ($sourceAttribute['name'] ?? '')));

                if (
                    $sourceId === 'HAIR_TONE'
                    || $sourceName === 'tono'
                    || $sourceName === 'tone'
                ) {
                    $sourceTone = $sourceAttribute;
                    break;
                }
            }

            if (is_array($sourceTone)) {
                $toneRow = $this->mlValueRow($sourceTone);

                if (is_array($toneRow)) {
                    $attrs = is_array($payload['attributes'] ?? null)
                        ? $payload['attributes']
                        : [];

                    $attrs = $this->removeAttributeIds($attrs, ['HAIR_TONE']);
                    $attrs[] = $toneRow;
                    $payload['attributes'] = array_values($attrs);

                    Log::info('ML republish: HAIR_TONE recuperado de la publicación origen', [
                        'value_id' => $toneRow['value_id'] ?? null,
                        'value_name' => $toneRow['value_name'] ?? null,
                    ]);
                }
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
        ?string $newUniversalCode = null,
        array $context = []
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

        return $this->normalizePayloadV4($payload, $context);
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
        // Formatos seguros devueltos por Mercado Libre:
        // Attribute [UNITS_PER_PACK], Attribute: GTIN, atributo BRAND.
        if (preg_match('/attribute\s*\[([A-Z][A-Z0-9_]{1,})\]/', $msg, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/attribute\s*:\s*([A-Z][A-Z0-9_]{1,})\b/', $msg, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/atributo\s+([A-Z][A-Z0-9_]{2,})\b/', $msg, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\[([A-Z][A-Z0-9_]{1,})\]/', $msg, $m)) {
            return strtoupper($m[1]);
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
            'PURCHASE_MAX_QUANTITY',
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
            'SHIPMENT_PACKING',
        ]);

        if ($isUserProduct) {
            $clean = $this->upsertAttributeValueName($clean, 'NAME', $familyName);
        }

        $clean = $this->normalizeSaleFormatAttributes($clean, $rawAttrs);
        $clean = $this->normalizeModelAttribute($clean, $rawAttrs);
        $clean = $this->ensureSellerPackageDimensions($clean, $rawAttrs);

        return array_values($clean);
    }

    /**
     * Normalizador V3: elimina campos heredados no modificables y completa
     * datos básicos antes del primer intento de publicación.
     */
    protected function normalizePayloadV4(array $payload, array $context = []): array
    {
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $attrs = $this->removeAttributeIds($attrs, ['SHIPMENT_PACKING']);

        $brand = trim((string) ($context['brand'] ?? ''));
        $line = trim((string) ($context['line'] ?? ''));
        $model = trim((string) ($context['model'] ?? $context['title'] ?? ''));

        if ($brand !== '') {
            $attrs = $this->upsertAttributeValueName($attrs, 'BRAND', $brand);
        }
        if ($line !== '') {
            $attrs = $this->upsertAttributeValueName($attrs, 'LINE', $line);
        }
        if ($model !== '') {
            $attrs = $this->upsertAttributeValueName($attrs, 'MODEL', $model);
        }

        /*
         * Los valores seleccionados por el usuario tienen prioridad sobre el
         * atributo original y sobre cualquier inferencia de los fallbacks.
         */
        foreach (($context['attribute_overrides'] ?? []) as $attributeId => $override) {
            $attributeId = strtoupper(trim((string) $attributeId));
            if ($attributeId === '' || !is_array($override)) {
                continue;
            }

            $valueId = trim((string) ($override['value_id'] ?? ''));
            $valueName = trim((string) ($override['value_name'] ?? ''));

            if ($valueId === '' && $valueName === '') {
                continue;
            }

            $attrs = $this->removeAttributeIds($attrs, [$attributeId]);
            $row = ['id' => $attributeId];

            if ($valueId !== '') {
                $row['value_id'] = $valueId;
            } else {
                $row['value_name'] = $valueName;
            }

            $attrs[] = $row;
        }

        if ($this->attributeValueEquals($attrs, 'SALE_FORMAT', 'Unidad')) {
            $attrs = $this->upsertNumericAttribute($attrs, 'UNITS_PER_PACK', 1);
        }

        $categoryId = trim((string) ($payload['category_id'] ?? ''));
        $attrs = $this->normalizeAttributesForCategory($attrs, $categoryId, $context);
        $payload['attributes'] = array_values($attrs);

        if (is_array($payload['sale_terms'] ?? null)) {
            $payload['sale_terms'] = $this->removeRowsById($payload['sale_terms'], ['PURCHASE_MAX_QUANTITY']);
            if (empty($payload['sale_terms'])) {
                unset($payload['sale_terms']);
            }
        }

        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $shipping['mode'] = 'me2';
        $payload['shipping'] = $shipping;

        return $payload;
    }

    protected function upsertNumericAttribute(array $attrs, string $id, int|float $number, string $unit = ''): array
    {
        $id = strtoupper(trim($id));
        $attrs = $this->removeAttributeIds($attrs, [$id]);

        // Mercado Libre acepta los atributos numéricos del dominio como value_name.
        // UNITS_PER_PACK, por ejemplo, es rechazado cuando se manda como value_struct.
        $value = rtrim(rtrim(number_format((float) $number, 4, '.', ''), '0'), '.');
        if ($value === '') {
            $value = '0';
        }

        if ($unit !== '') {
            $value .= ' ' . trim($unit);
        }

        $attrs[] = [
            'id' => $id,
            'value_name' => $value,
        ];

        return array_values($attrs);
    }

    /**
     * V4: reconstruye los atributos usando únicamente la definición vigente
     * de la categoría. Evita copiar atributos internos, vacíos o no editables.
     */
    protected function normalizeAttributesForCategory(array $attrs, string $categoryId, array $context = []): array
    {
        $definitions = $this->getCategoryAttributeDefinitions($categoryId);
        if (empty($definitions)) {
            return array_values($attrs);
        }

        $byId = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $id = strtoupper(trim((string) ($definition['id'] ?? '')));
            if ($id !== '') {
                $byId[$id] = $definition;
            }
        }

        $clean = [];
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if ($id === '' || !isset($byId[$id])) {
                continue;
            }

            $tags = is_array($byId[$id]['tags'] ?? null) ? $byId[$id]['tags'] : [];
            if (($tags['read_only'] ?? false) || ($tags['hidden'] ?? false)) {
                continue;
            }

            $normalized = $this->normalizeAttributeByDefinition($attr, $byId[$id]);
            if ($normalized !== null) {
                $clean[] = $normalized;
            }
        }

        // Datos de negocio que sí conocemos localmente.
        foreach ([
            'BRAND' => trim((string) ($context['brand'] ?? '')),
            'LINE' => trim((string) ($context['line'] ?? '')),
            'MODEL' => trim((string) ($context['model'] ?? $context['title'] ?? '')),
        ] as $id => $value) {
            if ($value !== '' && isset($byId[$id])) {
                $clean = $this->upsertAttributeValueName($clean, $id, $value);
            }
        }

        // La regla de Mercado Libre para venta por unidad exige ambas propiedades.
        if (isset($byId['SALE_FORMAT'])) {
            $clean = $this->upsertAttributeValueName($clean, 'SALE_FORMAT', 'Unidad');
        }
        if (isset($byId['UNITS_PER_PACK'])) {
            $clean = $this->upsertNumericAttribute($clean, 'UNITS_PER_PACK', 1);
        }

        // Completa obligatorios únicamente cuando existe un valor seguro.
        foreach ($byId as $id => $definition) {
            $tags = is_array($definition['tags'] ?? null) ? $definition['tags'] : [];
            $required = (bool) ($tags['required'] ?? false)
                || (bool) ($tags['catalog_required'] ?? false)
                || (bool) ($tags['conditional_required'] ?? false);

            if (!$required || $this->hasAttribute($clean, $id)) {
                continue;
            }

            $clean = $this->fillRequiredAttribute($clean, $id, $context, $categoryId);
        }

        return array_values($clean);
    }

    protected function normalizeAttributeByDefinition(array $attr, array $definition): ?array
    {
        $id = strtoupper(trim((string) ($definition['id'] ?? $attr['id'] ?? '')));
        if ($id === '') {
            return null;
        }

        $valueId = trim((string) ($attr['value_id'] ?? ''));
        $valueName = trim((string) ($attr['value_name'] ?? ''));

        if ($valueName === '' && is_array($attr['values'] ?? null)) {
            $valueName = trim((string) ($attr['values'][0]['name'] ?? ''));
            $valueId = trim((string) ($attr['values'][0]['id'] ?? $valueId));
        }

        if ($valueName === '' && is_array($attr['value_struct'] ?? null)) {
            $number = $attr['value_struct']['number'] ?? null;
            $unit = trim((string) ($attr['value_struct']['unit'] ?? ''));
            if (is_numeric($number)) {
                $valueName = (string) $number . ($unit !== '' ? ' ' . $unit : '');
            }
        }

        if ($valueName === '' && !$this->isUsableMlValueId($valueId)) {
            return null;
        }

        $allowedValues = is_array($definition['values'] ?? null) ? $definition['values'] : [];
        if ($this->isUsableMlValueId($valueId)) {
            foreach ($allowedValues as $allowed) {
                if ((string) ($allowed['id'] ?? '') === $valueId) {
                    return ['id' => $id, 'value_id' => $valueId];
                }
            }
        }

        if (!empty($allowedValues) && $valueName !== '') {
            foreach ($allowedValues as $allowed) {
                $allowedName = trim((string) ($allowed['name'] ?? ''));
                if ($allowedName !== '' && mb_strtolower($allowedName) === mb_strtolower($valueName)) {
                    $allowedId = trim((string) ($allowed['id'] ?? ''));
                    return $this->isUsableMlValueId($allowedId)
                        ? ['id' => $id, 'value_id' => $allowedId]
                        : ['id' => $id, 'value_name' => $allowedName];
                }
            }
        }

        return $valueName !== '' ? ['id' => $id, 'value_name' => $valueName] : null;
    }

    protected function getCategoryAttributeDefinitions(string $categoryId): array
    {
        $categoryId = trim($categoryId);

        if ($categoryId === '') {
            return [];
        }

        if (array_key_exists($categoryId, self::$runtimeCategoryAttributes)) {
            return self::$runtimeCategoryAttributes[$categoryId];
        }

        try {
            $res = $this->client()->get("categories/{$categoryId}/attributes", [
                'headers' => ['Accept' => 'application/json'],
            ]);

            $json = json_decode((string) $res->getBody(), true);
            self::$runtimeCategoryAttributes[$categoryId] = is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            Log::warning('ML V5 category attributes lookup failed', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);

            self::$runtimeCategoryAttributes[$categoryId] = [];
        }

        return self::$runtimeCategoryAttributes[$categoryId];
    }

    protected function attributeValueEquals(array $attrs, string $id, string $expected): bool
    {
        $id = strtoupper(trim($id));
        $expected = mb_strtolower(trim($expected));
        foreach ($attrs as $attr) {
            if (!is_array($attr) || strtoupper(trim((string) ($attr['id'] ?? ''))) !== $id) {
                continue;
            }
            $value = mb_strtolower(trim((string) ($attr['value_name'] ?? '')));
            return $value === $expected;
        }
        return false;
    }

    protected function removeRowsById(array $rows, array $ids): array
    {
        $remove = array_fill_keys(array_map(fn ($id) => strtoupper(trim((string) $id)), $ids), true);
        return array_values(array_filter($rows, function ($row) use ($remove) {
            if (!is_array($row)) {
                return false;
            }
            $id = strtoupper(trim((string) ($row['id'] ?? '')));
            return $id !== '' && !isset($remove[$id]);
        }));
    }

    protected function extractRequiredAttributeId(string $message): ?string
    {
        // No usar una expresión insensible a mayúsculas aquí. El mensaje
        // genérico inglés "Attribute is required" hacía que "is" se
        // interpretara erróneamente como el identificador IS.
        if (preg_match('/attribute\s*\[([A-Z][A-Z0-9_]{1,})\]/', $message, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/attribute\s*:\s*([A-Z][A-Z0-9_]{1,})\b/', $message, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/atributo\s+([A-Z][A-Z0-9_]{2,})\b/', $message, $m)) {
            return strtoupper($m[1]);
        }

        $lower = mb_strtolower($message);
        if (str_contains($lower, '"marca"') || str_contains($lower, "'marca'")) {
            return 'BRAND';
        }
        if (str_contains($lower, '"línea"') || str_contains($lower, '"linea"') || str_contains($lower, "'line'")) {
            return 'LINE';
        }

        return null;
    }


    /**
     * Devuelve atributos obligatorios con opciones cerradas que todavía no
     * tienen un valor utilizable en la publicación original.
     */
    protected function buildRepublishRequiredAttributeFields(array $item): array
    {
        $categoryId = trim((string) ($item['category_id'] ?? ''));
        if ($categoryId === '') {
            return [];
        }

        $definitions = $this->getCategoryAttributeDefinitions($categoryId);
        if (empty($definitions)) {
            return [];
        }

        $sourceAttributes = is_array($item['attributes'] ?? null)
            ? $item['attributes']
            : [];

        $ignored = array_fill_keys([
            'ITEM_CONDITION',
            'NAME',
            'BRAND',
            'LINE',
            'MODEL',
            'GTIN',
            'EAN',
            'UPC',
            'EMPTY_GTIN_REASON',
            'EMPTY_EAN_REASON',
            'SALE_FORMAT',
            'UNITS_PER_PACK',
            'SELLER_PACKAGE_HEIGHT',
            'SELLER_PACKAGE_WIDTH',
            'SELLER_PACKAGE_LENGTH',
            'SELLER_PACKAGE_WEIGHT',
        ], true);

        $label = trim((string) (
            $item['family_name']
            ?? $item['title']
            ?? ''
        ));

        $fields = [];

        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $id = strtoupper(trim((string) ($definition['id'] ?? '')));
            if ($id === '' || isset($ignored[$id])) {
                continue;
            }

            $tags = is_array($definition['tags'] ?? null)
                ? $definition['tags']
                : [];

            $required = (bool) ($tags['required'] ?? false)
                || (bool) ($tags['catalog_required'] ?? false)
                || (bool) ($tags['conditional_required'] ?? false);

            if (!$required || ($tags['hidden'] ?? false) || ($tags['read_only'] ?? false)) {
                continue;
            }

            $options = [];
            foreach (($definition['values'] ?? []) as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $valueId = trim((string) ($value['id'] ?? ''));
                $valueName = trim((string) ($value['name'] ?? ''));

                if ($valueId === '' && $valueName === '') {
                    continue;
                }

                $options[] = [
                    'id' => $valueId !== '' ? $valueId : $valueName,
                    'name' => $valueName !== '' ? $valueName : $valueId,
                ];
            }

            if (empty($options)) {
                continue;
            }

            $currentValue = $this->findAttributeTextByIds($sourceAttributes, [$id]) ?? '';
            if (trim($currentValue) !== '') {
                // El valor original se conserva automáticamente; no se pide otra vez.
                continue;
            }

            $fields[] = [
                'id' => $id,
                'name' => trim((string) ($definition['name'] ?? $id)),
                'required' => true,
                'value_type' => (string) ($definition['value_type'] ?? ''),
                'options' => $options,
                'default_value_id' => $this->inferRepublishRequiredAttributeDefault(
                    $id,
                    $label,
                    $options
                ),
            ];
        }

        return array_values($fields);
    }

    /**
     * Valida las selecciones del formulario contra las opciones vigentes de
     * Mercado Libre y las normaliza como value_id/value_name.
     */
    protected function normalizeRepublishAttributeOverrides(array $item, array $overrides): array
    {
        $fields = $this->buildRepublishRequiredAttributeFields($item);
        if (empty($fields)) {
            return [];
        }

        $normalized = [];
        $missing = [];

        foreach ($fields as $field) {
            $id = strtoupper(trim((string) ($field['id'] ?? '')));
            if ($id === '') {
                continue;
            }

            $selected = trim((string) (
                $overrides[$id]
                ?? $overrides[strtolower($id)]
                ?? $field['default_value_id']
                ?? ''
            ));

            if ($selected === '') {
                $missing[] = (string) ($field['name'] ?? $id);
                continue;
            }

            $matched = null;
            foreach (($field['options'] ?? []) as $option) {
                $optionId = trim((string) ($option['id'] ?? ''));
                $optionName = trim((string) ($option['name'] ?? ''));

                if (
                    $selected === $optionId
                    || mb_strtolower($selected) === mb_strtolower($optionName)
                ) {
                    $matched = [
                        'value_id' => $optionId,
                        'value_name' => $optionName,
                    ];
                    break;
                }
            }

            if ($matched === null) {
                throw new \RuntimeException(
                    'El valor seleccionado para "' . ($field['name'] ?? $id) . '" ya no es válido en Mercado Libre. Recarga el formulario.'
                );
            }

            $normalized[$id] = $matched;
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Debes seleccionar: ' . implode(', ', $missing) . '.'
            );
        }

        return $normalized;
    }

    /**
     * Sugiere valores obvios a partir del nombre. Siempre se muestran en el
     * formulario para que el usuario pueda revisarlos antes de publicar.
     */
    protected function inferRepublishRequiredAttributeDefault(
        string $attributeId,
        string $label,
        array $options
    ): string {
        $attributeId = strtoupper(trim($attributeId));
        $text = mb_strtolower(' ' . trim($label) . ' ');
        $preferredNames = [];

        if ($attributeId === 'SUPPLEMENT_FORMAT') {
            if (preg_match('/\b(tab|tabs|tableta|tabletas)\b/u', $text)) {
                $preferredNames = ['Tabletas'];
            } elseif (preg_match('/\b(comprimido|comprimidos)\b/u', $text)) {
                $preferredNames = ['Comprimidos'];
            } elseif (preg_match('/\b(caps|capsula|cápsula|capsulas|cápsulas|softgel|softgels)\b/u', $text)) {
                $preferredNames = ['Cápsula'];
            } elseif (str_contains($text, 'polvo')) {
                $preferredNames = ['Polvo'];
            } elseif (str_contains($text, 'aceite')) {
                $preferredNames = ['Aceite'];
            } elseif (str_contains($text, 'gel')) {
                $preferredNames = ['Gel'];
            } elseif (str_contains($text, ' té ') || str_contains($text, ' te ')) {
                $preferredNames = ['Té'];
            }
        }

        if ($attributeId === 'SUPPLEMENT_CLASS') {
            if (str_contains($text, 'creatina')) {
                $preferredNames = ['Creatina'];
            } elseif (str_contains($text, 'colageno') || str_contains($text, 'colágeno')) {
                $preferredNames = ['Colágeno'];
            } elseif (str_contains($text, 'proteina') || str_contains($text, 'proteína')) {
                $preferredNames = ['Proteínas'];
            } elseif (str_contains($text, 'omega') || str_contains($text, 'fish oil') || str_contains($text, 'aceite de pescado')) {
                $preferredNames = ['Omegas/Aceites de pescado'];
            } elseif (str_contains($text, 'probiot') || str_contains($text, 'prebiot')) {
                $preferredNames = ['Probióticos/Prebióticos'];
            } elseif (str_contains($text, 'amino')) {
                $preferredNames = ['Aminoácidos'];
            } elseif (str_contains($text, 'vitamina') || str_contains($text, 'mineral')) {
                $preferredNames = ['Vitaminas/Multivitamínicos/Minerales'];
            } elseif (str_contains($text, 'herbal') || str_contains($text, 'hierba')) {
                $preferredNames = ['Herbales'];
            } elseif (str_contains($text, 'carbo')) {
                $preferredNames = ['Carbohidratos'];
            } elseif (str_contains($text, 'dhea')) {
                $preferredNames = ['Otros'];
            }
        }

        foreach ($preferredNames as $preferredName) {
            foreach ($options as $option) {
                if (
                    mb_strtolower(trim((string) ($option['name'] ?? '')))
                    === mb_strtolower($preferredName)
                ) {
                    return trim((string) ($option['id'] ?? ''));
                }
            }
        }

        return '';
    }

    protected function fillMissingConditionalAttributes(
        array $attrs,
        array $context,
        string $categoryId
    ): array {
        if ($categoryId === '') {
            return $attrs;
        }

        $definitions = $this->getCategoryAttributeDefinitions($categoryId);
        if (empty($definitions)) {
            return $attrs;
        }

        $sourceById = [];
        foreach (($context['source_attributes'] ?? []) as $sourceAttribute) {
            if (!is_array($sourceAttribute)) {
                continue;
            }
            $sourceId = strtoupper(trim((string) ($sourceAttribute['id'] ?? '')));
            if ($sourceId !== '') {
                $sourceById[$sourceId] = $sourceAttribute;
            }
        }

        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $id = strtoupper(trim((string) ($definition['id'] ?? '')));
            if ($id === '' || $this->hasAttribute($attrs, $id)) {
                continue;
            }

            $tags = is_array($definition['tags'] ?? null) ? $definition['tags'] : [];
            $isConditional = (bool) ($tags['conditional_required'] ?? false)
                || (bool) ($tags['required'] ?? false)
                || (bool) ($tags['catalog_required'] ?? false);

            if (!$isConditional) {
                continue;
            }

            // Primero reutiliza el valor real de la publicación original.
            if (isset($sourceById[$id])) {
                $normalized = $this->normalizeAttributeByDefinition($sourceById[$id], $definition);
                if ($normalized !== null) {
                    $attrs[] = $normalized;
                    continue;
                }
            }

            // Después usa datos de negocio conocidos o valores seguros.
            $before = count($attrs);
            $attrs = $this->fillRequiredAttribute($attrs, $id, $context, $categoryId);
            if (count($attrs) > $before) {
                continue;
            }

            $values = is_array($definition['values'] ?? null) ? $definition['values'] : [];
            foreach ($values as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateName = trim((string) ($candidate['name'] ?? ''));
                $candidateId = trim((string) ($candidate['id'] ?? ''));
                if (in_array(mb_strtolower($candidateName), ['no', 'no aplica', 'ninguno'], true)) {
                    $attrs[] = $candidateId !== ''
                        ? ['id' => $id, 'value_id' => $candidateId]
                        : ['id' => $id, 'value_name' => $candidateName];
                    break;
                }
            }
        }

        return array_values($attrs);
    }

    protected function fillRequiredAttribute(array $attrs, string $id, array $context, string $categoryId): array
    {
        $id = strtoupper(trim($id));
        if ($this->hasAttribute($attrs, $id)) {
            return $attrs;
        }

        $value = match ($id) {
            'BRAND' => trim((string) ($context['brand'] ?? '')),
            'LINE' => trim((string) ($context['line'] ?? '')),
            'MODEL' => trim((string) ($context['model'] ?? $context['title'] ?? '')),
            default => '',
        };

        if ($value !== '') {
            return $this->upsertAttributeValueName($attrs, $id, $value);
        }

        $definition = $this->getCategoryAttributeDefinition($categoryId, $id);
        $values = is_array($definition['values'] ?? null) ? $definition['values'] : [];
        foreach ($values as $candidate) {
            $name = trim((string) ($candidate['name'] ?? ''));
            if (mb_strtolower($name) === 'no') {
                return $this->upsertAttributeValueName($attrs, $id, $name);
            }
        }

        return $attrs;
    }


    /**
     * Infere el valor del atributo condicional IS.
     * Mercado Libre lo expone como Sí/No en varias categorías de belleza.
     */
    protected function inferIsAttributeValue(array $context): string
    {
        $title = mb_strtolower(trim((string) ($context['title'] ?? $context['model'] ?? '')));

        if ($title === '') {
            return 'No';
        }

        $kitSignals = [
            ' kit ',
            'pack',
            'paquete',
            'combo',
            'set ',
            'juego',
            'caja ',
            '2 pz',
            '2pz',
            '3 pz',
            '3pz',
            '4 pz',
            '4pz',
            '5x',
            '6x',
            '12 pz',
            '12pz',
            ' + ',
        ];

        $haystack = ' ' . $title . ' ';
        foreach ($kitSignals as $signal) {
            if (str_contains($haystack, $signal)) {
                return 'Sí';
            }
        }

        return 'No';
    }

    protected function getCategoryAttributeDefinition(string $categoryId, string $attributeId): array
    {
        $categoryId = trim($categoryId);
        $attributeId = strtoupper(trim($attributeId));

        if ($categoryId === '' || $attributeId === '') {
            return [];
        }

        foreach ($this->getCategoryAttributeDefinitions($categoryId) as $definition) {
            if (
                is_array($definition)
                && strtoupper((string) ($definition['id'] ?? '')) === $attributeId
            ) {
                return $definition;
            }
        }

        return [];
    }
}