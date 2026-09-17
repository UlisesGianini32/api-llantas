<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class MeliAccountPublicationSyncService
{
    private const SEARCH_LIMIT = 100;
    private const MULTIGET_LIMIT = 20;

    public function __construct(private readonly MeliAccountApiClient $api)
    {
    }

    public static function cacheKey(int $userId, int $accountId): string
    {
        return "meli:account-publications-sync:{$userId}:{$accountId}";
    }

    /**
     * Descarga todas las publicaciones de una cuenta directamente desde Mercado Libre
     * y las guarda/actualiza en meli_publications.
     *
     * @param  callable(array<string, mixed>): void|null  $progress
     * @return array<string, int|string|null>
     */
    public function sync(MeliAccount $account, ?callable $progress = null): array
    {
        $account->loadMissing('user');

        if (! $account->user_id || ! $account->meli_user_id) {
            throw new RuntimeException('La cuenta de Mercado Libre no tiene usuario propietario o meli_user_id.');
        }

        $this->api->ensureFreshAccessToken($account);

        $startedAt = now();
        $ids = $this->discoverAllItemIds($account, function (int $found) use ($progress): void {
            if ($progress !== null) {
                $progress([
                    'phase' => 'discovering',
                    'message' => "Descubiertas {$found} publicaciones en Mercado Libre...",
                    'discovered' => $found,
                ]);
            }
        });

        if ($ids === []) {
            throw new RuntimeException('Mercado Libre no devolvió publicaciones; se canceló la sincronización para proteger los datos locales.');
        }

        $total = count($ids);
        $processed = 0;
        $saved = 0;
        $errors = 0;
        $hidden = 0;

        if ($progress !== null) {
            $progress([
                'phase' => 'details',
                'message' => "Mercado Libre reportó {$total} publicaciones. Descargando detalles...",
                'discovered' => $total,
                'processed' => 0,
                'saved' => 0,
                'errors' => 0,
            ]);
        }

        foreach (array_chunk($ids, self::MULTIGET_LIMIT) as $chunkIndex => $chunk) {
            $responses = $this->fetchItems($account, $chunk);

            foreach ($responses as $entry) {
                $processed++;
                $code = (int) ($entry['code'] ?? 0);
                $item = $entry['body'] ?? null;

                if ($code !== 200 || ! is_array($item) || empty($item['id'])) {
                    $errors++;
                    continue;
                }

                try {
                    $publication = $this->saveItem($account, $item);
                    $saved++;

                    if ($this->isHiddenStatus((string) $publication->status)) {
                        $hidden++;
                    }
                } catch (Throwable) {
                    $errors++;
                }
            }

            if ($progress !== null) {
                $progress([
                    'phase' => 'details',
                    'message' => sprintf(
                        'Lote %d/%d · Revisadas %d/%d · Guardadas %d · Errores %d',
                        $chunkIndex + 1,
                        max(1, (int) ceil($total / self::MULTIGET_LIMIT)),
                        $processed,
                        $total,
                        $saved,
                        $errors,
                    ),
                    'discovered' => $total,
                    'processed' => $processed,
                    'saved' => $saved,
                    'errors' => $errors,
                ]);
            }
        }

        // Esta limpieza solo se ejecuta después de terminar correctamente el scan completo.
        $markedNotCurrent = MeliPublication::query()
            ->where('user_id', $account->user_id)
            ->where('meli_account_id', $account->id)
            ->where('is_current', true)
            ->whereNotIn('mlm', $ids)
            ->update([
                'is_current' => false,
                'updated_at' => now(),
            ]);

        $summary = [
            'account_id' => (int) $account->id,
            'meli_user_id' => (string) $account->meli_user_id,
            'discovered' => $total,
            'processed' => $processed,
            'saved' => $saved,
            'hidden_blocked_or_review' => $hidden,
            'visible_estimate' => max(0, $saved - $hidden),
            'errors' => $errors,
            'marked_not_current' => $markedNotCurrent,
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => now()->toDateTimeString(),
        ];

        if ($progress !== null) {
            $progress([
                'phase' => 'finished',
                'message' => "Sincronización terminada: {$saved} guardadas y {$errors} errores.",
                ...$summary,
            ]);
        }

        return $summary;
    }

    /** @return list<string> */
    private function discoverAllItemIds(MeliAccount $account, ?callable $progress = null): array
    {
        $ids = [];
        $scrollId = null;

        do {
            $params = [
                'search_type' => 'scan',
                'limit' => self::SEARCH_LIMIT,
            ];

            if (filled($scrollId)) {
                $params['scroll_id'] = $scrollId;
            }

            $response = $this->api->request($account, 'get', "/users/{$account->meli_user_id}/items/search", $params);
            $batch = array_values(array_filter(
                array_map(static fn ($id) => strtoupper(trim((string) $id)), (array) $response->json('results', [])),
                static fn (string $id) => $id !== '',
            ));

            foreach ($batch as $id) {
                $ids[$id] = $id;
            }

            $scrollId = $response->json('scroll_id');
            if ($progress !== null) {
                $progress(count($ids));
            }
        } while ($batch !== [] && filled($scrollId));

        return array_values($ids);
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function fetchItems(MeliAccount $account, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $response = $this->api->request($account, 'get', '/items', [
            'ids' => implode(',', $ids),
        ]);

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    /** @param array<string, mixed> $item */
    private function saveItem(MeliAccount $account, array $item): MeliPublication
    {
        $mlm = strtoupper(trim((string) ($item['id'] ?? '')));
        if ($mlm === '') {
            throw new RuntimeException('Mercado Libre devolvió una publicación sin ID.');
        }

        $existingRows = MeliPublication::query()
            ->where('user_id', $account->user_id)
            ->where('meli_account_id', $account->id)
            ->where('mlm', $mlm)
            ->orderByDesc('id')
            ->get();

        /** @var MeliPublication $publication */
        $publication = $existingRows->first() ?? new MeliPublication();
        $oldRaw = is_array($publication->raw) ? $publication->raw : [];

        $metadata = [];
        foreach (['metrics', 'moderations', 'visits', 'conversion', 'deleted_from_panel_at'] as $key) {
            if (array_key_exists($key, $oldRaw)) {
                $metadata[$key] = $oldRaw[$key];
            }
        }

        $raw = [
            'item' => $item,
            ...$metadata,
            'account_catalog_sync' => [
                'synced_at' => now()->toDateTimeString(),
                'source' => 'users-items-search-scan',
            ],
        ];

        $publication->forceFill([
            'user_id' => $account->user_id,
            'meli_account_id' => $account->id,
            'sku' => $this->extractSku($item, (string) ($publication->sku ?? '')),
            'mlm' => $mlm,
            'source_mlm' => $publication->source_mlm,
            'status' => strtolower(trim((string) ($item['status'] ?? ''))),
            'sub_status' => array_values((array) ($item['sub_status'] ?? [])),
            'permalink' => $item['permalink'] ?? $publication->permalink,
            'last_sync_at' => now(),
            'raw' => $raw,
            'category_id' => $item['category_id'] ?? $publication->category_id,
            'pictures' => $item['pictures'] ?? $publication->pictures,
            'is_current' => true,
        ]);
        $publication->save();

        $existingRows
            ->where('id', '!=', $publication->id)
            ->each(function (MeliPublication $duplicate): void {
                $duplicate->forceFill(['is_current' => false])->save();
            });

        return $publication->fresh(['meliAccount:id,nickname,is_default']);
    }

    /** @param array<string, mixed> $item */
    private function extractSku(array $item, string $fallback = ''): string
    {
        $candidates = [
            $item['seller_custom_field'] ?? null,
            $this->attributeValue((array) ($item['attributes'] ?? []), 'SELLER_SKU'),
        ];

        foreach ((array) ($item['variations'] ?? []) as $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $candidates[] = $variation['seller_custom_field'] ?? null;
            $candidates[] = $this->attributeValue((array) ($variation['attributes'] ?? []), 'SELLER_SKU');
        }

        $candidates[] = $fallback;

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return '';
    }

    /** @param array<int, mixed> $attributes */
    private function attributeValue(array $attributes, string $attributeId): ?string
    {
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || strtoupper((string) ($attribute['id'] ?? '')) !== strtoupper($attributeId)) {
                continue;
            }

            $value = $attribute['value_name'] ?? $attribute['value_id'] ?? null;

            return filled($value) ? (string) $value : null;
        }

        return null;
    }

    private function isHiddenStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            'deleted',
            'under_review',
            'blocked',
            'inactive',
            'suspended',
            'closed',
        ], true);
    }

}
