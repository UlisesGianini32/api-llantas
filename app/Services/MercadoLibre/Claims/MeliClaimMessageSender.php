<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliClaim;
use App\Models\MeliClaimActionLog;
use App\Models\MeliClaimAttachmentUpload;
use App\Models\User;
use App\Services\MercadoLibre\MeliApiRequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeliClaimMessageSender
{
    private const DUPLICATE_WINDOW_SECONDS = 15;

    public function __construct(
        private MeliClaimMessagePolicy $policy,
        private MeliClaimsService $claims,
    ) {}

    /** @param Collection<int, mixed>|null $attachments */
    public function send(User $actor, MeliClaim $claim, string $message, ?Collection $attachments = null, string $source = 'web'): array
    {
        $claim->refresh();
        $account = $actor->meliAccounts()->findOrFail($claim->meli_account_id);
        $receiver = $this->policy->recipient($claim);
        if ($receiver === null) {
            throw ValidationException::withMessages(['message' => 'Actualmente Mercado Libre no permite enviar un mensaje desde este reclamo.']);
        }

        $files = ($attachments ?? collect())->map(function ($file): array {
            $hash = hash_file('sha256', $file->getRealPath());
            if ($hash === false) {
                throw ValidationException::withMessages(['attachments' => 'No fue posible leer uno de los archivos.']);
            }

            return ['file' => $file, 'hash' => $hash, 'safe_name' => $this->claims->safeAttachmentFilename($file, $hash)];
        })->values();
        $fileHashes = $files->pluck('hash')->sort()->values()->implode('|');
        $intent = $claim->id.'|'.$actor->id.'|'.$message.($fileHashes !== '' ? '|'.$fileHashes : '');
        $hash = hash('sha256', $intent);
        $lock = Cache::lock('meli-claim-message:'.$hash, self::DUPLICATE_WINDOW_SECONDS);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['message' => 'Este mensaje ya se está enviando. Espera antes de intentarlo nuevamente.']);
        }

        try {
            if (! Cache::add('meli-claim-message-cooldown:'.$hash, true, now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS))) {
                throw ValidationException::withMessages(['message' => 'Este mensaje ya fue procesado recientemente.']);
            }
            if (MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->where('user_id', $actor->id)
                ->where('message_hash', $hash)->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))->exists()) {
                throw ValidationException::withMessages(['message' => 'Este mensaje ya fue procesado recientemente.']);
            }

            $audit = MeliClaimActionLog::query()->create([
                'meli_claim_id' => $claim->id,
                'meli_account_id' => $account->id,
                'user_id' => $actor->id,
                'action' => 'send_message',
                'receiver_role' => $receiver,
                'request_payload_sanitized' => [
                    'source' => $source,
                    'receiver_role' => $receiver,
                    'message' => $message,
                    'attachments' => $files->map(fn (array $item): array => ['name' => $item['safe_name'], 'hash' => $item['hash']])->all(),
                ],
                'message_hash' => $hash,
            ]);

            try {
                $this->claims->ensureFreshToken($account);
                $remoteAttachments = [];
                foreach ($files as $item) {
                    $file = $item['file'];
                    $upload = MeliClaimAttachmentUpload::query()->create([
                        'meli_claim_id' => $claim->id, 'meli_account_id' => $account->id, 'user_id' => $actor->id,
                        'original_filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                        'safe_filename' => $item['safe_name'], 'file_hash' => $item['hash'],
                        'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    ]);
                    try {
                        $response = $this->claims->uploadAttachment($account, $claim, $file, $item['safe_name']);
                        $remoteFilename = data_get($response->json(), 'filename') ?? data_get($response->json(), 'file_name');
                        if (! is_string($remoteFilename) || trim($remoteFilename) === '') {
                            $upload->forceFill(['remote_status' => $response->status(), 'success' => false, 'error_code' => 'invalid_remote_response', 'error_message' => 'Mercado Libre no devolvió un nombre remoto válido.'])->save();
                            throw new MeliClaimAttachmentUploadException($response->status(), 'invalid_remote_response');
                        }
                        $remoteFilename = trim($remoteFilename);
                        $upload->forceFill(['remote_filename' => $remoteFilename, 'remote_status' => $response->status(), 'success' => true])->save();
                        $remoteAttachments[] = $remoteFilename;
                    } catch (Throwable $error) {
                        if ($error instanceof MeliClaimAttachmentUploadException) {
                            throw $error;
                        }
                        $status = $error instanceof MeliApiRequestException ? $error->httpStatus() : 0;
                        $upload->forceFill(['remote_status' => $status ?: null, 'success' => false, 'error_code' => $status === 0 ? 'uncertain_upload' : 'http_'.$status, 'error_message' => $this->claims->safeErrorMessage($error)])->save();
                        throw new MeliClaimAttachmentUploadException($status, $status === 0 ? 'uncertain_upload' : 'http_'.$status, $error);
                    }
                }

                $response = $this->claims->sendMessage($account, $claim, $receiver, $message, $remoteAttachments);
                $responseData = $response->json();
                $audit->forceFill(['remote_response_id' => is_array($responseData) ? data_get($responseData, 'id') : null, 'remote_status' => $response->status(), 'success' => true])->save();
            } catch (Throwable $error) {
                $this->recordFailure($audit, $error);
                throw $error;
            }

            try {
                $this->claims->syncClaim($account, $claim->claim_id, true);
            } catch (Throwable $error) {
                Log::warning('MELI CLAIMS: mensaje enviado pero refresh posterior falló', ['meli_claim_id' => $claim->id, 'error' => $this->claims->safeErrorMessage($error)]);

                return ['ok' => true, 'refresh_failed' => true, 'error' => null];
            }

            return ['ok' => true, 'refresh_failed' => false, 'error' => null];
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return ['ok' => false, 'refresh_failed' => false, 'error' => $this->publicError($error)];
        } finally {
            $lock->release();
        }
    }

    private function recordFailure(MeliClaimActionLog $audit, Throwable $error): void
    {
        $status = $error instanceof MeliApiRequestException ? $error->httpStatus() : ($error instanceof MeliClaimAttachmentUploadException ? $error->remoteStatus : 0);
        $audit->forceFill([
            'remote_status' => $status ?: null,
            'success' => false,
            'error_code' => $error instanceof MeliClaimAttachmentUploadException ? $error->errorCode : ($status === 0 ? 'uncertain_delivery' : 'http_'.$status),
            'error_message' => $this->claims->safeErrorMessage($error),
        ])->save();
    }

    private function publicError(Throwable $error): string
    {
        if ($error instanceof MeliClaimAttachmentUploadException) {
            return $error->remoteStatus === 0
                ? 'No fue posible confirmar la carga del archivo. El mensaje no fue enviado. No vuelvas a intentarlo inmediatamente.'
                : 'No fue posible cargar todos los archivos. El mensaje no fue enviado. Uno o más archivos pueden haber quedado cargados temporalmente en Mercado Libre.';
        }
        $status = $error instanceof MeliApiRequestException ? $error->httpStatus() : 0;
        if ($status === 0) {
            return 'No fue posible confirmar si Mercado Libre recibió el mensaje. Actualiza el reclamo antes de intentar nuevamente.';
        }

        return match ($status) {
            400 => 'Mercado Libre rechazó el mensaje por considerarlo inválido.',
            401 => 'Mercado Libre rechazó la sesión. El mensaje no se reintentó.',
            403 => 'Mercado Libre no permite enviar este mensaje.',
            404 => 'El reclamo ya no está disponible en Mercado Libre.',
            429 => 'Mercado Libre limitó temporalmente las solicitudes. El mensaje no se reintentó.',
            default => 'No fue posible enviar el mensaje. El envío no se reintentó automáticamente.',
        };
    }
}
