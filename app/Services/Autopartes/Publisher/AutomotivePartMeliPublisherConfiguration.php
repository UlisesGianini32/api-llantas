<?php

namespace App\Services\Autopartes\Publisher;

class AutomotivePartMeliPublisherConfiguration
{
    public function enabled(): bool { return (bool) config('autopartes_meli_publisher.enabled', false); }
    public function remoteValidationEnabled(): bool { return (bool) config('autopartes_meli_publisher.remote_validation_enabled', false); }
    public function imageUploadEnabled(): bool { return (bool) config('autopartes_meli_publisher.image_upload_enabled', false); }
    public function liveEnabled(): bool { return (bool) config('autopartes_meli_publisher.live_enabled', false); }
    public function listingTypeId(): ?string { $value = trim((string) config('autopartes_meli_publisher.listing_type_id')); return $value === '' ? null : $value; }
    public function buyingMode(): ?string { $value = trim((string) config('autopartes_meli_publisher.buying_mode')); return $value === '' ? null : $value; }
    public function channels(): array { return array_values(array_filter((array) config('autopartes_meli_publisher.channels', []), fn ($v) => is_string($v) && preg_match('/^[a-z0-9_-]+$/', $v))); }
    public function maxBatch(): int { return max(1, min(1, (int) config('autopartes_meli_publisher.max_batch', 1))); }
    public function maxDailyItems(): int { return max(1, (int) config('autopartes_meli_publisher.max_daily_items', 1)); }
    public function timeout(): int { return max(1, min(120, (int) config('autopartes_meli_publisher.timeout', 30))); }
    public function validationTtlMinutes(): int { return max(1, (int) config('autopartes_meli_publisher.validation_ttl_minutes', 60)); }
    public function rulesVersion(): string { return (string) config('autopartes_meli_publisher.rules_version', 'v1'); }
    public function baseUrl(): string { return 'https://api.mercadolibre.com'; }
    public function configuredAccountId(): ?int { $value = config('autopartes_meli_publisher.account_id'); return filter_var($value, FILTER_VALIDATE_INT) && (int) $value > 0 ? (int) $value : null; }
    public function publicSettings(): array { return ['enabled' => $this->enabled(), 'remote_validation_enabled' => $this->remoteValidationEnabled(),
        'image_upload_enabled' => $this->imageUploadEnabled(), 'live_enabled' => $this->liveEnabled(),
        'listing_type_configured' => $this->listingTypeId() !== null, 'account_configured' => $this->configuredAccountId() !== null,
        'max_batch' => $this->maxBatch(), 'max_daily_items' => $this->maxDailyItems(), 'validation_ttl_minutes' => $this->validationTtlMinutes()]; }

    public function assertPublisher(): void { if (! $this->enabled()) $this->fail('El publicador de Autopartes está deshabilitado.', 'publisher_disabled'); }
    public function assertRemoteValidation(): void { $this->assertPublisher(); if (! $this->remoteValidationEnabled()) $this->fail('La validación remota está deshabilitada.', 'remote_validation_disabled'); }
    public function assertImageUpload(): void { $this->assertPublisher(); if (! $this->imageUploadEnabled()) $this->fail('La carga remota de imágenes está deshabilitada.', 'image_upload_disabled'); }
    public function assertLive(): void { $this->assertPublisher(); if (! $this->liveEnabled()) $this->fail('La publicación real está deshabilitada.', 'live_publication_disabled'); }
    private function fail(string $message, string $code): never { throw new AutomotivePartMeliPublisherException($message, $code); }
}
