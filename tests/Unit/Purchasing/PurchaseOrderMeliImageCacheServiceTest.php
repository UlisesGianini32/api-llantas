<?php

namespace Tests\Unit\Purchasing;

use App\Services\Purchasing\PurchaseOrderMeliImageCacheService;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use OverflowException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tests\TestCase;

class PurchaseOrderMeliImageCacheServiceTest extends TestCase
{
    private PurchaseOrderMeliImageCacheService $service;

    private string $tinyPngBytes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tinyPngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ) ?: '';

        $this->service = app(PurchaseOrderMeliImageCacheService::class);
    }

    public function test_rejects_oversized_response_by_content_length_without_reading_stream(): void
    {
        $url = 'https://http2.mlstatic.com/oversized-with-header.png';
        $spyStream = new SpyChunkedStream(totalBytesToEmit: 10 * 1024 * 1024);

        Http::fake([
            $url => static fn () => Create::promiseFor(new Psr7Response(
                200,
                [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) (PurchaseOrderMeliImageCacheService::MAX_BYTES + 1024),
                ],
                $spyStream,
            )),
        ]);

        $result = $this->service->fetch($url);

        $this->assertNull($result);
        $this->assertSame(0, $spyStream->bytesRead, 'Stream must not be read when Content-Length exceeds 5 MB.');
        $this->assertTrue($spyStream->closed, 'Stream must be closed immediately upon rejection.');
    }

    public function test_aborts_chunked_transfer_without_content_length_when_stream_exceeds_5mb(): void
    {
        $url = 'https://http2.mlstatic.com/oversized-chunked-no-length.png';
        $tenMegabytes = 10 * 1024 * 1024;
        $spyStream = new SpyChunkedStream(totalBytesToEmit: $tenMegabytes);

        Http::fake([
            $url => static fn () => Create::promiseFor(new Psr7Response(
                200,
                [
                    'Content-Type' => 'image/png',
                    'Transfer-Encoding' => 'chunked',
                ],
                $spyStream,
            )),
        ]);

        $result = $this->service->fetch($url);

        $this->assertNull($result);
        $this->assertTrue($spyStream->closed, 'Chunked stream must be closed as soon as 5 MB limit is exceeded.');
        $this->assertLessThanOrEqual(
            PurchaseOrderMeliImageCacheService::MAX_BYTES + 1,
            $spyStream->bytesRead,
            'Downloader must stop immediately upon crossing MAX_BYTES rather than buffering full 10 MB body.'
        );
        $this->assertGreaterThan(PurchaseOrderMeliImageCacheService::MAX_BYTES, $spyStream->bytesRead);
    }

    public function test_downloads_and_caches_valid_streamed_image_without_content_length(): void
    {
        $url = 'https://http2.mlstatic.com/valid-chunked-no-length.png';
        $spyStream = new SpyChunkedStream(payload: $this->tinyPngBytes);

        Http::fake([
            $url => static fn () => Create::promiseFor(new Psr7Response(
                200,
                [
                    'Content-Type' => 'image/png',
                    'Transfer-Encoding' => 'chunked',
                ],
                $spyStream,
            )),
        ]);

        $result = $this->service->fetch($url);

        $this->assertNotNull($result);
        $this->assertSame($this->tinyPngBytes, $result['bytes']);
        $this->assertSame('image/png', $result['mime']);
        $this->assertFalse($result['cached']);
        $this->assertFalse($result['stale']);
        $this->assertTrue($spyStream->closed);

        // Subsequent call comes from local cache with zero additional HTTP requests
        $cached = $this->service->fetch($url);
        $this->assertNotNull($cached);
        $this->assertTrue($cached['cached']);
        Http::assertSentCount(1);
    }

    public function test_aborts_oversized_refresh_without_content_length_and_falls_back_to_stale_cache(): void
    {
        $url = 'https://http2.mlstatic.com/stale-fallback-oversized.png';
        $hash = hash('sha256', $url);

        Storage::disk('local')->put("purchasing/meli-photos/{$hash}.png", $this->tinyPngBytes);
        Storage::disk('local')->put("purchasing/meli-photos/{$hash}.json", (string) json_encode([
            'mime' => 'image/png',
            'source_url' => $url,
            'cached_at' => time() - (10 * 86400),
            'bytes' => strlen($this->tinyPngBytes),
        ]));

        $spyStream = new SpyChunkedStream(totalBytesToEmit: 8 * 1024 * 1024);

        Http::fake([
            $url => static fn () => Create::promiseFor(new Psr7Response(
                200,
                ['Content-Type' => 'image/png'],
                $spyStream,
            )),
        ]);

        $result = $this->service->fetch($url);

        $this->assertNotNull($result);
        $this->assertTrue($result['cached']);
        $this->assertTrue($result['stale']);
        $this->assertSame($this->tinyPngBytes, $result['bytes']);
        $this->assertTrue($spyStream->closed);
        $this->assertLessThanOrEqual(PurchaseOrderMeliImageCacheService::MAX_BYTES + 1, $spyStream->bytesRead);
    }

    public function test_configures_stream_and_progress_limit_options_on_http_request(): void
    {
        $url = 'https://http2.mlstatic.com/options-check.png';
        $capturedProgress = null;
        $streamEnabled = false;

        Http::fake(function (HttpClientRequest $request, array $options) use (&$capturedProgress, &$streamEnabled) {
            $streamEnabled = (bool) ($options['stream'] ?? false);
            $capturedProgress = $options['progress'] ?? null;

            return Create::promiseFor(new Psr7Response(
                200,
                ['Content-Type' => 'image/png'],
                new SpyChunkedStream(payload: $this->tinyPngBytes),
            ));
        });

        $result = $this->service->fetch($url);

        $this->assertNotNull($result);
        $this->assertTrue($streamEnabled, 'HTTP client must enable stream=true to avoid buffering unbounded bodies.');
        $this->assertIsCallable($capturedProgress, 'HTTP client must register a progress callback enforcing MAX_BYTES.');

        // Calling progress within limit succeeds
        $capturedProgress(PurchaseOrderMeliImageCacheService::MAX_BYTES, PurchaseOrderMeliImageCacheService::MAX_BYTES);

        // Exceeding limit in progress callback aborts transfer with OverflowException
        $this->expectException(OverflowException::class);
        $capturedProgress(0, PurchaseOrderMeliImageCacheService::MAX_BYTES + 1);
    }
}

/**
 * Minimal PSR-7 StreamInterface spy that emits chunks lazily without allocating the entire payload,
 * reports null size (simulating chunked transfer without Content-Length), and records exact bytes read.
 */
final class SpyChunkedStream implements StreamInterface
{
    public int $bytesRead = 0;

    public bool $closed = false;

    private int $offset = 0;

    private readonly int $totalLength;

    public function __construct(
        private readonly ?string $payload = null,
        ?int $totalBytesToEmit = null,
    ) {
        $this->totalLength = $payload !== null ? strlen($payload) : (int) ($totalBytesToEmit ?? 0);
    }

    public function __toString(): string
    {
        throw new RuntimeException('Unbounded __toString() must not be called on bounded streaming downloads.');
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->closed = true;

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function eof(): bool
    {
        return $this->closed || $this->offset >= $this->totalLength;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Stream is not rewindable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable.');
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function read(int $length): string
    {
        if ($this->eof() || $length <= 0) {
            return '';
        }

        $remaining = $this->totalLength - $this->offset;
        $toRead = min($length, $remaining);

        if ($this->payload !== null) {
            $chunk = substr($this->payload, $this->offset, $toRead);
        } else {
            $chunk = str_repeat('A', $toRead);
        }

        $this->offset += strlen($chunk);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        throw new RuntimeException('Unbounded getContents() must not be called on bounded streaming downloads.');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
