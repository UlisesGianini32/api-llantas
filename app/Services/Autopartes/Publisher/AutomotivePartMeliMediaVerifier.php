<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMedia;
use Illuminate\Support\Facades\Storage;

class AutomotivePartMeliMediaVerifier
{
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function verify(AutomotivePartMedia $media, int $partId, ?string $expectedHash = null): array
    {
        if ($media->automotive_part_id !== $partId || $media->status !== 'approved') $this->fail('La imagen no está aprobada para esta autoparte.', 'media_not_approved');
        if (! preg_match('#^autopartes/media/[0-9]+/[A-Za-z0-9._-]+$#', $media->path)) $this->fail('La ruta privada de imagen no es válida.', 'unsafe_media_path');
        $disk = Storage::disk($media->disk);
        if (! $disk->exists($media->path)) $this->fail('La imagen aprobada ya no existe.', 'media_missing');
        $contents = $disk->get($media->path);
        $sha = hash('sha256', $contents);
        if (! hash_equals((string) $media->sha256, $sha) || ($expectedHash !== null && ! hash_equals($expectedHash, $sha))) {
            $this->fail('La imagen cambió desde su aprobación.', 'media_hash_changed');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! is_string($mime) || ! isset(self::MIMES[$mime]) || $mime !== $media->detected_mime) $this->fail('El MIME de la imagen cambió o no está permitido.', 'media_mime_changed');
        return ['contents' => $contents, 'sha256' => $sha, 'mime' => $mime,
            'filename' => 'automotive-part-'.$media->id.'.'.self::MIMES[$mime]];
    }

    private function fail(string $message, string $code): never { throw new AutomotivePartMeliPublisherException($message, $code); }
}
