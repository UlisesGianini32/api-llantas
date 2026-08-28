<?php

namespace App\Services\Autopartes\Meli;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliCategoryCandidate;
use Illuminate\Support\Str;

class AutomotivePartMeliCategorySuggestionService
{
    public function __construct(
        private AutomotivePartMeliConfiguration $configuration,
        private AutomotivePartMeliCategorySyncService $categories,
        private MercadoLibreCatalogMetadataClient $client,
    ) {}

    public function preview(AutomotivePart $part): array
    {
        $part->loadMissing('enrichmentReview');
        [$query, $querySource] = $this->queryFor($part);

        return [
            'automotive_part_id' => $part->id,
            'item_number' => $part->item_number,
            'internal_category' => $part->category,
            'internal_subcategory' => $part->subcategory,
            'query' => $query,
            'query_source' => $querySource,
            'deterministic_rule' => $this->matchingRule($part),
            'rules_version' => (string) config('autopartes_meli.rules_version', 'v1'),
        ];
    }

    public function suggest(AutomotivePart $part, bool $refresh = false, bool $force = false): array
    {
        $this->configuration->assertReady();
        $part->loadMissing('enrichmentReview');

        if (! $force && $part->meliCategoryCandidates()->where('status', 'approved')->exists()) {
            return ['created' => 0, 'skipped' => 1, 'candidates' => collect()];
        }

        [$query, $querySource] = $this->queryFor($part);
        if ($query === '') {
            throw new AutomotivePartMeliException('La autoparte no tiene texto suficiente para buscar una categoría.', 'insufficient_query');
        }

        $max = max(1, min(8, (int) config('autopartes_meli.max_candidates', 5)));
        $candidates = collect();
        $rule = $this->matchingRule($part);

        if ($rule !== null && count($candidates) < $max) {
            $category = $this->categories->syncCategory((string) $rule['category_id'], $refresh);
            $candidates->push($this->createCandidate($part, [
                'category_id' => $category->category_id,
                'category_name' => $category->name,
                'domain_id' => $category->domain_id,
                'source' => 'deterministic',
                'query_text' => $query,
                'position' => 1,
                'score' => null,
                'evidence' => [
                    'query_source' => $querySource,
                    'internal_category' => $part->category,
                    'internal_subcategory' => $part->subcategory,
                    'rule' => $rule,
                    'rules_version' => config('autopartes_meli.rules_version', 'v1'),
                ],
                'raw_payload' => $category->raw_payload,
            ]));
        }

        if (count($candidates) < $max) {
            $response = $this->client->discover($query, $max, $refresh);
            foreach ($response['payload'] as $position => $payload) {
                if (count($candidates) >= $max || ! is_array($payload)) {
                    break;
                }

                $categoryId = strtoupper((string) ($payload['category_id'] ?? $payload['id'] ?? ''));
                if (! preg_match('/^MLM\d+$/', $categoryId) || $candidates->contains('category_id', $categoryId)) {
                    continue;
                }

                $category = $this->categories->syncCategory($categoryId, $refresh);
                if (blank($category->domain_id) && filled($payload['domain_id'] ?? null)) {
                    $category->forceFill(['domain_id' => $payload['domain_id']])->save();
                }
                $candidates->push($this->createCandidate($part, [
                    'category_id' => $category->category_id,
                    'category_name' => $category->name,
                    'domain_id' => $payload['domain_id'] ?? $category->domain_id,
                    'source' => 'domain_discovery',
                    'query_text' => $query,
                    'position' => $position + 1,
                    'score' => is_numeric($payload['score'] ?? null) ? (float) $payload['score'] : null,
                    'evidence' => [
                        'query_source' => $querySource,
                        'request_id' => $response['request_id'],
                        'rules_version' => config('autopartes_meli.rules_version', 'v1'),
                    ],
                    'raw_payload' => $payload,
                ]));
            }
        }

        return ['created' => $candidates->where('wasRecentlyCreated', true)->count(), 'skipped' => 0, 'candidates' => $candidates];
    }

    private function createCandidate(AutomotivePart $part, array $data): AutomotivePartMeliCategoryCandidate
    {
        return AutomotivePartMeliCategoryCandidate::query()->updateOrCreate(
            [
                'automotive_part_id' => $part->id,
                'category_id' => $data['category_id'],
                'source' => $data['source'],
                'status' => 'pending',
            ],
            array_merge($data, [
                'automotive_part_enrichment_review_id' => $part->enrichmentReview?->id,
            ]),
        );
    }

    private function queryFor(AutomotivePart $part): array
    {
        $review = $part->enrichmentReview;
        $options = [
            'approved_manual_spanish' => $review?->status === 'approved' && $review?->enrichment_source === 'manual'
                ? trim(implode(' ', array_filter([$review->proposed_title, $review->proposed_description]))) : '',
            'complete_enrichment' => filled($review?->proposed_title) && filled($review?->proposed_description)
                ? trim($review->proposed_title.' '.$review->proposed_description) : '',
            'deterministic_proposal' => $review?->enrichment_source === 'rules' ? trim((string) $review->proposed_title) : '',
            'original_description' => trim((string) $part->description_original),
            'internal_taxonomy' => trim(implode(' ', array_filter([$part->category, $part->subcategory]))),
        ];

        foreach ($options as $source => $text) {
            if ($text !== '') {
                $context = trim(implode(' ', array_filter([$part->vendor, $part->prevalent_model, $part->manufacturer_part_number])));

                return [Str::limit(trim($text.' '.$context), 240, ''), $source];
            }
        }

        return ['', 'none'];
    }

    private function matchingRule(AutomotivePart $part): ?array
    {
        $category = mb_strtoupper(trim((string) $part->category));
        $subcategory = mb_strtoupper(trim((string) $part->subcategory));

        return collect((array) config('autopartes_meli.deterministic_rules', []))
            ->first(function ($rule) use ($category, $subcategory) {
                if (! is_array($rule) || ! preg_match('/^MLM\d+$/', (string) ($rule['category_id'] ?? ''))) {
                    return false;
                }

                $categoryMatches = mb_strtoupper(trim((string) ($rule['internal_category'] ?? ''))) === $category;
                $ruleSubcategory = mb_strtoupper(trim((string) ($rule['internal_subcategory'] ?? '')));

                return $categoryMatches && ($ruleSubcategory === '' || $ruleSubcategory === $subcategory);
            });
    }
}
