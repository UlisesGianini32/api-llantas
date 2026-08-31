<?php

namespace App\Http\Controllers;

use App\Models\MeliClaim;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MeliClaimAttachmentController extends Controller
{
    public function download(Request $request, MeliClaim $claim, string $attachment, MeliClaimsService $service): Response
    {
        $account = $request->user()->meliAccounts()->findOrFail($claim->meli_account_id);
        abort_unless((bool) preg_match('/\A[A-Za-z0-9._-]{1,255}\z/', $attachment), 404);
        $metadata = collect($claim->messages ?? [])->flatMap(fn (mixed $message) => is_array($message) ? (array) ($message['attachments'] ?? []) : [])
            ->first(fn (mixed $file) => is_array($file) && in_array($attachment, [$file['filename'] ?? null, $file['file_name'] ?? null], true));
        abort_unless(is_array($metadata), 404);

        $remote = $service->downloadAttachment($account, $claim, $attachment);
        $mime = in_array($remote->header('Content-Type'), ['image/jpeg', 'image/png', 'application/pdf'], true)
            ? $remote->header('Content-Type') : 'application/octet-stream';
        $downloadName = Str::ascii((string) ($metadata['original_filename'] ?? $metadata['filename'] ?? 'adjunto'));
        $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $downloadName))) ?: 'adjunto';

        return response($remote->body(), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($downloadName, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
