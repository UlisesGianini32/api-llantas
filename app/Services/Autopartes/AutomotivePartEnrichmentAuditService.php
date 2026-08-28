<?php

namespace App\Services\Autopartes;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;
use App\Models\AutomotivePartImportRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AutomotivePartEnrichmentAuditService
{
    public const ISSUE_CODES = [
        'missing_compatibility',
        'missing_model_year',
        'invalid_model_year_range',
        'missing_vendor',
        'missing_mfg_part_number',
        'missing_description',
        'missing_dimensions',
        'missing_weight',
        'missing_price',
        'duplicate_source_key',
        'internal_category_requires_mapping',
        'needs_spanish_content',
    ];

    private const INTERNAL_CATEGORIES = ['PDQ36', 'FNWI', 'ROTORS', 'WIRE'];

    public function audit(?int $limit = null, ?int $partId = null, bool $refreshApproved = false): array
    {
        $stats = [
            'reviewed' => 0,
            'created' => 0,
            'updated' => 0,
            'approved_skipped' => 0,
            'rejected_skipped' => 0,
            'errors' => 0,
            'error_details' => [],
        ];

        $query = AutomotivePart::query()->orderBy('id');

        if ($partId !== null) {
            $query->whereKey($partId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $parts = $query->get();
        $duplicateSourceKeys = $this->duplicateSourceKeys($parts);

        foreach ($parts as $part) {
            $stats['reviewed']++;

            try {
                $review = AutomotivePartEnrichmentReview::query()
                    ->where('automotive_part_id', $part->id)
                    ->first();

                if ($review?->status === 'approved' && ! $refreshApproved) {
                    $stats['approved_skipped']++;

                    continue;
                }

                if ($review?->status === 'rejected') {
                    $stats['rejected_skipped']++;

                    continue;
                }

                $issueCodes = $this->detectIssues($part, $duplicateSourceKeys->has($part->source_key));
                if ($this->hasCompleteManualSpanishContent($review)) {
                    $issueCodes = array_values(array_diff($issueCodes, ['needs_spanish_content']));
                }

                if ($review === null && $issueCodes === []) {
                    continue;
                }

                if ($review === null) {
                    AutomotivePartEnrichmentReview::query()->create([
                        'automotive_part_id' => $part->id,
                        'status' => 'pending',
                        'issue_codes' => $issueCodes,
                        'proposed_title' => $this->buildRuleBasedTitle($part),
                        'enrichment_source' => 'rules',
                        'metadata' => $this->auditMetadata($issueCodes),
                    ]);
                    $stats['created']++;

                    continue;
                }

                $review->forceFill([
                    'issue_codes' => $issueCodes,
                    'metadata' => array_merge($review->metadata ?? [], $this->auditMetadata($issueCodes)),
                ])->save();
                $stats['updated']++;
            } catch (Throwable $exception) {
                $stats['errors']++;

                $errorDetail = [
                    'automotive_part_id' => $part->id,
                    'exception_class' => $exception::class,
                    'message' => $this->sanitizeExceptionMessage($exception->getMessage()),
                ];

                if (count($stats['error_details']) < 10) {
                    $stats['error_details'][] = $errorDetail;
                }

                Log::error('Automotive part enrichment audit failed.', [
                    ...$errorDetail,
                    'exception' => $exception,
                ]);
            }
        }

        return $stats;
    }

    public function detectIssues(AutomotivePart $part, bool $hasDuplicateImportRow = false): array
    {
        $issues = [];

        if ($this->blank($part->applicable_models_text)) {
            $issues[] = 'missing_compatibility';
        }

        if ($part->min_model_year === null && $part->average_model_year === null && $part->max_model_year === null) {
            $issues[] = 'missing_model_year';
        }

        if ($this->hasInvalidModelYearRange($part)) {
            $issues[] = 'invalid_model_year_range';
        }

        if ($this->blank($part->vendor)) {
            $issues[] = 'missing_vendor';
        }

        if ($this->blank($part->manufacturer_part_number)) {
            $issues[] = 'missing_mfg_part_number';
        }

        if ($this->blank($part->description_original)) {
            $issues[] = 'missing_description';
        }

        if ($part->length_inches === null || $part->width_inches === null || $part->height_inches === null) {
            $issues[] = 'missing_dimensions';
        }

        if ($part->weight_pounds === null) {
            $issues[] = 'missing_weight';
        }

        if ($part->retail_price_original === null) {
            $issues[] = 'missing_price';
        }

        if ($hasDuplicateImportRow) {
            $issues[] = 'duplicate_source_key';
        }

        $category = strtoupper(trim((string) $part->category));
        if (in_array($category, self::INTERNAL_CATEGORIES, true)) {
            $issues[] = 'internal_category_requires_mapping';
        }

        $issues[] = 'needs_spanish_content';

        return $issues;
    }

    private function duplicateSourceKeys(Collection $parts): Collection
    {
        $sourceKeys = $parts->pluck('source_key')->filter()->values();

        if ($sourceKeys->isEmpty()) {
            return collect();
        }

        return AutomotivePartImportRow::query()
            ->whereIn('source_key', $sourceKeys)
            ->whereNotNull('duplicate_of_row_id')
            ->pluck('source_key')
            ->filter()
            ->flip();
    }

    private function hasInvalidModelYearRange(AutomotivePart $part): bool
    {
        $min = $part->min_model_year;
        $average = $part->average_model_year;
        $max = $part->max_model_year;

        if ($min !== null && $max !== null && $min > $max) {
            return true;
        }

        return $average !== null
            && (($min !== null && $average < $min) || ($max !== null && $average > $max));
    }

    private function hasCompleteManualSpanishContent(?AutomotivePartEnrichmentReview $review): bool
    {
        if ($review === null || $review->enrichment_source !== 'manual') {
            return false;
        }

        $title = trim((string) $review->proposed_title);
        $description = trim((string) $review->proposed_description);

        if (mb_strlen($title) < 10 || mb_strlen($description) < 40) {
            return false;
        }

        $content = $title.' '.$description;

        return preg_match('/[áéíóúüñ¿¡]|\b(el|la|los|las|un|una|para|con|sin|de|del|en|y|compatible|repuesto|pieza|vehículo|incluye)\b/iu', $content) === 1;
    }

    private function buildRuleBasedTitle(AutomotivePart $part): ?string
    {
        $yearRange = null;
        if ($part->min_model_year !== null && $part->max_model_year !== null) {
            $yearRange = $part->min_model_year === $part->max_model_year
                ? (string) $part->min_model_year
                : $part->min_model_year.'-'.$part->max_model_year;
        }

        $segments = collect([
            $part->description_original,
            $part->vendor,
            $part->manufacturer_part_number,
            $part->prevalent_model,
            $yearRange,
        ])->filter(fn ($value) => ! $this->blank($value))
            ->map(fn ($value) => trim(preg_replace('/\s+/u', ' ', (string) $value)))
            ->unique(fn ($value) => mb_strtolower($value))
            ->values();

        return $segments->isEmpty() ? null : Str::limit($segments->implode(' · '), 255, '');
    }

    private function auditMetadata(array $issueCodes): array
    {
        return [
            'audit_version' => 1,
            'audited_at' => now()->toIso8601String(),
            'detected_issue_count' => count($issueCodes),
            'title_draft_method' => 'deterministic_rules',
        ];
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function sanitizeExceptionMessage(string $message): string
    {
        $sanitized = preg_replace([
            '/\b(password|passwd|pwd|token|secret|authorization|api[_-]?key)\b(\s*[=:]\s*)([^\s,;]+)/iu',
            '/(https?:\/\/)[^@\s\/]+@/iu',
        ], [
            '$1$2[REDACTED]',
            '$1[REDACTED]@',
        ], $message) ?? 'Error sin mensaje disponible.';

        return Str::limit($sanitized, 1000, '…');
    }
}
