<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetches and locally caches verified Mercado Libre product pictures so purchase orders,
 * html2canvas/html2pdf exports, and shared supplier PDFs render same-origin without CORS
 * failures and remain resilient if remote ML URLs temporarily fail or expire.
 */
class PurchaseOrderMeliImageCacheService
{
    private const CACHE_DIR = 'purchasing/meli-photos';

    private const CACHE_TTL_SECONDS = 604800; // 7 days

    private const MAX_BYTES = 5_242_880; // 5 MB

    private const MAX_REDIRECTS = 2;

    /** @var array<string, string> */
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly PurchaseOrderMeliImageResolver $resolver,
    ) {}

    /**
     * Retrieve cached or freshly fetched image bytes for a trusted Mercado Libre URL.
     *
     * @return array{bytes: string, mime: string, cached: bool, stale: bool}|null
     */
    public function fetch(string $rawUrl): ?array
    {
        $url = $this->resolver->trustedUrl($rawUrl);
        if ($url === null) {
            return null;
        }

        $hash = hash('sha256', $url);
        $staleCandidate = $this->readCached($hash);

        if ($staleCandidate !== null && ! $staleCandidate['expired']) {
            return [
                'bytes' => $staleCandidate['bytes'],
                'mime' => $staleCandidate['mime'],
                'cached' => true,
                'stale' => false,
            ];
        }

        $downloaded = $this->downloadTrustedImage($url);
        if ($downloaded !== null) {
            $this->writeCached($hash, $url, $downloaded['bytes'], $downloaded['mime']);

            return [
                'bytes' => $downloaded['bytes'],
                'mime' => $downloaded['mime'],
                'cached' => false,
                'stale' => false,
            ];
        }

        // Stale-while-error fallback: if remote URL expired or ML CDN failed, keep serving the cached copy.
        if ($staleCandidate !== null) {
            return [
                'bytes' => $staleCandidate['bytes'],
                'mime' => $staleCandidate['mime'],
                'cached' => true,
                'stale' => true,
            ];
        }

        return null;
    }

    /** @return array{bytes: string, mime: string}|null */
    private function downloadTrustedImage(string $url): ?array
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::timeout(6)
                    ->connectTimeout(3)
                    ->withHeaders([
                        'Accept' => 'image/jpeg,image/png,image/webp,image/gif,*/*;q=0.5',
                        'User-Agent' => 'SBS-Purchasing/1.0',
                    ])
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);
            } catch (Throwable $e) {
                Log::warning('[Purchasing][MeliPhotos] Failed fetching remote photo', [
                    'host' => parse_url($currentUrl, PHP_URL_HOST),
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $status = $response->status();
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $this->resolver->trustedUrl($response->header('Location'));
                if ($location === null) {
                    return null;
                }
                $currentUrl = $location;
                continue;
            }

            if ($status !== 200) {
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_BYTES) {
                return null;
            }

            $mime = $this->detectValidatedImageMime($body);
            if ($mime === null) {
                return null;
            }

            return [
                'bytes' => $body,
                'mime' => $mime,
            ];
        }

        return null;
    }

    private function detectValidatedImageMime(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            return null;
        }

        $mime = strtolower(trim((string) ($info['mime'] ?? '')));

        return array_key_exists($mime, self::ALLOWED_MIMES) ? $mime : null;
    }

    /** @return array{bytes: string, mime: string, expired: bool}|null */
    private function readCached(string $hash): ?array
    {
        try {
            $disk = Storage::disk('local');
            $metaPath = $this->metaPath($hash);
            if (! $disk->exists($metaPath)) {
                return null;
            }

            $meta = json_decode((string) $disk->get($metaPath), true);
            if (! is_array($meta)) {
                return null;
            }

            $mime = strtolower(trim((string) ($meta['mime'] ?? '')));
            $ext = self::ALLOWED_MIMES[$mime] ?? null;
            if ($ext === null) {
                return null;
            }

            $imagePath = $this->imagePath($hash, $ext);
            if (! $disk->exists($imagePath)) {
                return null;
            }

            $bytes = (string) $disk->get($imagePath);
            if ($bytes === '') {
                return null;
            }

            $cachedAt = (int) ($meta['cached_at'] ?? 0);
            $expired = (time() - $cachedAt) > self::CACHE_TTL_SECONDS;

            return [
                'bytes' => $bytes,
                'mime' => $mime,
                'expired' => $expired,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function writeCached(string $hash, string $url, string $bytes, string $mime): void
    {
        $ext = self::ALLOWED_MIMES[$mime] ?? null;
        if ($ext === null) {
            return;
        }

        try {
            $disk = Storage::disk('local');
            $disk->put($this->imagePath($hash, $ext), $bytes);
            $disk->put($this->metaPath($hash), (string) json_encode([
                'mime' => $mime,
                'source_url' => $url,
                'cached_at' => time(),
                'bytes' => strlen($bytes),
            ], JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            Log::warning('[Purchasing][MeliPhotos] Unable to persist local image cache', [
                'hash' => $hash,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function imagePath(string $hash, string $ext): string
    {
        return self::CACHE_DIR.'/'.$hash.'.'.$ext;
    }

    private function metaPath(string $hash): string
    {
        return self::CACHE_DIR.'/'.$hash.'.json';
    }
}
