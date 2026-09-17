<?php

namespace App\Services\MercadoLibre\PriceManager;

final readonly class MeliBrandClassificationResult
{
    /**
     * @param  list<array<string, int|string>>  $candidates
     */
    public function __construct(
        public string $status,
        public ?int $brandGroupId,
        public ?int $suggestedBrandGroupId,
        public ?string $source,
        public ?string $confidence,
        public ?int $matchedAliasId,
        public ?array $metadata = null,
        public array $candidates = [],
        public ?string $skippedReason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'brand_group_id' => $this->brandGroupId,
            'suggested_brand_group_id' => $this->suggestedBrandGroupId,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'matched_alias_id' => $this->matchedAliasId,
            'metadata' => $this->metadata,
            'candidates' => $this->candidates,
            'skipped_reason' => $this->skippedReason,
        ];
    }
}
