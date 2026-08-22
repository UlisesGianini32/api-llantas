<?php

namespace App\Services\Autopartes;

class AutomotivePartNormalizer
{
    public function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $normalized !== null ? trim($normalized) : null;
    }

    public function normalizePartNumber(?string $value): ?string
    {
        $normalized = $this->normalizeText($value);
        if ($normalized === null) {
            return null;
        }

        return strtoupper(str_replace(['\u00a0', ' '], '', $normalized));
    }

    public function normalizeVendor(?string $value): ?string
    {
        $normalized = $this->normalizeText($value);
        if ($normalized === null) {
            return null;
        }

        return ucwords(strtolower($normalized));
    }

    public function parseInteger(?string $value): ?int
    {
        $normalized = $this->normalizeText($value);
        if ($normalized === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9\-]/', '', $normalized);
        if ($clean === '' || $clean === '-' || $clean === '-0') {
            return null;
        }

        return (int) $clean;
    }

    public function parseDecimal(?string $value): ?float
    {
        $normalized = $this->normalizeText($value);
        if ($normalized === null) {
            return null;
        }

        $normalized = str_replace(['$', ',', ' ', '"'], '', $normalized);
        $normalized = str_replace(['USD', 'usd'], '', $normalized);
        $normalized = str_replace(['\u00a0', '\n', '\r'], '', $normalized);

        if ($normalized === '') {
            return null;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', $normalized);
    }

    public function makeSourceKey(?string $itemNumber, ?string $manufacturerPartNumber, ?string $vendor): string
    {
        $item = $this->normalizePartNumber($itemNumber) ?? '';
        $manufacturer = $this->normalizePartNumber($manufacturerPartNumber) ?? '';
        $vendor = $this->normalizeVendor($vendor) ?? '';

        $payload = strtolower($item.'|'.$manufacturer.'|'.$vendor);

        return hash('sha256', $payload);
    }

    public function inchesToCm(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($value * 2.54, 4);
    }

    public function poundsToKg(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($value * 0.45359237, 4);
    }

    public function parseYear(?string $value): ?int
    {
        $parsed = $this->parseInteger($value);
        if ($parsed === null) {
            return null;
        }

        if ($parsed < 1900 || $parsed > 2100) {
            return null;
        }

        return $parsed;
    }

    public function buildMissingFields(array $payload): array
    {
        $missing = [];

        foreach (['item_number', 'manufacturer_part_number', 'vendor', 'category', 'description_original'] as $field) {
            if (empty($payload[$field])) {
                $missing[] = $field;
            }
        }

        if (empty($payload['applicable_models_text'])) {
            $missing[] = 'applicable_models';
        }

        return $missing;
    }
}
