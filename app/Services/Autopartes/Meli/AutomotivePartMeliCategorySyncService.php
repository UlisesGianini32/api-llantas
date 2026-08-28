<?php

namespace App\Services\Autopartes\Meli;

use App\Models\AutomotivePartMeliAttributeRequirement;
use App\Models\AutomotivePartMeliCategory;
use Illuminate\Support\Facades\DB;

class AutomotivePartMeliCategorySyncService
{
    public function __construct(
        private MercadoLibreCatalogMetadataClient $client,
        private AutomotivePartMeliConfiguration $configuration,
    ) {}

    public function syncSiteCategories(bool $refresh = false): int
    {
        $response = $this->client->siteCategories($refresh);
        $categories = $response['payload'];
        $synced = 0;

        foreach ($categories as $payload) {
            if (! is_array($payload) || ! preg_match('/^MLM\d+$/', (string) ($payload['id'] ?? ''))) {
                continue;
            }

            $category = AutomotivePartMeliCategory::query()->firstOrNew([
                'site_id' => $this->configuration->siteId(),
                'category_id' => $payload['id'],
            ]);
            $category->name = (string) ($payload['name'] ?? $payload['id']);
            $category->synced_at = now();
            if (! $category->exists) {
                $category->path_from_root = [['id' => $payload['id'], 'name' => $payload['name'] ?? $payload['id']]];
                $category->raw_payload = $payload;
            }
            $category->save();
            $synced++;
        }

        return $synced;
    }

    public function syncCategory(string $categoryId, bool $refresh = false): AutomotivePartMeliCategory
    {
        $response = $this->client->category($categoryId, $refresh);
        $payload = $response['payload'];
        $returnedId = strtoupper((string) ($payload['id'] ?? ''));
        $expectedId = strtoupper(trim($categoryId));

        if ($returnedId !== $expectedId) {
            throw new AutomotivePartMeliException('La API no confirmó el category_id solicitado.', 'category_id_mismatch');
        }

        $category = AutomotivePartMeliCategory::query()->firstOrNew([
            'site_id' => $this->configuration->siteId(),
            'category_id' => $expectedId,
        ]);
        $domainId = $payload['catalog_domain'] ?? data_get($payload, 'settings.catalog_domain');
        $category->fill([
            'name' => (string) ($payload['name'] ?? $expectedId),
            'domain_id' => filled($domainId) ? $domainId : $category->domain_id,
            'path_from_root' => is_array($payload['path_from_root'] ?? null) ? $payload['path_from_root'] : ($category->path_from_root ?? []),
            'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : $category->settings,
            'raw_payload' => $payload,
            'synced_at' => now(),
        ])->save();

        return $category;
    }

    public function syncAttributes(string $categoryId, bool $refresh = false): AutomotivePartMeliCategory
    {
        $category = $this->syncCategory($categoryId, $refresh);
        $response = $this->client->categoryAttributes($categoryId, $refresh);

        DB::transaction(function () use ($category, $response) {
            $attributeIds = [];
            foreach ($response['payload'] as $payload) {
                if (! is_array($payload) || blank($payload['id'] ?? null)) {
                    continue;
                }

                $attributeIds[] = (string) $payload['id'];
                $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];
                AutomotivePartMeliAttributeRequirement::query()->updateOrCreate(
                    [
                        'automotive_part_meli_category_id' => $category->id,
                        'attribute_id' => (string) $payload['id'],
                    ],
                    [
                        'name' => (string) ($payload['name'] ?? $payload['id']),
                        'value_type' => $payload['value_type'] ?? $payload['type'] ?? null,
                        'value_max_length' => is_numeric($payload['value_max_length'] ?? null) ? (int) $payload['value_max_length'] : null,
                        'tags' => $tags,
                        'allowed_values' => is_array($payload['values'] ?? null) ? $payload['values'] : null,
                        'hierarchy' => $payload['hierarchy'] ?? null,
                        'is_required' => (bool) ($tags['required'] ?? false),
                        'is_catalog_required' => (bool) ($tags['catalog_required'] ?? false),
                        'is_conditional_required' => (bool) ($tags['conditional_required'] ?? false),
                        'raw_payload' => $payload,
                    ],
                );
            }

            $staleRequirements = $category->attributeRequirements();
            if ($attributeIds === []) {
                $staleRequirements->delete();
            } else {
                $staleRequirements->whereNotIn('attribute_id', $attributeIds)->delete();
            }

            $category->forceFill(['attributes_synced_at' => now()])->save();
        });

        return $category->fresh('attributeRequirements');
    }
}
