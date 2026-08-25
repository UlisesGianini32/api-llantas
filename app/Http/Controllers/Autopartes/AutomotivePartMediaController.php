<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMedia;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AutomotivePartMediaController extends Controller
{
    public function index(Request $request, AutomotivePartMediaPricingConfiguration $configuration): Response
    {
        $search = trim((string) $request->query('q'));
        $parts = AutomotivePart::query()->withCount([
            'media', 'media as approved_media_count' => fn ($q) => $q->where('status', 'approved'),
            'meliDrafts as stale_drafts_count' => fn ($q) => $q->where('status', 'stale'),
        ])->when($search !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('item_number', 'like', "%{$search}%")
            ->orWhere('manufacturer_part_number', 'like', "%{$search}%")->orWhere('description_original', 'like', "%{$search}%")))
            ->orderBy('id')->paginate(25)->withQueryString();
        return Inertia::render('Autopartes/Medios', ['parts' => $parts, 'filters' => ['q' => $search],
            'settings' => ['enabled' => $configuration->enabled(), 'max_images' => $configuration->maxImages()]]);
    }

    public function show(AutomotivePart $automotivePart, AutomotivePartMediaPricingConfiguration $configuration): Response
    {
        $automotivePart->load(['media' => fn ($q) => $q->with(['uploader:id,name', 'approver:id,name', 'rejecter:id,name', 'events.user:id,name'])->orderBy('position')->orderBy('id')]);
        return Inertia::render('Autopartes/MediosDetalle', ['part' => $automotivePart,
            'settings' => ['enabled' => $configuration->enabled(), 'max_images' => $configuration->maxImages(),
                'provenance_types' => AutomotivePartMedia::PROVENANCE_TYPES]]);
    }

    public function preview(AutomotivePartMedia $media): StreamedResponse
    {
        abort_unless(in_array($media->detected_mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $expectedPath = 'autopartes/media/'.$media->automotive_part_id.'/'.$media->stored_name;
        abort_unless($media->stored_name === basename($media->stored_name) && hash_equals($expectedPath, $media->path), 404);
        abort_unless(is_array(config('filesystems.disks.'.$media->disk)), 404);
        $disk = Storage::disk($media->disk); abort_unless($disk->exists($media->path), 404);
        return response()->stream(function () use ($disk, $media) {
            $stream = $disk->readStream($media->path); if (! is_resource($stream)) return;
            fpassthru($stream); fclose($stream);
        }, 200, ['Content-Type' => $media->detected_mime, 'Content-Length' => (string) $media->size_bytes,
            'Content-Disposition' => 'inline; filename="'.$media->stored_name.'"', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store']);
    }
}
