<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Services\MercadoLibre\LinkedPublications\MeliLinkedPublicationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MeliPriceManagerSyncService
{
    private const SEARCH_LIMIT = 100;

    private const MULTIGET_LIMIT = 20;

    private const MAX_ERROR_DETAILS = 10;

    private const MAX_RAW_ITEM_BYTES = 1_000_000;

    public function __construct(
        private readonly MeliAccountApiClient $api,
        private readonly MeliBrandNormalizer $brandNormalizer,
        private readonly MeliLinkedPublicationService $linkedPublications,
    ) {}

    /**
     * @return array{
     *     total_found: int,
     *     processed: int,
     *     created: int,
     *     updated: int,
     *     failed: int,
     *     error_details: list<array{meli_item_id: string, http_status: int|null, message: string, exception_class: string|null}>,
     *     started_at: string,
     *     finished_at: string
     * }
     */
    public function syncAccount(MeliAccount $account): array
    {
        if (! $account->exists) {
            throw new RuntimeException('La cuenta de Mercado Libre debe existir antes de sincronizarse.');
        }

        $sellerId = trim((string) $account->meli_user_id);
        if ($sellerId === '') {
            throw new RuntimeException('La cuenta de Mercado Libre no tiene meli_user_id.');
        }

        $startedAt = now();

        Log::info('[MeliPriceManager] Starting sync', [
            'meli_account_id' => (int) $account->id,
            'meli_user_id' => $sellerId,
        ]);

        $this->api->ensureFreshAccessToken($account);
        $itemIds = $this->discoverAllItemIds($account, $sellerId);

        Log::info('[MeliPriceManager] Items found', [
            'meli_account_id' => (int) $account->id,
            'total_found' => count($itemIds),
        ]);

        $summary = [
            'total_found' => count($itemIds),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'error_details' => [],
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => $startedAt->toDateTimeString(),
        ];

        foreach (array_chunk($itemIds, self::MULTIGET_LIMIT) as $itemIdChunk) {
            $entries = $this->fetchItemEntries($account, $itemIdChunk);

            foreach ($itemIdChunk as $itemId) {
                $summary['processed']++;
                $entry = $entries[$itemId] ?? [
                    'code' => 0,
                    'body' => ['message' => 'Mercado Libre no devolvió el item solicitado.'],
                ];

                $status = (int) ($entry['code'] ?? 0);
                $item = $entry['body'] ?? null;
                $exception = $entry['exception'] ?? null;

                if ($status !== 200 || ! is_array($item) || trim((string) ($item['id'] ?? '')) === '') {
                    $message = $exception instanceof Throwable
                        ? $exception->getMessage()
                        : (string) data_get($item, 'message', "Respuesta inválida de Mercado Libre para {$itemId}.");
                    $exception ??= new MeliApiRequestException(
                        "Mercado Libre item {$itemId} HTTP {$status}: ".$this->sanitizeMessage($message),
                        $status,
                    );
                    $this->recordItemFailure($summary, $account, $itemId, $status, $message, $exception);

                    continue;
                }

                try {
                    $wasCreated = $this->saveItem($account, $item, now());
                    if ((array) ($item['item_relations'] ?? []) !== []) {
                        $record = MeliPriceManagerItem::query()
                            ->where('meli_account_id', $account->id)
                            ->where('meli_item_id', $itemId)
                            ->firstOrFail();
                        try {
                            $this->linkedPublications->refreshPriceRelations($account, $record);
                        } catch (Throwable $relationException) {
                            Log::warning('[MeliPriceManager] Price relation refresh failed', [
                                'meli_account_id' => (int) $account->id,
                                'meli_item_id' => $itemId,
                                'message' => $this->sanitizeMessage($relationException->getMessage()),
                            ]);
                        }
                    }
                    $summary[$wasCreated ? 'created' : 'updated']++;
                } catch (Throwable $exception) {
                    $this->recordItemFailure(
                        $summary,
                        $account,
                        $itemId,
                        $exception instanceof MeliApiRequestException ? $exception->httpStatus() : 0,
                        $exception->getMessage(),
                        $exception,
                    );
                }
            }
        }

        $summary['finished_at'] = now()->toDateTimeString();

        Log::info('[MeliPriceManager] Sync completed', [
            'meli_account_id' => (int) $account->id,
            'total_found' => $summary['total_found'],
            'processed' => $summary['processed'],
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'failed' => $summary['failed'],
        ]);

        return $summary;
    }

    /** @return list<string> */
    private function discoverAllItemIds(MeliAccount $account, string $sellerId): array
    {
        $ids = [];
        $scrollId = null;

        do {
            $query = [
                'search_type' => 'scan',
                'limit' => self::SEARCH_LIMIT,
            ];

            if ($scrollId !== null) {
                $query['scroll_id'] = $scrollId;
            }

            $response = $this->api->request(
                $account,
                'get',
                '/users/'.rawurlencode($sellerId).'/items/search',
                $query,
            );

            $batch = array_values(array_filter(array_map(
                static fn (mixed $id): string => mb_strtoupper(trim((string) $id), 'UTF-8'),
                (array) $response->json('results', []),
            )));

            $previousCount = count($ids);
            foreach ($batch as $id) {
                $ids[$id] = $id;
            }

            $nextScrollId = trim((string) $response->json('scroll_id', ''));
            $scrollId = $nextScrollId === '' ? null : $nextScrollId;
        } while ($batch !== [] && count($ids) > $previousCount && $scrollId !== null);

        return array_values($ids);
    }

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array<string, mixed>>
     */
    private function fetchItemEntries(MeliAccount $account, array $itemIds): array
    {
        try {
            $response = $this->api->request($account, 'get', '/items', [
                'ids' => implode(',', $itemIds),
            ]);

            $entries = [];
            foreach (array_values((array) $response->json()) as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $body = $entry['body'] ?? null;
                $id = is_array($body) ? mb_strtoupper(trim((string) ($body['id'] ?? '')), 'UTF-8') : '';
                $id = $id !== '' ? $id : ($itemIds[$index] ?? '');
                if ($id !== '') {
                    $entries[$id] = $entry;
                }
            }

            return $entries;
        } catch (Throwable $batchException) {
            Log::warning('[MeliPriceManager] Multiget failed; retrying items individually', [
                'meli_account_id' => (int) $account->id,
                'item_count' => count($itemIds),
                'http_status' => $batchException instanceof MeliApiRequestException ? $batchException->httpStatus() : null,
                'message' => $this->sanitizeMessage($batchException->getMessage()),
                'exception' => $batchException,
            ]);

            $entries = [];
            foreach ($itemIds as $itemId) {
                try {
                    $response = $this->api->request($account, 'get', '/items/'.rawurlencode($itemId));
                    $entries[$itemId] = ['code' => $response->status(), 'body' => $response->json()];
                } catch (Throwable $exception) {
                    $entries[$itemId] = [
                        'code' => $exception instanceof MeliApiRequestException ? $exception->httpStatus() : 0,
                        'body' => ['message' => $exception->getMessage()],
                        'exception' => $exception,
                    ];
                }
            }

            return $entries;
        }
    }

    /** @param array<string, mixed> $item */
    private function saveItem(MeliAccount $account, array $item, CarbonInterface $syncedAt): bool
    {
        $itemId = mb_strtoupper(trim((string) ($item['id'] ?? '')), 'UTF-8');
        $title = trim((string) ($item['title'] ?? ''));
        $price = $item['price'] ?? null;

        if ($itemId === '' || $title === '' || ! is_numeric($price)) {
            throw new RuntimeException("El item {$itemId} no contiene ID, título o precio válidos.");
        }

        $attributes = array_values((array) ($item['attributes'] ?? []));
        $brand = $this->extractAttributeValue($attributes, 'BRAND');
        $record = MeliPriceManagerItem::query()->firstOrNew([
            'meli_account_id' => $account->id,
            'meli_item_id' => $itemId,
        ]);
        $wasCreated = ! $record->exists;

        $record->fill([
            'sku' => $this->extractSku($item),
            'title' => mb_substr($title, 0, 255),
            'category_id' => $this->nullableString($item['category_id'] ?? null, 64),
            'listing_type_id' => $this->nullableString($item['listing_type_id'] ?? null, 64),
            'catalog_product_id' => $this->nullableString($item['catalog_product_id'] ?? null, 128),
            'user_product_id' => $this->nullableString($item['user_product_id'] ?? null, 128),
            'inventory_id' => $this->nullableString($item['inventory_id'] ?? null, 128),
            'catalog_listing' => (bool) ($item['catalog_listing'] ?? false),
            'price_relation_ids' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $relation): string => mb_strtoupper(trim((string) (is_array($relation) ? ($relation['id'] ?? '') : $relation)), 'UTF-8'),
                (array) ($item['item_relations'] ?? []),
            )))),
            // A fresh item snapshot invalidates the previous buybox assertion until
            // /public/buybox/sync confirms it again during this synchronization.
            'price_sync_status' => null,
            'linked_synced_at' => null,
            'meli_brand' => $this->nullableString($brand, 255),
            'normalized_brand' => $this->brandNormalizer->normalize($brand),
            'current_price' => (string) $price,
            'original_price' => is_numeric($item['original_price'] ?? null) ? (string) $item['original_price'] : null,
            'available_quantity' => is_numeric($item['available_quantity'] ?? null) ? (int) $item['available_quantity'] : null,
            'sold_quantity' => is_numeric($item['sold_quantity'] ?? null) ? max(0, (int) $item['sold_quantity']) : null,
            'currency_id' => $this->nullableString($item['currency_id'] ?? null, 8),
            'status' => $this->nullableString($item['status'] ?? null, 64),
            'permalink' => $this->nullableString($item['permalink'] ?? null, 2048),
            'thumbnail' => $this->nullableString($item['thumbnail'] ?? null, 2048),
            'raw_attributes' => $this->sanitizePayload($attributes),
            'raw_item' => $this->boundedRawItem($item),
            'last_synced_at' => $syncedAt,
        ]);
        $record->save();

        return $wasCreated;
    }

    /** @param array<string, mixed> $item */
    public function extractSku(array $item): ?string
    {
        $attributes = (array) ($item['attributes'] ?? []);
        $candidates = [
            $this->extractAttributeValue($attributes, 'SELLER_SKU'),
            $this->extractAttributeValue($attributes, 'SKU'),
            $item['seller_custom_field'] ?? null,
        ];

        foreach ((array) ($item['variations'] ?? []) as $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $variationAttributes = (array) ($variation['attributes'] ?? []);
            $candidates[] = $this->extractAttributeValue($variationAttributes, 'SELLER_SKU');
            $candidates[] = $this->extractAttributeValue($variationAttributes, 'SKU');
            $candidates[] = $variation['seller_custom_field'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $sku = trim((string) $candidate);
            if ($sku !== '') {
                return mb_substr($sku, 0, 191);
            }
        }

        return null;
    }

    /** @param array<int, mixed> $attributes */
    private function extractAttributeValue(array $attributes, string $attributeId): ?string
    {
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || strcasecmp((string) ($attribute['id'] ?? ''), $attributeId) !== 0) {
                continue;
            }

            $value = $attribute['value_name'] ?? $attribute['value_id'] ?? data_get($attribute, 'values.0.name')
                ?? data_get($attribute, 'values.0.id');
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /** @param array<string, mixed> $summary */
    private function recordItemFailure(
        array &$summary,
        MeliAccount $account,
        string $itemId,
        int $httpStatus,
        string $message,
        ?Throwable $exception,
    ): void {
        $safeMessage = $this->sanitizeMessage($message);
        $detail = [
            'meli_item_id' => $itemId,
            'http_status' => $httpStatus > 0 ? $httpStatus : null,
            'message' => $safeMessage,
            'exception_class' => $exception !== null ? $exception::class : null,
        ];

        $summary['failed']++;
        if (count($summary['error_details']) < self::MAX_ERROR_DETAILS) {
            $summary['error_details'][] = $detail;
        }

        $context = [
            'meli_account_id' => (int) $account->id,
            ...$detail,
        ];
        if ($exception !== null) {
            $context['exception'] = $exception;
        }

        Log::error('[MeliPriceManager] Failed item '.$itemId, $context);
    }

    /** @param array<string, mixed> $item */
    private function boundedRawItem(array $item): array
    {
        $sanitized = $this->sanitizePayload($item);
        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json) || strlen($json) > self::MAX_RAW_ITEM_BYTES) {
            return [
                'truncated' => true,
                'original_bytes' => is_string($json) ? strlen($json) : null,
                'sha256' => is_string($json) ? hash('sha256', $json) : null,
                'id' => $item['id'] ?? null,
                'status' => $item['status'] ?? null,
            ];
        }

        return $sanitized;
    }

    private function sanitizePayload(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), [
            'access_token', 'refresh_token', 'authorization', 'client_secret', 'password', 'secret',
        ], true)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            return $this->sanitizeMessage($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $childKey => $childValue) {
            $value[$childKey] = $this->sanitizePayload($childValue, is_string($childKey) ? $childKey : null);
        }

        return $value;
    }

    private function sanitizeMessage(string $message): string
    {
        $sanitized = preg_replace([
            '/Bearer\s+[A-Za-z0-9._~-]+/i',
            '/\b(access_token|refresh_token|client_secret|authorization)\b\s*[=:]\s*[^\s,;]+/i',
            '/\bAPP_USR-[A-Za-z0-9_-]+\b/',
        ], [
            'Bearer [REDACTED]',
            '$1=[REDACTED]',
            '[REDACTED]',
        ], $message) ?? 'Error sanitizado';

        return Str::limit($sanitized, 1000, '…');
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
