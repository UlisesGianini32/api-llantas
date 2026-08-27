<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliBrandAlias;
use App\Models\MeliPriceManagerItem;
use Illuminate\Database\Eloquent\Builder;

class MeliBrandClassificationService
{
    private const CHUNK_SIZE = 500;

    private const MANUAL_SOURCE_PREFIX = 'manual';

    public function __construct(private readonly MeliBrandNormalizer $normalizer) {}

    public function classifyItem(MeliPriceManagerItem $item, bool $dryRun = false): MeliBrandClassificationResult
    {
        $result = $this->decisionFor($item, $this->loadRules());

        if (! $dryRun && $result->skippedReason === null) {
            $this->applyResult($item, $result);
        }

        return $result;
    }

    /** @return array<string, int|bool> */
    public function classifyAccount(MeliAccount $account, bool $reclassifyAll = false, bool $dryRun = false): array
    {
        $query = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->where('meli_account_id', $account->id);

        if (! $reclassifyAll) {
            $query->where(function (Builder $query): void {
                $query->whereIn('classification_status', ['uncategorized', 'suggested', 'ignored'])
                    ->orWhere('classification_source', 'like', self::MANUAL_SOURCE_PREFIX.'%');
            });
        }

        return $this->classifyQuery($query, $dryRun);
    }

    /** @return array<string, int|bool> */
    public function classifyUncategorized(MeliAccount $account, bool $dryRun = false): array
    {
        $query = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->where('meli_account_id', $account->id)
            ->whereIn('classification_status', ['uncategorized', 'suggested']);

        return $this->classifyQuery($query, $dryRun);
    }

    /**
     * @param  Builder<MeliPriceManagerItem>  $query
     * @return array<string, int|bool>
     */
    private function classifyQuery(Builder $query, bool $dryRun): array
    {
        $rules = $this->loadRules();
        $summary = [
            'processed' => 0,
            'categorized' => 0,
            'suggested' => 0,
            'uncategorized' => 0,
            'ignored' => 0,
            'skipped_manual' => 0,
            'changed' => 0,
            'dry_run' => $dryRun,
        ];

        $query->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($items) use (&$summary, $rules, $dryRun): void {
            foreach ($items as $item) {
                $summary['processed']++;
                $result = $this->decisionFor($item, $rules);

                if ($result->skippedReason === 'manual') {
                    $summary['skipped_manual']++;

                    continue;
                }

                if ($result->skippedReason === 'ignored') {
                    $summary['ignored']++;

                    continue;
                }

                $summary[$result->status]++;
                $wouldChange = $this->wouldChange($item, $result);
                if ($wouldChange) {
                    $summary['changed']++;
                }

                if (! $dryRun && $wouldChange) {
                    $this->applyResult($item, $result);
                }
            }
        });

        return $summary;
    }

    /**
     * @return list<array{
     *     alias_id: int,
     *     brand_group_id: int,
     *     alias: string,
     *     normalized_alias: string,
     *     match_type: string,
     *     priority: int
     * }>
     */
    private function loadRules(): array
    {
        return MeliBrandAlias::query()
            ->where('active', true)
            ->where('match_type', '!=', 'manual')
            ->whereHas('brandGroup', fn (Builder $query) => $query->where('active', true))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->map(function (MeliBrandAlias $alias): ?array {
                $normalizedAlias = $this->normalizer->normalize($alias->normalized_alias ?: $alias->alias);
                if ($normalizedAlias === null) {
                    return null;
                }

                return [
                    'alias_id' => (int) $alias->id,
                    'brand_group_id' => (int) $alias->brand_group_id,
                    'alias' => (string) $alias->alias,
                    'normalized_alias' => $normalizedAlias,
                    'match_type' => (string) $alias->match_type,
                    'priority' => (int) $alias->priority,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array{alias_id: int, brand_group_id: int, alias: string, normalized_alias: string, match_type: string, priority: int}>  $rules
     */
    private function decisionFor(MeliPriceManagerItem $item, array $rules): MeliBrandClassificationResult
    {
        if ($item->classification_status === 'ignored') {
            return $this->skippedResult($item, 'ignored');
        }

        if ($this->isManual($item)) {
            return $this->skippedResult($item, 'manual');
        }

        $brand = $this->normalizer->normalize($item->normalized_brand ?: $item->meli_brand);
        $title = $this->normalizer->normalize($item->title);

        $stages = [
            ['source' => 'brand_exact', 'confidence' => '1.0000', 'input' => $brand, 'type' => 'exact'],
            ['source' => 'brand_starts_with', 'confidence' => '0.9500', 'input' => $brand, 'type' => 'starts_with'],
            ['source' => 'brand_contains', 'confidence' => '0.9000', 'input' => $brand, 'type' => 'contains'],
            ['source' => 'title_contains', 'confidence' => '0.8500', 'input' => $title, 'type' => 'title_contains'],
        ];

        foreach ($stages as $stage) {
            if ($stage['input'] === null) {
                continue;
            }

            $candidates = $this->matchingCandidates($rules, $stage['input'], $stage['type'], $stage['source'], $stage['confidence']);
            if ($candidates !== []) {
                return $this->resultFromCandidates($candidates, $stage['source'], $stage['confidence']);
            }
        }

        return new MeliBrandClassificationResult(
            status: 'uncategorized',
            brandGroupId: null,
            suggestedBrandGroupId: null,
            source: null,
            confidence: null,
            matchedAliasId: null,
        );
    }

    /**
     * @param  list<array{alias_id: int, brand_group_id: int, alias: string, normalized_alias: string, match_type: string, priority: int}>  $rules
     * @return list<array<string, int|string>>
     */
    private function matchingCandidates(array $rules, string $input, string $type, string $source, string $confidence): array
    {
        $candidates = [];

        foreach ($rules as $rule) {
            $matches = match ($type) {
                'exact' => $rule['match_type'] === 'exact' && $input === $rule['normalized_alias'],
                'starts_with' => $rule['match_type'] === 'starts_with' && $this->startsWithPhrase($input, $rule['normalized_alias']),
                'contains' => $rule['match_type'] === 'contains' && $this->containsPhrase($input, $rule['normalized_alias']),
                'title_contains' => $rule['match_type'] === 'title_contains' && $this->containsPhrase($input, $rule['normalized_alias']),
                default => false,
            };

            if (! $matches) {
                continue;
            }

            $candidates[] = [
                ...$rule,
                'source' => $source,
                'confidence' => $confidence,
                'specificity' => $type === 'title_contains' && $input === $rule['normalized_alias'] ? 2 : 1,
                'alias_length' => mb_strlen($rule['normalized_alias']),
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => $right['priority'] <=> $left['priority']
            ?: $right['specificity'] <=> $left['specificity']
            ?: $right['alias_length'] <=> $left['alias_length']
            ?: $left['alias_id'] <=> $right['alias_id']
        );

        return $candidates;
    }

    /** @param list<array<string, int|string>> $candidates */
    private function resultFromCandidates(array $candidates, string $source, string $confidence): MeliBrandClassificationResult
    {
        $top = $candidates[0];
        $tied = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['priority'] === $top['priority']
            && $candidate['specificity'] === $top['specificity']
            && $candidate['alias_length'] === $top['alias_length']
        ));
        $conflictingGroups = array_values(array_unique(array_column($tied, 'brand_group_id')));
        $auditableCandidates = array_map(static fn (array $candidate): array => [
            'brand_group_id' => $candidate['brand_group_id'],
            'alias_id' => $candidate['alias_id'],
            'normalized_alias' => $candidate['normalized_alias'],
            'match_type' => $candidate['match_type'],
            'priority' => $candidate['priority'],
            'source' => $candidate['source'],
            'confidence' => $candidate['confidence'],
        ], array_slice($candidates, 0, 25));

        if (count($conflictingGroups) > 1) {
            return new MeliBrandClassificationResult(
                status: 'suggested',
                brandGroupId: null,
                suggestedBrandGroupId: (int) $top['brand_group_id'],
                source: 'ambiguous_'.$source,
                confidence: $confidence,
                matchedAliasId: (int) $top['alias_id'],
                metadata: [
                    'reason' => 'multiple_brand_groups_tied',
                    'candidate_count' => count($candidates),
                    'candidates' => $auditableCandidates,
                ],
                candidates: $auditableCandidates,
            );
        }

        return new MeliBrandClassificationResult(
            status: 'categorized',
            brandGroupId: (int) $top['brand_group_id'],
            suggestedBrandGroupId: null,
            source: $source,
            confidence: $confidence,
            matchedAliasId: (int) $top['alias_id'],
            metadata: [
                'matched_alias' => $auditableCandidates[0],
            ],
            candidates: [$auditableCandidates[0]],
        );
    }

    private function startsWithPhrase(string $input, string $alias): bool
    {
        return $input === $alias || str_starts_with($input, $alias.' ');
    }

    private function containsPhrase(string $input, string $alias): bool
    {
        return preg_match('/(?:^| )'.preg_quote($alias, '/').'(?: |$)/u', $input) === 1;
    }

    private function isManual(MeliPriceManagerItem $item): bool
    {
        return str_starts_with(strtolower(trim((string) $item->classification_source)), self::MANUAL_SOURCE_PREFIX);
    }

    private function skippedResult(MeliPriceManagerItem $item, string $reason): MeliBrandClassificationResult
    {
        return new MeliBrandClassificationResult(
            status: (string) $item->classification_status,
            brandGroupId: $item->brand_group_id !== null ? (int) $item->brand_group_id : null,
            suggestedBrandGroupId: $item->suggested_brand_group_id !== null ? (int) $item->suggested_brand_group_id : null,
            source: $item->classification_source,
            confidence: $item->classification_confidence,
            matchedAliasId: $item->matched_brand_alias_id !== null ? (int) $item->matched_brand_alias_id : null,
            metadata: $item->classification_metadata,
            skippedReason: $reason,
        );
    }

    private function wouldChange(MeliPriceManagerItem $item, MeliBrandClassificationResult $result): bool
    {
        return $item->brand_group_id !== $result->brandGroupId
            || $item->suggested_brand_group_id !== $result->suggestedBrandGroupId
            || $item->matched_brand_alias_id !== $result->matchedAliasId
            || $item->classification_status !== $result->status
            || $item->classification_source !== $result->source
            || $item->classification_confidence !== $result->confidence
            || $item->classification_metadata !== $result->metadata;
    }

    private function applyResult(MeliPriceManagerItem $item, MeliBrandClassificationResult $result): void
    {
        $item->forceFill([
            'brand_group_id' => $result->brandGroupId,
            'suggested_brand_group_id' => $result->suggestedBrandGroupId,
            'matched_brand_alias_id' => $result->matchedAliasId,
            'classification_status' => $result->status,
            'classification_source' => $result->source,
            'classification_confidence' => $result->confidence,
            'classification_metadata' => $result->metadata,
        ])->save();
    }
}
