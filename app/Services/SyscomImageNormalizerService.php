<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Normaliza imágenes de SYSCOM para cumplir requisitos de Mercado Libre:
 * - Tamaño mínimo: 1200×1200 (ML pide mín. 500×500; 1200 es la recomendación oficial).
 * - Fondo blanco.
 * - Producto centrado y ocupando ~92% del cuadro (cumple "posición y proporción").
 *
 * Tiene dos modos de salida:
 *  - normalizeToBytes(): devuelve los bytes JPG en memoria, listos para subirlos a ML
 *    con POST /pictures/items/upload (modo recomendado, así ML aloja la foto).
 *  - normalizeUrlForMeli(): guarda en disk público y devuelve la URL (modo legacy,
 *    útil si ML rechaza el upload o si querés cachear el JPG en tu propio CDN).
 */
class SyscomImageNormalizerService
{
    private string $disk;

    private string $folder;

    private int $finalSize;

    private float $productRatio;

    private int $jpegQuality;

    public function __construct()
    {
        $this->disk = (string) config('syscom.image_normalizer.disk', 'public');
        $this->folder = trim((string) config('syscom.image_normalizer.folder', 'syscom_meli'), '/');
        $this->finalSize = max(500, (int) config('syscom.image_normalizer.final_size', 1200));
        $this->productRatio = (float) config('syscom.image_normalizer.product_ratio', 0.92);
        if ($this->productRatio < 0.5 || $this->productRatio > 1.0) {
            $this->productRatio = 0.92;
        }
        $this->jpegQuality = max(60, min(95, (int) config('syscom.image_normalizer.jpeg_quality', 92)));
    }

    /**
     * Descarga la imagen de SYSCOM, la centra en un lienzo blanco 1200×1200 al ~92% y devuelve los bytes JPG.
     * No toca disco. Ideal para subirla a ML con POST /pictures/items/upload.
     */
    public function normalizeToBytes(string $url): ?string
    {
        $url = trim(
            html_entity_decode(
                $url,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        /*
         * SYSCOM devuelve algunas rutas con espacios reales:
         *
         *   /HIKSEMI by HIKVISION/
         *   /ASSA ABLOY/
         *
         * Antes FILTER_VALIDATE_URL las rechazaba sin intentar
         * siquiera la descarga.
         */
        $url = str_replace(
            ' ',
            '%20',
            $url
        );

        if (
            $url === ''
            || ! filter_var(
                $url,
                FILTER_VALIDATE_URL
            )
        ) {
            Log::warning(
                'SyscomImageNormalizer: URL inválida.',
                [
                    'url' => $url,
                ]
            );

            return null;
        }

        if (! function_exists('imagecreatefromstring')) {
            Log::warning('SyscomImageNormalizer: extensión GD no disponible.', [
                'url' => $url,
            ]);

            return null;
        }

        $bytes = $this->downloadBytes($url);
        if ($bytes === null) {
            return null;
        }

        $orig = @imagecreatefromstring($bytes);
        if (! $orig) {
            Log::warning('SyscomImageNormalizer: imagen ilegible.', ['url' => $url]);

            return null;
        }

        $ow = imagesx($orig);
        $oh = imagesy($orig);
        if ($ow <= 0 || $oh <= 0) {
            imagedestroy($orig);

            return null;
        }

        $canvas = $this->buildCenteredCanvas($orig, $ow, $oh);
        imagedestroy($orig);

        if ($canvas === null) {
            return null;
        }

        // Encodeamos en memoria (sin tempnam ni archivos intermedios) para evitar
        // el aviso "tempnam(): file created in the system's temporary directory"
        // que en PHP 8.4 / configs estrictas se convierte en excepción.
        ob_start();
        $ok = @imagejpeg($canvas, null, $this->jpegQuality);
        $jpgBytes = (string) ob_get_clean();
        imagedestroy($canvas);

        if (! $ok || $jpgBytes === '') {
            Log::warning('SyscomImageNormalizer: no se pudo codificar JPG.', ['url' => $url]);

            return null;
        }

        return $jpgBytes;
    }

    /**
     * Normaliza una URL y devuelve la URL pública lista para ML.
     * (Modo legacy: cachea en storage/app/public/<folder>; usá normalizeToBytes() + uploadPictureBytes() si podés.)
     */
    public function normalizeUrlForMeli(string $url, string $cacheKey, int $index = 0): ?string
    {
        $url = trim(
            html_entity_decode(
                $url,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        /*
         * SYSCOM devuelve algunas rutas con espacios reales:
         *
         *   /HIKSEMI by HIKVISION/
         *   /ASSA ABLOY/
         *
         * Antes FILTER_VALIDATE_URL las rechazaba sin intentar
         * siquiera la descarga.
         */
        $url = str_replace(
            ' ',
            '%20',
            $url
        );

        if (
            $url === ''
            || ! filter_var(
                $url,
                FILTER_VALIDATE_URL
            )
        ) {
            Log::warning(
                'SyscomImageNormalizer: URL inválida.',
                [
                    'url' => $url,
                ]
            );

            return null;
        }

        $disk = Storage::disk($this->disk);
        $hash = sha1($cacheKey . '|' . $index . '|' . $url);
        $relative = $this->folder . '/' . $hash . '.jpg';

        if ($disk->exists($relative)) {
            $path = method_exists($disk, 'path') ? $disk->path($relative) : null;
            if (is_string($path) && $path !== '' && is_file($path)) {
                $info = @getimagesize($path);
                $w = is_array($info) ? (int) ($info[0] ?? 0) : 0;
                $h = is_array($info) ? (int) ($info[1] ?? 0) : 0;
                if ($w >= 500 && $h >= 500) {
                    return $disk->url($relative);
                }
                Log::info('SyscomImageNormalizer: caché JPG subdimensionada, se regenera.', [
                    'w' => $w,
                    'h' => $h,
                    'relative' => $relative,
                ]);
            }
            $disk->delete($relative);
        }

        $jpgBytes = $this->normalizeToBytes($url);
        if ($jpgBytes === null) {
            return null;
        }

        $disk->put($relative, $jpgBytes);

        return $disk->url($relative);
    }

    /**
     * @return list<string> URLs públicas normalizadas (las que fallaron quedan fuera).
     */
    public function normalizeMany(array $urls, string $cacheKey): array
    {
        $out = [];
        foreach (array_values($urls) as $i => $url) {
            $u = $this->normalizeUrlForMeli((string) $url, $cacheKey, (int) $i);
            if ($u !== null && $u !== '') {
                $out[] = $u;
            }
        }

        return $out;
    }

    private function downloadBytes(string $url): ?string
    {
        try {
            $resp = Http::timeout(45)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; MMLlantas/1.0)',
                    'Accept' => 'image/*,*/*;q=0.8',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('SyscomImageNormalizer: error de red al descargar.', [
                'url' => $url,
                'err' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $resp->successful()) {
            Log::warning('SyscomImageNormalizer: descarga no exitosa.', [
                'url' => $url,
                'status' => $resp->status(),
            ]);

            return null;
        }

        $bytes = (string) $resp->body();
        if ($bytes === '' || strlen($bytes) < 256) {
            return null;
        }

        return $bytes;
    }

    /**
     * Crea el canvas final 1200×1200 blanco con la imagen original reescalada al ~92%
     * y centrada. Si la imagen original es más grande que el área permitida, se reduce;
     * si es más chica, se amplía con resampling para no quedar pixelada.
     *
     * @return \GdImage|null
     */
    private function buildCenteredCanvas(\GdImage $orig, int $ow, int $oh): mixed
    {
        $maxAspect = (float) config('syscom.image_normalizer.max_aspect_before_crop', 2.5);
        if ($maxAspect >= 1.0 && $ow > 0 && $oh > 0) {
            $ratio = $ow >= $oh ? ($ow / $oh) : ($oh / $ow);
            if ($ratio > $maxAspect) {
                $side = min($ow, $oh);
                $sx = (int) max(0, (int) floor(($ow - $side) / 2));
                $sy = (int) max(0, (int) floor(($oh - $side) / 2));
                $cropRect = ['x' => $sx, 'y' => $sy, 'width' => $side, 'height' => $side];
                $cropped = @imagecrop($orig, $cropRect);
                if ($cropped instanceof \GdImage) {
                    imagedestroy($orig);
                    $orig = $cropped;
                    $ow = $side;
                    $oh = $side;
                }
            }
        }

        $size = $this->finalSize;
        $maxInner = max(1, (int) round($size * $this->productRatio));

        $scale = min($maxInner / $ow, $maxInner / $oh);
        $tw = max(1, (int) round($ow * $scale));
        $th = max(1, (int) round($oh * $scale));

        $canvas = imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $size - 1, $size - 1, $white);

        $resized = imagecreatetruecolor($tw, $th);
        if ($resized === false) {
            imagedestroy($canvas);

            return null;
        }

        $whiteR = imagecolorallocate($resized, 255, 255, 255);
        imagefilledrectangle($resized, 0, 0, $tw - 1, $th - 1, $whiteR);

        imagealphablending($orig, true);
        imagecopyresampled($resized, $orig, 0, 0, 0, 0, $tw, $th, $ow, $oh);

        $dx = (int) round(($size - $tw) / 2);
        $dy = (int) round(($size - $th) / 2);
        imagecopy($canvas, $resized, $dx, $dy, 0, 0, $tw, $th);

        imagedestroy($resized);

        return $canvas;
    }
}
