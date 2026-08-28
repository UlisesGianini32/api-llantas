<?php

namespace App\Services\Autopartes\Drafts;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliDraftEvent;
use Illuminate\Support\Facades\DB;

class AutomotivePartDraftGenerator
{
    public function __construct(
        private AutomotivePartDraftConfiguration $configuration,
        private AutomotivePartDraftBuilder $builder,
        private AutomotivePartDraftLocalOnlyGuard $localOnly,
    ) {}

    public function generate(AutomotivePart $part, bool $force = false): array
    {
        $this->localOnly->assertLocalOperation($force ? 'regenerate' : 'generate');
        $this->configuration->assertEnabled();
        $preview = $this->builder->preview($part);

        return DB::transaction(function () use ($part, $preview, $force) {
            AutomotivePart::query()->lockForUpdate()->findOrFail($part->id);
            $existing = AutomotivePartMeliDraft::query()
                ->where('automotive_part_id', $part->id)
                ->where('fingerprint', $preview['fingerprint'])
                ->first();
            if ($existing !== null) {
                return ['draft' => $existing, 'created' => false, 'force' => $force];
            }

            $previous = AutomotivePartMeliDraft::query()
                ->where('automotive_part_id', $part->id)
                ->where('status', '!=', 'stale')
                ->lockForUpdate()
                ->get();
            foreach ($previous as $oldDraft) {
                $from = $oldDraft->status;
                $oldDraft->forceFill(['status' => 'stale'])->save();
                AutomotivePartMeliDraftEvent::query()->create([
                    'automotive_part_meli_draft_id' => $oldDraft->id,
                    'action' => 'source_changed',
                    'from_status' => $from,
                    'to_status' => 'stale',
                    'metadata' => ['replacement_fingerprint' => $preview['fingerprint']],
                    'created_at' => now(),
                ]);
            }

            $payload = $preview['payload'];
            $draft = AutomotivePartMeliDraft::query()->create([
                'automotive_part_id' => $part->id,
                'automotive_part_enrichment_review_id' => $preview['automotive_part_enrichment_review_id'],
                'approved_category_candidate_id' => $preview['approved_category_candidate_id'],
                'version' => ((int) AutomotivePartMeliDraft::query()->where('automotive_part_id', $part->id)->max('version')) + 1,
                'category_id' => $payload['category_id'],
                'category_name' => $payload['category_name'],
                'domain_id' => $payload['domain_id'],
                'title' => $payload['title'],
                'description' => $payload['description'],
                'price_mxn' => $payload['price_mxn'],
                'stock' => $payload['stock'],
                'currency' => $payload['currency'],
                'condition' => $payload['condition'],
                'prepared_attributes' => $payload['prepared_attributes'],
                'prepared_compatibilities' => $payload['prepared_compatibilities'],
                'prepared_images' => $payload['prepared_images'],
                'source_snapshot' => $preview['source_snapshot'],
                'fingerprint' => $preview['fingerprint'],
                'status' => $preview['suggested_status'],
                'blocking_errors' => $preview['blocking_errors'],
                'warnings' => $preview['warnings'],
                'generated_at' => now(),
            ]);
            AutomotivePartMeliDraftEvent::query()->create([
                'automotive_part_meli_draft_id' => $draft->id,
                'action' => 'generated',
                'from_status' => null,
                'to_status' => $draft->status,
                'metadata' => ['fingerprint' => $draft->fingerprint, 'force' => $force],
                'created_at' => now(),
            ]);

            return ['draft' => $draft, 'created' => true, 'force' => $force];
        });
    }
}
