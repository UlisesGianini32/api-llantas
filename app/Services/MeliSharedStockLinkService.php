<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MeliSharedStockLinkService
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        int $userId,
        int $masterAccountId,
        int $secondaryAccountId,
        bool $apply = false,
        bool $refreshMasterStock = false,
    ): array {
        $master = MeliAccount::query()
            ->where('user_id', $userId)
            ->findOrFail($masterAccountId);
        $secondary = MeliAccount::query()
            ->where('user_id', $userId)
            ->findOrFail($secondaryAccountId);

        if (! $master->is_default) {
            throw new RuntimeException('La cuenta maestra debe ser la cuenta principal (is_default = true).');
        }

        if ($master->id === $secondary->id) {
            throw new RuntimeException('La cuenta maestra y la secundaria deben ser diferentes.');
        }

        $masterPublications = $this->publications($userId, $master->id);
        $secondaryPublications = $this->publications($userId, $secondary->id);

        $masterRows = $masterPublications
            ->flatMap(fn (MeliPublication $publication) => $this->flattenPublication($publication, 'master'))
            ->values();
        $secondaryRows = $secondaryPublications
            ->flatMap(fn (MeliPublication $publication) => $this->flattenPublication($publication, 'mirror'))
            ->values();

        $masterByMlm = $masterRows->groupBy('mlm');
        $masterBySku = $masterRows
            ->filter(fn (array $row) => $row['sku_norm'] !== '')
            ->groupBy('sku_norm');

        $resolvedSecondary = collect();
        $unmatched = collect();
        $ambiguous = collect();

        foreach ($secondaryRows as $row) {
            $match = $this->matchSecondaryRow($row, $masterByMlm, $masterBySku);

            if ($match['status'] === 'matched') {
                $resolvedSecondary->push([
                    ...$row,
                    'link_key' => $match['master']['link_key'],
                    'match_method' => $match['method'],
                ]);
                continue;
            }

            if ($match['status'] === 'ambiguous') {
                $ambiguous->push([
                    'mlm' => $row['mlm'],
                    'variation_id' => $row['variation_id'],
                    'sku' => $row['sku'],
                    'reason' => $match['reason'],
                ]);
                continue;
            }

            $unmatched->push([
                'mlm' => $row['mlm'],
                'variation_id' => $row['variation_id'],
                'sku' => $row['sku'],
                'source_mlm' => $row['source_mlm'],
            ]);
        }

        $masterGroups = $masterRows->groupBy('link_key');
        $mirrorGroups = $resolvedSecondary->groupBy('link_key');

        $groups = collect();
        foreach ($mirrorGroups as $linkKey => $mirrorMembers) {
            $masterMembers = $masterGroups->get($linkKey, collect());
            if ($masterMembers->isEmpty()) {
                continue;
            }

            $knownMasterMembers = $masterMembers
                ->filter(fn (array $row) => (bool) ($row['stock_known'] ?? false));
            $knownMirrorMembers = $mirrorMembers
                ->filter(fn (array $row) => (bool) ($row['stock_known'] ?? false));

            $canonical = ($knownMasterMembers->isNotEmpty()
                ? $knownMasterMembers
                : $masterMembers)
                ->sortByDesc(fn (array $row) => $row['last_sync_timestamp'])
                ->first();

            $masterStockValues = $knownMasterMembers
                ->pluck('available_quantity')
                ->map(fn ($value) => max(0, (int) $value))
                ->unique()
                ->sort()
                ->values();
            $mirrorStockValues = $knownMirrorMembers
                ->pluck('available_quantity')
                ->map(fn ($value) => max(0, (int) $value))
                ->unique()
                ->sort()
                ->values();

            $masterStockMissing = $masterStockValues->isEmpty();
            $masterStockConflict = $masterStockValues->count() > 1;
            $selectedStock = $masterStockValues->count() === 1
                ? (int) $masterStockValues->first()
                : null;
            $safeToApply = ! $masterStockMissing && ! $masterStockConflict;

            $groups->push([
                'link_key' => (string) $linkKey,
                'group_key' => sha1("{$userId}|{$masterAccountId}|{$secondaryAccountId}|{$linkKey}"),
                'sku' => $canonical['sku'] !== '' ? $canonical['sku'] : null,
                'master_mlm' => $canonical['mlm'],
                'master_variation_id' => $canonical['variation_id'],
                'stock' => $selectedStock,
                'master_stock_values' => $masterStockValues,
                'mirror_stock_values' => $mirrorStockValues,
                'master_stock_missing' => $masterStockMissing,
                'master_stock_conflict' => $masterStockConflict,
                'mirror_stock_missing' => $mirrorStockValues->isEmpty(),
                'mirror_stock_conflict' => $mirrorStockValues->count() > 1,
                'master_mirror_difference' => $selectedStock !== null
                    && $mirrorStockValues->contains(
                        fn ($value) => (int) $value !== $selectedStock,
                    ),
                'safe_to_apply' => $safeToApply,
                'masters' => $masterMembers->values(),
                'mirrors' => $mirrorMembers->values(),
            ]);
        }

        // Nunca activar automáticamente un grupo sin stock legible en cuenta 1
        // o con cantidades contradictorias dentro de la cuenta maestra.
        $safeGroups = $groups
            ->where('safe_to_apply', true)
            ->values();

        $unmatchedAudit = $this->classifyUnmatched(
            $unmatched,
            $userId,
            $masterAccountId,
        );

        $summary = [
            'user_id' => $userId,
            'master_account_id' => $masterAccountId,
            'secondary_account_id' => $secondaryAccountId,
            'master_publications' => $masterPublications->count(),
            'secondary_publications' => $secondaryPublications->count(),
            'master_rows' => $masterRows->count(),
            'secondary_rows' => $secondaryRows->count(),
            'groups_found' => $groups->count(),
            'safe_groups' => $safeGroups->count(),
            'skipped_master_stock_missing_groups' => $groups
                ->where('master_stock_missing', true)
                ->count(),
            'skipped_master_stock_conflict_groups' => $groups
                ->where('master_stock_conflict', true)
                ->count(),
            'master_members' => $groups->sum(fn (array $group) => $group['masters']->count()),
            'mirror_members' => $groups->sum(fn (array $group) => $group['mirrors']->count()),
            'safe_master_members' => $safeGroups->sum(fn (array $group) => $group['masters']->count()),
            'safe_mirror_members' => $safeGroups->sum(fn (array $group) => $group['mirrors']->count()),
            'unmatched_rows' => $unmatched->count(),
            'ambiguous_rows' => $ambiguous->count(),
            'match_method_counts' => $resolvedSecondary
                ->countBy('match_method')
                ->sortKeys()
                ->all(),
            'multi_master_groups' => $groups
                ->filter(fn (array $group) => $group['masters']->count() > 1)
                ->count(),
            'multi_mirror_groups' => $groups
                ->filter(fn (array $group) => $group['mirrors']->count() > 1)
                ->count(),
            'master_stock_conflict_groups' => $groups
                ->where('master_stock_conflict', true)
                ->count(),
            'mirror_stock_conflict_groups' => $groups
                ->where('mirror_stock_conflict', true)
                ->count(),
            'master_mirror_difference_groups' => $groups
                ->where('master_mirror_difference', true)
                ->count(),
            'unmatched_reason_counts' => $unmatchedAudit['counts'],
            'applied' => false,
            'sample_groups' => $groups->take(20)->map(fn (array $group) => [
                'sku' => $group['sku'],
                'stock' => $group['stock'],
                'master_mlm' => $group['master_mlm'],
                'master_variation_id' => $group['master_variation_id'],
                'master_members' => $group['masters']->count(),
                'mirror_members' => $group['mirrors']->count(),
                'master_stock_values' => $group['master_stock_values']->implode(', '),
                'mirror_stock_values' => $group['mirror_stock_values']->implode(', '),
                'link_key' => $group['link_key'],
                'safe_to_apply' => $group['safe_to_apply'],
                'master_stock_missing' => $group['master_stock_missing'],
            ])->values()->all(),
            'sample_unsafe_groups' => $groups
                ->where('safe_to_apply', false)
                ->take(20)
                ->map(fn (array $group) => [
                    'sku' => $group['sku'],
                    'master_mlm' => $group['master_mlm'],
                    'master_members' => $group['masters']->count(),
                    'mirror_members' => $group['mirrors']->count(),
                    'master_stock_values' => $group['master_stock_values']->implode(', '),
                    'mirror_stock_values' => $group['mirror_stock_values']->implode(', '),
                    'reason' => $group['master_stock_missing']
                        ? 'sin_stock_legible_en_cuenta_1'
                        : 'stocks_distintos_en_cuenta_1',
                ])->values()->all(),
            'sample_stock_conflicts' => $groups
                ->filter(fn (array $group) => $group['master_stock_conflict'] || $group['mirror_stock_conflict'])
                ->take(20)
                ->map(fn (array $group) => [
                    'sku' => $group['sku'],
                    'master_mlm' => $group['master_mlm'],
                    'master_members' => $group['masters']->count(),
                    'mirror_members' => $group['mirrors']->count(),
                    'master_stock_values' => $group['master_stock_values']->implode(', '),
                    'mirror_stock_values' => $group['mirror_stock_values']->implode(', '),
                    'selected_stock' => $group['stock'],
                ])->values()->all(),
            'sample_unmatched' => $unmatchedAudit['samples'],
            'sample_ambiguous' => $ambiguous->take(20)->values()->all(),
        ];

        if (! $apply) {
            return $summary;
        }

        DB::transaction(function () use (
            $safeGroups,
            $userId,
            $masterAccountId,
            $secondaryAccountId,
            $refreshMasterStock,
        ): void {
            $seenGroupKeys = [];
            $seenMemberKeys = [];

            foreach ($safeGroups as $data) {
                $group = MeliSharedStockGroup::query()->firstOrNew([
                    'group_key' => $data['group_key'],
                ]);

                $isNew = ! $group->exists;
                $group->forceFill([
                    'user_id' => $userId,
                    'master_account_id' => $masterAccountId,
                    'link_key' => mb_substr((string) $data['link_key'], 0, 512),
                    'sku' => $data['sku'],
                    'master_mlm' => $data['master_mlm'],
                    'master_variation_id' => $data['master_variation_id'],
                    'stock' => ($isNew || $refreshMasterStock)
                        ? max(0, (int) $data['stock'])
                        : max(0, (int) $group->stock),
                    'link_method' => 'auto',
                    'is_enabled' => true,
                    'activated_at' => $group->activated_at ?: now(),
                    'last_error' => null,
                ])->save();

                $seenGroupKeys[] = $group->group_key;

                foreach ([
                    ['rows' => $data['masters'], 'role' => 'master'],
                    ['rows' => $data['mirrors'], 'role' => 'mirror'],
                ] as $bucket) {
                    foreach ($bucket['rows'] as $row) {
                        $memberKey = $this->memberKey(
                            (int) $row['meli_account_id'],
                            (string) $row['mlm'],
                            $row['variation_id'],
                        );

                        MeliSharedStockMember::query()->updateOrCreate(
                            ['member_key' => $memberKey],
                            [
                                'group_id' => $group->id,
                                'user_id' => $userId,
                                'meli_account_id' => (int) $row['meli_account_id'],
                                'meli_publication_id' => (int) $row['meli_publication_id'],
                                'mlm' => (string) $row['mlm'],
                                'variation_id' => $row['variation_id'],
                                'sku' => $row['sku'] !== '' ? $row['sku'] : null,
                                'role' => $bucket['role'],
                                'match_method' => $row['match_method'] ?? ($bucket['role'] === 'master' ? 'master' : 'auto'),
                                'is_active' => true,
                                'is_fulfillment' => false,
                                'last_error' => null,
                            ],
                        );

                        $seenMemberKeys[] = $memberKey;
                    }
                }
            }

            $autoGroups = MeliSharedStockGroup::query()
                ->where('user_id', $userId)
                ->where('master_account_id', $masterAccountId)
                ->where('link_method', 'auto');

            if ($seenGroupKeys === []) {
                $autoGroups->update(['is_enabled' => false, 'updated_at' => now()]);
            } else {
                (clone $autoGroups)
                    ->whereNotIn('group_key', $seenGroupKeys)
                    ->update(['is_enabled' => false, 'updated_at' => now()]);
            }

            $memberScope = MeliSharedStockMember::query()
                ->where('user_id', $userId)
                ->whereIn('meli_account_id', [$masterAccountId, $secondaryAccountId])
                ->whereIn('group_id', (clone $autoGroups)->pluck('id'));

            if ($seenMemberKeys === []) {
                $memberScope->update(['is_active' => false, 'updated_at' => now()]);
            } else {
                $memberScope
                    ->whereNotIn('member_key', $seenMemberKeys)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
        });

        $summary['applied'] = true;
        $summary['applied_groups'] = $safeGroups->count();

        return $summary;
    }

    private function publications(int $userId, int $accountId): Collection
    {
        return MeliPublication::query()
            ->where('user_id', $userId)
            ->where('meli_account_id', $accountId)
            ->where('is_current', true)
            ->whereIn('status', ['active', 'paused'])
            ->orderByDesc('last_sync_at')
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function flattenPublication(MeliPublication $publication, string $role): Collection
    {
        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $logisticType = strtolower(trim((string) data_get($item, 'shipping.logistic_type', '')));

        if ($logisticType === 'fulfillment') {
            return collect();
        }

        $itemSku = $this->extractSku($item, (string) ($publication->sku ?? ''));
        $variations = collect((array) ($item['variations'] ?? []))
            ->filter(fn ($variation) => is_array($variation))
            ->values();

        if ($variations->isEmpty()) {
            $signature = 'simple';
            $skuNorm = $this->normalizeSku($itemSku);
            $linkKey = $skuNorm !== ''
                ? "sku:{$skuNorm}|simple"
                : 'mlm:'.strtoupper((string) $publication->mlm).'|simple';

            $stockKnown = array_key_exists('available_quantity', $item)
                && is_numeric($item['available_quantity']);

            return collect([[
                'role' => $role,
                'meli_account_id' => (int) $publication->meli_account_id,
                'meli_publication_id' => (int) $publication->id,
                'mlm' => strtoupper((string) $publication->mlm),
                'source_mlm' => strtoupper(trim((string) ($publication->source_mlm ?? ''))),
                'variation_id' => null,
                'variation_position' => 0,
                'signature' => $signature,
                'sku' => $itemSku,
                'sku_norm' => $skuNorm,
                'available_quantity' => $stockKnown
                    ? max(0, (int) $item['available_quantity'])
                    : null,
                'stock_known' => $stockKnown,
                'link_key' => $linkKey,
                'last_sync_timestamp' => $publication->last_sync_at?->timestamp ?? $publication->updated_at?->timestamp ?? 0,
            ]]);
        }

        return $variations->map(function (array $variation, int $position) use ($publication, $role): array {
            $variationSku = $this->extractSku($variation, '');
            $skuNorm = $this->normalizeSku($variationSku);
            $signature = $this->variationSignature($variation);
            $variationId = trim((string) ($variation['id'] ?? ''));

            $linkKey = $skuNorm !== ''
                ? "sku:{$skuNorm}|sig:{$signature}"
                : 'mlm:'.strtoupper((string) $publication->mlm).'|sig:'.$signature;

            $stockKnown = array_key_exists('available_quantity', $variation)
                && is_numeric($variation['available_quantity']);

            return [
                'role' => $role,
                'meli_account_id' => (int) $publication->meli_account_id,
                'meli_publication_id' => (int) $publication->id,
                'mlm' => strtoupper((string) $publication->mlm),
                'source_mlm' => strtoupper(trim((string) ($publication->source_mlm ?? ''))),
                'variation_id' => $variationId !== '' ? $variationId : null,
                'variation_position' => $position,
                'signature' => $signature,
                'sku' => $variationSku,
                'sku_norm' => $skuNorm,
                'available_quantity' => $stockKnown
                    ? max(0, (int) $variation['available_quantity'])
                    : null,
                'stock_known' => $stockKnown,
                'link_key' => $linkKey,
                'last_sync_timestamp' => $publication->last_sync_at?->timestamp ?? $publication->updated_at?->timestamp ?? 0,
            ];
        });
    }

    /** @return array{status:string, master?:array<string,mixed>, method?:string, reason?:string} */
    private function matchSecondaryRow(array $row, Collection $masterByMlm, Collection $masterBySku): array
    {
        $sourceMlm = $row['source_mlm'];
        if ($sourceMlm !== '' && $masterByMlm->has($sourceMlm)) {
            $result = $this->pickCandidate($row, $masterByMlm->get($sourceMlm), 'source_mlm');
            if ($result['status'] !== 'unmatched') {
                return $result;
            }
        }

        if ($row['sku_norm'] !== '' && $masterBySku->has($row['sku_norm'])) {
            $result = $this->pickCandidate($row, $masterBySku->get($row['sku_norm']), 'sku');
            if ($result['status'] !== 'unmatched') {
                return $result;
            }
        }

        return ['status' => 'unmatched'];
    }

    /** @return array{status:string, master?:array<string,mixed>, method?:string, reason?:string} */
    private function pickCandidate(array $row, Collection $candidates, string $method): array
    {
        if ($candidates->isEmpty()) {
            return ['status' => 'unmatched'];
        }

        $sameShape = $candidates
            ->filter(fn (array $candidate) => ($candidate['variation_id'] === null) === ($row['variation_id'] === null));
        if ($sameShape->isNotEmpty()) {
            $candidates = $sameShape;
        }

        if ($row['sku_norm'] !== '') {
            $sameSku = $candidates->where('sku_norm', $row['sku_norm']);
            if ($sameSku->isNotEmpty()) {
                $candidates = $sameSku;
            }
        }

        $sameSignature = $candidates->where('signature', $row['signature']);
        if ($sameSignature->count() === 1) {
            return [
                'status' => 'matched',
                'master' => $sameSignature->first(),
                'method' => $method.'_signature',
            ];
        }

        if ($sameSignature->count() > 1) {
            $linkKeys = $sameSignature->pluck('link_key')->unique();
            if ($linkKeys->count() === 1) {
                return [
                    'status' => 'matched',
                    'master' => $sameSignature->first(),
                    'method' => $method.'_duplicate_publications',
                ];
            }
        }

        if ($candidates->count() === 1) {
            return [
                'status' => 'matched',
                'master' => $candidates->first(),
                'method' => $method.'_single',
            ];
        }

        $samePosition = $candidates->where('variation_position', $row['variation_position']);
        if ($samePosition->count() === 1) {
            return [
                'status' => 'matched',
                'master' => $samePosition->first(),
                'method' => $method.'_position',
            ];
        }

        return [
            'status' => 'ambiguous',
            'reason' => "{$method}: hay {$candidates->count()} candidatos y no existe coincidencia única de SKU/variante.",
        ];
    }

    /** @param array<string, mixed> $row */
    private function variationSignature(array $row): string
    {
        $attributes = collect((array) ($row['attribute_combinations'] ?? $row['attributes'] ?? []))
            ->filter(fn ($attribute) => is_array($attribute))
            ->map(function (array $attribute): string {
                $id = strtoupper(trim((string) ($attribute['id'] ?? $attribute['name'] ?? 'ATTR')));
                $value = trim((string) (
                    $attribute['value_id']
                    ?? $attribute['value_name']
                    ?? data_get($attribute, 'value_struct.number')
                    ?? ''
                ));

                return $id.':'.mb_strtoupper($value);
            })
            ->filter()
            ->sort()
            ->values();

        return $attributes->isNotEmpty()
            ? sha1($attributes->implode('|'))
            : 'no-signature';
    }

    /** @param array<string, mixed> $row */
    private function extractSku(array $row, string $fallback = ''): string
    {
        $candidates = [
            $row['seller_custom_field'] ?? null,
            $this->attributeValue((array) ($row['attributes'] ?? []), 'SELLER_SKU'),
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return '';
    }

    /** @param array<int, mixed> $attributes */
    private function attributeValue(array $attributes, string $id): ?string
    {
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || strtoupper((string) ($attribute['id'] ?? '')) !== strtoupper($id)) {
                continue;
            }

            $value = $attribute['value_name'] ?? $attribute['value_id'] ?? null;

            return filled($value) ? (string) $value : null;
        }

        return null;
    }

    /**
     * Clasifica por qué una fila de cuenta 2 no pudo conectarse.
     *
     * @return array{counts:array<string,int>,samples:array<int,array<string,mixed>>}
     */
    private function classifyUnmatched(
        Collection $unmatched,
        int $userId,
        int $masterAccountId,
    ): array {
        if ($unmatched->isEmpty()) {
            return ['counts' => [], 'samples' => []];
        }

        $allMasterPublications = MeliPublication::query()
            ->where('user_id', $userId)
            ->where('meli_account_id', $masterAccountId)
            ->orderByDesc('is_current')
            ->orderByDesc('last_sync_at')
            ->get();

        $byMlm = $allMasterPublications->groupBy(
            fn (MeliPublication $publication) => strtoupper(trim((string) $publication->mlm)),
        );

        $bySku = collect();
        foreach ($allMasterPublications as $publication) {
            $item = MeliPublication::itemArrayFromRaw($publication->raw);
            $skus = collect([
                $this->extractSku($item, (string) ($publication->sku ?? '')),
            ]);

            foreach ((array) ($item['variations'] ?? []) as $variation) {
                if (is_array($variation)) {
                    $skus->push($this->extractSku($variation, ''));
                }
            }

            foreach ($skus->map(fn ($sku) => $this->normalizeSku((string) $sku))->filter()->unique() as $skuNorm) {
                $bucket = $bySku->get($skuNorm, collect());
                $bucket->push($publication);
                $bySku->put($skuNorm, $bucket);
            }
        }

        $classified = $unmatched->map(function (array $row) use ($byMlm, $bySku): array {
            $sourceMlm = strtoupper(trim((string) ($row['source_mlm'] ?? '')));
            $skuNorm = $this->normalizeSku((string) ($row['sku'] ?? ''));
            $candidates = collect();
            $origin = 'none';

            if ($sourceMlm !== '' && $byMlm->has($sourceMlm)) {
                $candidates = $byMlm->get($sourceMlm);
                $origin = 'source_mlm';
            } elseif ($skuNorm !== '' && $bySku->has($skuNorm)) {
                $candidates = $bySku->get($skuNorm);
                $origin = 'sku';
            }

            $reason = 'sin_origen_en_cuenta_1';
            $statuses = [];

            if ($candidates->isNotEmpty()) {
                $statuses = $candidates
                    ->map(fn (MeliPublication $publication) => strtolower(trim((string) $publication->status)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $hasVisibleNonFull = $candidates->contains(function (MeliPublication $publication): bool {
                    $item = MeliPublication::itemArrayFromRaw($publication->raw);
                    $logisticType = strtolower(trim((string) data_get($item, 'shipping.logistic_type', '')));

                    return (bool) $publication->is_current
                        && in_array(strtolower((string) $publication->status), ['active', 'paused'], true)
                        && $logisticType !== 'fulfillment';
                });
                $hasFull = $candidates->contains(function (MeliPublication $publication): bool {
                    $item = MeliPublication::itemArrayFromRaw($publication->raw);

                    return strtolower(trim((string) data_get($item, 'shipping.logistic_type', ''))) === 'fulfillment';
                });
                $hasCurrent = $candidates->contains(fn (MeliPublication $publication) => (bool) $publication->is_current);

                $reason = $hasVisibleNonFull
                    ? 'visible_pero_forma_variante_no_coincide'
                    : ($hasFull
                        ? 'origen_full_no_editable'
                        : ($hasCurrent
                            ? 'origen_oculto_por_estado'
                            : 'origen_no_actual'));
            }

            return [
                ...$row,
                'reason' => $reason,
                'origin_lookup' => $origin,
                'master_statuses' => implode(', ', $statuses),
            ];
        });

        return [
            'counts' => $classified->countBy('reason')->sortKeys()->all(),
            'samples' => $classified->take(20)->values()->all(),
        ];
    }

    private function normalizeSku(string $sku): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($sku)) ?? '');
    }

    private function memberKey(int $accountId, string $mlm, ?string $variationId): string
    {
        return mb_substr($accountId.'|'.strtoupper($mlm).'|'.($variationId ?: '0'), 0, 191);
    }
}
