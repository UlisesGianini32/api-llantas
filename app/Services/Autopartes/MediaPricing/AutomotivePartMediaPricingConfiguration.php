<?php

namespace App\Services\Autopartes\MediaPricing;

class AutomotivePartMediaPricingConfiguration
{
    public function enabled(): bool { return (bool) config('autopartes_media_pricing.enabled', false); }
    public function disk(): string { return (string) config('autopartes_media_pricing.media_disk', 'local'); }
    public function maxFileBytes(): int { return max(1, (int) config('autopartes_media_pricing.media_max_file_kb', 5120)) * 1024; }
    public function maxWidth(): int { return max(1, (int) config('autopartes_media_pricing.media_max_width', 4096)); }
    public function maxHeight(): int { return max(1, (int) config('autopartes_media_pricing.media_max_height', 4096)); }
    public function maxImages(): int { return max(1, (int) config('autopartes_media_pricing.media_max_images_per_part', 10)); }
    public function maxBatch(): int { return max(1, (int) config('autopartes_media_pricing.price_max_batch', 25)); }
    public function maxMarkup(): float { return max(0, (float) config('autopartes_media_pricing.max_markup_percent', 1000)); }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new AutomotivePartMediaPricingException('La Fase 6 de Autopartes está deshabilitada.', 'media_pricing_disabled');
        }
    }
}
