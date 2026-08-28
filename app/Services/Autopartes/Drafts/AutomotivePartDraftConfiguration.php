<?php

namespace App\Services\Autopartes\Drafts;

use App\Models\AutomotivePart;

class AutomotivePartDraftConfiguration
{
    public function isEnabled(): bool
    {
        return (bool) config('autopartes_drafts.enabled', false);
    }

    public function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new AutomotivePartDraftException(
                'La generación de borradores de Autopartes está deshabilitada.',
                'drafts_disabled',
            );
        }
    }

    public function maxBatch(): int
    {
        return max(1, (int) config('autopartes_drafts.max_batch', 10));
    }

    public function pricingRules(): array
    {
        return [
            'usd_mxn_rate' => $this->numericOrNull(config('autopartes_drafts.usd_mxn_rate')),
            'price_markup_percent' => $this->numericOrNull(config('autopartes_drafts.price_markup_percent')),
            'meli_fee_percent' => $this->numericOrNull(config('autopartes_drafts.meli_fee_percent')),
            'currency' => strtoupper(trim((string) config('autopartes_drafts.currency', 'MXN'))),
        ];
    }

    public function contentRules(): array
    {
        return [
            'condition' => filled(config('autopartes_drafts.condition'))
                ? strtolower(trim((string) config('autopartes_drafts.condition')))
                : null,
            'title_min_length' => max(1, (int) config('autopartes_drafts.title_min_length', 10)),
            'title_max_length' => max(1, (int) config('autopartes_drafts.title_max_length', 60)),
            'description_min_length' => max(1, (int) config('autopartes_drafts.description_min_length', 40)),
            'description_max_length' => max(1, (int) config('autopartes_drafts.description_max_length', 50000)),
            'rules_version' => (string) config('autopartes_drafts.rules_version', 'v1'),
        ];
    }

    public function imagesFor(AutomotivePart $part): array
    {
        $imagesBySourceKey = config('autopartes_drafts.images_by_source_key', []);
        $configured = is_array($imagesBySourceKey) ? ($imagesBySourceKey[$part->source_key] ?? []) : [];
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter($configured, function ($url) {
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return false;
            }

            return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        })));
    }

    public function publicSettings(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'max_batch' => $this->maxBatch(),
            'currency' => $this->pricingRules()['currency'],
            'has_exchange_rate' => ($this->pricingRules()['usd_mxn_rate'] ?? 0) > 0,
            'condition_configured' => filled($this->contentRules()['condition']),
            'rules_version' => $this->contentRules()['rules_version'],
        ];
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
