<?php

namespace App\Services\Autopartes\MediaPricing;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliDraftEvent;
use App\Models\AutomotivePartPriceRule;
use Illuminate\Support\Facades\Schema;

class AutomotivePartDraftStalenessService
{
    public function markPart(AutomotivePart|int $part, string $reason, array $metadata = []): int
    {
        if (! Schema::hasTable('automotive_part_meli_drafts')) {
            return 0;
        }
        $partId = $part instanceof AutomotivePart ? $part->id : $part;
        $drafts = AutomotivePartMeliDraft::query()
            ->where('automotive_part_id', $partId)->where('status', '!=', 'stale')->get();
        foreach ($drafts as $draft) {
            $from = $draft->status;
            $draft->forceFill(['status' => 'stale'])->save();
            AutomotivePartMeliDraftEvent::query()->create([
                'automotive_part_meli_draft_id' => $draft->id,
                'action' => 'phase6_source_changed', 'from_status' => $from, 'to_status' => 'stale',
                'metadata' => array_merge(['reason' => $reason, 'approved_snapshot_preserved' => $from === 'approved'], $metadata),
                'created_at' => now(),
            ]);
        }

        return $drafts->count();
    }

    public function markRuleScope(AutomotivePartPriceRule $rule, string $reason): int
    {
        $query = AutomotivePart::query();
        match ($rule->scope_type) {
            'automotive_part' => $query->whereKey((int) $rule->scope_value),
            'vendor' => $query->whereRaw('LOWER(TRIM(COALESCE(vendor_normalized, vendor))) = ?', [strtolower(trim((string) $rule->scope_value))]),
            'category' => $query->whereRaw('LOWER(TRIM(category)) = ?', [strtolower(trim((string) $rule->scope_value))]),
            default => null,
        };
        $count = 0;
        $query->select('id')->chunkById(100, function ($parts) use (&$count, $reason, $rule) {
            foreach ($parts as $part) {
                $count += $this->markPart($part->id, $reason, ['price_rule_id' => $rule->id, 'price_rule_version' => $rule->version]);
            }
        });

        return $count;
    }
}
