<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveCatalogFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AutomotiveOriginalStore
{
    public function preserve(string $source, string $hash): string
    {
        $relative = $hash.'/original';
        $disk = Storage::disk('automotive_originals');
        $disk->makeDirectory($hash);
        $target = $disk->path($relative);
        if (! is_file($target)) {
            $temporary = $target.'.pending-'.bin2hex(random_bytes(12));
            $out = @fopen($temporary, 'xb');
            if ($out === false) {
                throw new RuntimeException('No se pudo crear el archivo temporal privado.');
            }
            $in = fopen($source, 'rb');
            try {
                if ($in === false || stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException('No se pudo conservar el original.');
                }
                fflush($out);
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
                fclose($out);
            }
            try {
                if (! hash_equals($hash, hash_file('sha256', $temporary))) {
                    throw new RuntimeException('La copia temporal no coincide con el original.');
                }
                // Publish a completed file atomically without replacing an existing path.
                if (! @link($temporary, $target) && ! @rename($temporary, $target) && ! is_file($target)) {
                    throw new RuntimeException('El almacenamiento local no permite publicar el original de forma exclusiva.');
                }
            } finally {
                // Only this invocation's generated temporary file is removed.
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        if (! hash_equals($hash, hash_file('sha256', $target))) {
            throw new RuntimeException('El original conservado no coincide con su hash; requiere revisión sin sobrescribirlo.');
        }

        return $relative;
    }

    public function verifiedPath(AutomotiveCatalogFile $file): string
    {
        if ($file->disk !== 'automotive_originals' || $file->storage_path !== $file->sha256.'/original') {
            throw new RuntimeException('Referencia de almacenamiento no válida.');
        }
        $path = Storage::disk('automotive_originals')->path($file->storage_path);
        if (! is_file($path) || ! hash_equals($file->sha256, hash_file('sha256', $path))) {
            throw new RuntimeException('No se pudo verificar la integridad del original.');
        }

        return $path;
    }
}
