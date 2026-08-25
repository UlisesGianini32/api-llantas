<?php

namespace App\Services\Autopartes\MediaPricing;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMedia;
use App\Models\AutomotivePartMediaEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AutomotivePartMediaService
{
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private AutomotivePartMediaPricingConfiguration $configuration,
        private AutomotivePartMediaPricingLocalOnlyGuard $guard,
        private AutomotivePartDraftStalenessService $staleness,
    ) {}

    public function upload(AutomotivePart $part, UploadedFile $file, string $provenance, ?string $reference, ?string $notes, User $user): AutomotivePartMedia
    {
        $this->guard->assert('upload_media');
        $this->configuration->assertEnabled();
        if (! in_array($provenance, AutomotivePartMedia::PROVENANCE_TYPES, true)) {
            $this->fail('La procedencia no es válida.', 'invalid_provenance');
        }
        $originalPath = $file->getClientOriginalPath();
        $original = $file->getClientOriginalName();
        if ($this->hasUnsafeOriginalName($originalPath) || $this->hasUnsafeOriginalName($original)) {
            $this->fail('El nombre original contiene una ruta no permitida.', 'path_traversal');
        }
        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            $this->fail('No se pudo leer el archivo.', 'unreadable_file');
        }
        $size = filesize($realPath);
        if ($size === false || $size < 1 || $size > $this->configuration->maxFileBytes()) {
            $this->fail('El archivo supera el tamaño permitido.', 'invalid_file_size');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $dimensions = @getimagesize($realPath);
        if (! is_string($mime) || ! isset(self::MIME_EXTENSIONS[$mime]) || $dimensions === false || ($dimensions['mime'] ?? null) !== $mime) {
            $this->fail('El contenido no es una imagen JPEG, PNG o WebP válida.', 'invalid_image_content');
        }
        [$width, $height] = $dimensions;
        if ($width < 1 || $height < 1 || $width > $this->configuration->maxWidth() || $height > $this->configuration->maxHeight()) {
            $this->fail('Las dimensiones exceden los límites permitidos.', 'invalid_dimensions');
        }
        $sha = hash_file('sha256', $realPath);
        if (AutomotivePartMedia::query()->where('automotive_part_id', $part->id)->where('sha256', $sha)->exists()) {
            $this->fail('La misma imagen ya está registrada para esta autoparte.', 'duplicate_media');
        }
        $activeCount = AutomotivePartMedia::query()->where('automotive_part_id', $part->id)
            ->whereIn('status', ['pending', 'approved'])->count();
        if ($activeCount >= $this->configuration->maxImages()) {
            $this->fail('Se alcanzó el máximo de imágenes para la autoparte.', 'media_limit_reached');
        }

        $disk = $this->configuration->disk();
        $extension = self::MIME_EXTENSIONS[$mime];
        $storedName = Str::random(40).'.'.$extension;
        $path = 'autopartes/media/'.$part->id.'/'.$storedName;
        if (! Storage::disk($disk)->put($path, file_get_contents($realPath))) {
            $this->fail('No se pudo almacenar el archivo.', 'storage_failed');
        }

        try {
            return DB::transaction(function () use ($part, $disk, $path, $original, $storedName, $mime, $extension, $size, $width, $height, $sha, $provenance, $reference, $notes, $user) {
                $media = AutomotivePartMedia::query()->create([
                    'automotive_part_id' => $part->id, 'disk' => $disk, 'path' => $path,
                    'original_name' => mb_substr(basename($original), 0, 255), 'stored_name' => $storedName,
                    'detected_mime' => $mime, 'extension' => $extension, 'size_bytes' => $size,
                    'width' => $width, 'height' => $height, 'sha256' => $sha,
                    'position' => ((int) AutomotivePartMedia::query()->where('automotive_part_id', $part->id)->max('position')) + 1,
                    'is_primary' => false, 'status' => 'pending', 'provenance_type' => $provenance,
                    'provenance_reference' => $reference, 'notes' => $notes, 'uploaded_by' => $user->id,
                    'uploaded_at' => now(), 'metadata' => ['content_verified' => true],
                ]);
                $this->event($media, 'uploaded', null, 'pending', $user, $notes);
                return $media;
            });
        } catch (Throwable $exception) {
            // Solo limpia el archivo nuevo si la transacción que debía persistirlo falló.
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function approve(AutomotivePartMedia $media, User $user): AutomotivePartMedia
    {
        return $this->transition($media, $user, 'approve_media', 'approved', null, function (AutomotivePartMedia $locked) use ($user) {
            $primaryExists = AutomotivePartMedia::query()->where('automotive_part_id', $locked->automotive_part_id)
                ->where('status', 'approved')->where('is_primary', true)->where('id', '!=', $locked->id)->exists();
            $locked->forceFill(['approved_by' => $user->id, 'approved_at' => now(), 'rejected_by' => null,
                'rejected_at' => null, 'is_primary' => ! $primaryExists,
                'approved_primary_slot' => $primaryExists ? null : $locked->automotive_part_id])->save();
        });
    }

    public function reject(AutomotivePartMedia $media, User $user, string $note): AutomotivePartMedia
    {
        if (trim($note) === '') $this->fail('La nota de rechazo es obligatoria.', 'rejection_note_required');
        return $this->transition($media, $user, 'reject_media', 'rejected', $note, function (AutomotivePartMedia $locked) use ($user, $note) {
            $locked->forceFill(['rejected_by' => $user->id, 'rejected_at' => now(), 'notes' => $note,
                'is_primary' => false, 'approved_primary_slot' => null])->save();
        });
    }

    public function archive(AutomotivePartMedia $media, User $user, ?string $note = null): AutomotivePartMedia
    {
        return $this->transition($media, $user, 'archive_media', 'archived', $note,
            fn ($locked) => $locked->forceFill(['is_primary' => false, 'approved_primary_slot' => null])->save());
    }

    public function setPrimary(AutomotivePartMedia $media, User $user): AutomotivePartMedia
    {
        $this->guard->assert('set_primary_media'); $this->configuration->assertEnabled();
        if ($media->status !== 'approved') $this->fail('Solo una imagen aprobada puede ser principal.', 'media_not_approved');
        DB::transaction(function () use ($media, $user) {
            AutomotivePartMedia::query()->where('automotive_part_id', $media->automotive_part_id)
                ->where('status', 'approved')->where('is_primary', true)->update(['is_primary' => false, 'approved_primary_slot' => null]);
            $locked = AutomotivePartMedia::query()->lockForUpdate()->findOrFail($media->id);
            if ($locked->status !== 'approved') $this->fail('Solo una imagen aprobada puede ser principal.', 'media_not_approved');
            $locked->forceFill(['is_primary' => true, 'approved_primary_slot' => $locked->automotive_part_id])->save();
            $this->event($locked, 'primary_selected', 'approved', 'approved', $user);
        });
        $this->staleness->markPart($media->automotive_part_id, 'media_primary_changed', ['media_id' => $media->id]);
        return $media->fresh();
    }

    public function reorder(AutomotivePart $part, array $mediaIds, User $user): void
    {
        $this->guard->assert('reorder_media'); $this->configuration->assertEnabled();
        if (count($mediaIds) !== count(array_unique(array_map('intval', $mediaIds)))) $this->fail('El orden contiene IDs duplicados.', 'invalid_media_order');
        $media = AutomotivePartMedia::query()->where('automotive_part_id', $part->id)->whereIn('id', $mediaIds)->get()->keyBy('id');
        if ($media->count() !== count($mediaIds)) $this->fail('El orden contiene imágenes ajenas o inexistentes.', 'invalid_media_order');
        DB::transaction(function () use ($mediaIds, $media, $user) {
            foreach (array_values($mediaIds) as $position => $id) {
                $item = $media[(int) $id]; $item->forceFill(['position' => $position + 1])->save();
                $this->event($item, 'reordered', $item->status, $item->status, $user, null, ['position' => $position + 1]);
            }
        });
        $this->staleness->markPart($part, 'media_order_changed');
    }

    private function transition(AutomotivePartMedia $media, User $user, string $operation, string $to, ?string $note, callable $after): AutomotivePartMedia
    {
        $this->guard->assert($operation); $this->configuration->assertEnabled();
        $from = null;
        DB::transaction(function () use ($media, $user, $to, $note, $after, &$from, $operation) {
            $locked = AutomotivePartMedia::query()->lockForUpdate()->findOrFail($media->id);
            $from = $locked->status;
            if ($from === 'archived' || $from === $to) $this->fail('La transición de estado no está permitida.', 'invalid_media_transition');
            $locked->forceFill(['status' => $to])->save(); $after($locked);
            $this->event($locked, str_replace('_media', '', $operation), $from, $to, $user, $note);
        });
        if ($to === 'approved' || $from === 'approved') {
            $this->staleness->markPart($media->automotive_part_id, 'media_'.$to, ['media_id' => $media->id]);
        }
        return $media->fresh();
    }

    private function event(AutomotivePartMedia $media, string $action, ?string $from, ?string $to, ?User $user, ?string $notes = null, array $metadata = []): void
    {
        AutomotivePartMediaEvent::query()->create(['automotive_part_media_id' => $media->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'user_id' => $user?->id, 'notes' => $notes,
            'metadata' => $metadata ?: null, 'created_at' => now()]);
    }

    private function hasUnsafeOriginalName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) {
            return true;
        }

        return in_array('..', preg_split('/[\\\\\/]+/', $name) ?: [], true);
    }

    private function fail(string $message, string $code): never { throw new AutomotivePartMediaPricingException($message, $code); }
}
