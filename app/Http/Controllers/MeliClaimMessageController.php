<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeliClaimMessageRequest;
use App\Models\MeliClaim;
use App\Models\MeliClaimActionLog;
use App\Models\MeliClaimAttachmentUpload;
use App\Services\MercadoLibre\Claims\MeliClaimAttachmentUploadException;
use App\Services\MercadoLibre\Claims\MeliClaimMessagePolicy;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use App\Services\MercadoLibre\MeliApiRequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeliClaimMessageController extends Controller
{
    private const DUPLICATE_WINDOW_SECONDS = 15;

    public function store(
        StoreMeliClaimMessageRequest $request,
        MeliClaim $claim,
        MeliClaimMessagePolicy $policy,
        MeliClaimsService $service,
    ): RedirectResponse {
        $account = $request->user()->meliAccounts()->findOrFail($claim->meli_account_id);
        $receiver = $policy->recipient($claim);
        if ($receiver === null) {
            throw ValidationException::withMessages(['message' => 'Actualmente Mercado Libre no permite enviar un mensaje desde este reclamo.']);
        }

        $message = $request->validated('message');
        $files = collect($request->file('attachments', []))->map(function ($file) use ($service): array {
            $hash = hash_file('sha256', $file->getRealPath());
            if ($hash === false) throw ValidationException::withMessages(['attachments' => 'No fue posible leer uno de los archivos.']);
            return ['file' => $file, 'hash' => $hash, 'safe_name' => $service->safeAttachmentFilename($file, $hash)];
        })->values();
        $fileHashes = $files->pluck('hash')->sort()->values()->implode('|');
        // Receiver can legitimately change during the GET refresh after a successful send.
        // The cooldown identifies the user's intent, so an immediate resubmit remains blocked.
        $intent = $claim->id.'|'.$request->user()->id.'|'.$message;
        if ($fileHashes !== '') $intent .= '|'.$fileHashes;
        $hash = hash('sha256', $intent);
        $lock = Cache::lock('meli-claim-message:'.$hash, self::DUPLICATE_WINDOW_SECONDS);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['message' => 'Este mensaje ya se está enviando. Espera antes de intentarlo nuevamente.']);
        }

        try {
            $cooldownKey = 'meli-claim-message-cooldown:'.$hash;
            if (! Cache::add($cooldownKey, true, now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS))) {
                throw ValidationException::withMessages(['message' => 'Este mensaje ya fue procesado recientemente.']);
            }

            if (MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->where('user_id', $request->user()->id)
                ->where('message_hash', $hash)->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))->exists()) {
                throw ValidationException::withMessages(['message' => 'Este mensaje ya fue procesado recientemente.']);
            }

            $audit = MeliClaimActionLog::query()->create([
                'meli_claim_id' => $claim->id,
                'meli_account_id' => $account->id,
                'user_id' => $request->user()->id,
                'action' => 'send_message',
                'receiver_role' => $receiver,
                'request_payload_sanitized' => ['receiver_role' => $receiver, 'message' => $message, 'attachments' => $files->map(fn (array $item) => ['name' => $item['safe_name'], 'hash' => $item['hash']])->all()],
                'message_hash' => $hash,
            ]);

            try {
                $service->ensureFreshToken($account);
                $remoteAttachments = [];
                foreach ($files as $item) {
                    $file = $item['file'];
                    $upload = MeliClaimAttachmentUpload::query()->create([
                        'meli_claim_id' => $claim->id, 'meli_account_id' => $account->id, 'user_id' => $request->user()->id,
                        'original_filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                        'safe_filename' => $item['safe_name'], 'file_hash' => $item['hash'],
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    ]);
                    try {
                        $uploadResponse = $service->uploadAttachment($account, $claim, $file, $item['safe_name']);
                        $remoteFilename = data_get($uploadResponse->json(), 'filename') ?? data_get($uploadResponse->json(), 'file_name');
                        if (! is_string($remoteFilename) || trim($remoteFilename) === '') {
                            $message = 'Mercado Libre respondió correctamente, pero no devolvió un nombre remoto válido.';
                            $upload->forceFill([
                                'remote_status' => $uploadResponse->status(), 'success' => false,
                                'error_code' => 'invalid_remote_response', 'error_message' => $message,
                            ])->save();
                            throw new MeliClaimAttachmentUploadException($uploadResponse->status(), 'invalid_remote_response');
                        }
                        $remoteFilename = trim($remoteFilename);
                        $upload->forceFill(['remote_filename' => $remoteFilename, 'remote_status' => $uploadResponse->status(), 'success' => true])->save();
                        $remoteAttachments[] = $remoteFilename;
                    } catch (Throwable $uploadError) {
                        if ($uploadError instanceof MeliClaimAttachmentUploadException) throw $uploadError;
                        $uploadStatus = $uploadError instanceof MeliApiRequestException ? $uploadError->httpStatus() : 0;
                        $upload->forceFill(['remote_status' => $uploadStatus ?: null, 'success' => false, 'error_code' => $uploadStatus === 0 ? 'uncertain_upload' : 'http_'.$uploadStatus, 'error_message' => $service->safeErrorMessage($uploadError)])->save();
                        throw new MeliClaimAttachmentUploadException($uploadStatus, $uploadStatus === 0 ? 'uncertain_upload' : 'http_'.$uploadStatus, $uploadError);
                    }
                }

                $response = $service->sendMessage($account, $claim, $receiver, $message, $remoteAttachments);
                $responseData = $response->json();
                $audit->forceFill([
                    'remote_response_id' => is_array($responseData) ? data_get($responseData, 'id') : null,
                    'remote_status' => $response->status(),
                    'success' => true,
                ])->save();
            } catch (Throwable $error) {
                $this->recordFailure($audit, $service, $error);
                throw $error;
            }

            try {
                $service->syncClaim($account, $claim->claim_id, true);
            } catch (Throwable $refreshError) {
                Log::warning('MELI CLAIMS: mensaje enviado pero refresh posterior falló', [
                    'meli_claim_id' => $claim->id,
                    'error' => $service->safeErrorMessage($refreshError),
                ]);

                return redirect()->route('meli.claims.show', $claim)
                    ->with('err', 'Mensaje enviado, pero no fue posible actualizar la conversación. Actualiza el reclamo.');
            }

            return redirect()->route('meli.claims.show', $claim)->with('ok', 'Mensaje enviado correctamente.');
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            $status = $error instanceof MeliApiRequestException ? $error->httpStatus() : 0;
            $message = $error instanceof MeliClaimAttachmentUploadException
                ? ($error->remoteStatus === 0
                    ? 'No fue posible confirmar la carga del archivo. El mensaje no fue enviado. No vuelvas a intentarlo inmediatamente.'
                    : 'No fue posible cargar todos los archivos. El mensaje no fue enviado. Uno o más archivos pueden haber quedado cargados temporalmente en Mercado Libre.')
                : ($status === 0
                ? 'No fue posible confirmar si Mercado Libre recibió el mensaje. Actualiza el reclamo antes de intentar nuevamente.'
                : match ($status) {
                    400 => 'Mercado Libre rechazó el mensaje por considerarlo inválido.',
                    401 => 'Mercado Libre rechazó la sesión. El mensaje no se reintentó.',
                    403 => 'Mercado Libre no permite enviar este mensaje.',
                    404 => 'El reclamo ya no está disponible en Mercado Libre.',
                    429 => 'Mercado Libre limitó temporalmente las solicitudes. El mensaje no se reintentó.',
                    default => 'No fue posible enviar el mensaje. El envío no se reintentó automáticamente.',
                });

            return redirect()->route('meli.claims.show', $claim)->with('err', $message);
        } finally {
            $lock->release();
        }
    }

    private function recordFailure(MeliClaimActionLog $audit, MeliClaimsService $service, Throwable $error): void
    {
        $status = $error instanceof MeliApiRequestException ? $error->httpStatus()
            : ($error instanceof MeliClaimAttachmentUploadException ? $error->remoteStatus : 0);
        $audit->forceFill([
            'remote_status' => $status ?: null,
            'success' => false,
            'error_code' => $error instanceof MeliClaimAttachmentUploadException
                ? $error->errorCode
                : ($status === 0 ? 'uncertain_delivery' : 'http_'.$status),
            'error_message' => $service->safeErrorMessage($error),
        ])->save();
    }
}
