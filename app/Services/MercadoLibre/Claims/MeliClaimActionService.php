<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliAccount;
use App\Models\MeliClaim;
use App\Models\MeliClaimActionLog;
use App\Models\User;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Support\UserAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeliClaimActionService
{
    private const COOLDOWN_SECONDS = 60;

    private const ECONOMIC_ACTIONS = ['refund', 'allow_return', 'partial_refund'];

    public function __construct(
        private MeliClaimResolutionPolicy $policy,
        private MeliClaimResolutionService $resolutions,
        private MeliClaimsService $claims,
    ) {}

    /** @return array{ok:bool,code:string,message:string,offers:list<array<string,mixed>>} */
    public function prepare(User $actor, MeliClaim $claim, string $action): array
    {
        $account = $this->accountFor($actor, $claim);
        $this->assertSupported($action);
        $this->resolutions->ensureFreshToken($account);
        $raw = $this->resolutions->preflight($account, $claim);
        $this->resolutions->persistPreflight($claim, $raw);

        if ($interlock = $this->reconcileUncertainDelivery($claim, $raw)) {
            return $interlock;
        }

        if (! in_array((string) ($raw['status'] ?? ''), ['open', 'opened'], true)) {
            return $this->failure('closed', 'El reclamo cambió de estado y ya no admite esta acción.');
        }
        if (! $this->policy->allows($raw, $action)) {
            return $this->failure('unavailable', 'Mercado Libre ya no permite realizar esta acción. El reclamo fue actualizado.');
        }

        $offers = $action === 'partial_refund'
            ? $this->resolutions->partialRefundOffers($account, $claim)
            : [];

        return ['ok' => true, 'code' => 'ready', 'message' => '', 'offers' => $offers];
    }

    /**
     * @param  array{percentage?:float|int,amount?:float|int|null,currency_id?:string|null}|null  $expectedOffer
     * @return array{ok:bool,code:string,message:string,refresh_failed:bool}
     */
    public function execute(User $actor, MeliClaim $claim, string $action, ?float $percentage = null, string $source = 'web', ?array $expectedOffer = null, ?string $telegramChatId = null): array
    {
        $account = $this->accountFor($actor, $claim);
        $this->assertSupported($action);
        if (! in_array($source, ['web', 'telegram'], true)) {
            throw new \InvalidArgumentException('Origen de acción no soportado.');
        }

        $hash = hash('sha256', implode('|', [$claim->id, $actor->id, $action, $percentage ?? '']));
        $lock = Cache::lock('meli-claim-resolution:'.$claim->id, self::COOLDOWN_SECONDS);
        if (! $lock->get()) {
            return $this->failure('processing', 'Esta resolución ya se está procesando.');
        }

        try {
            if ($this->wasRecentlyAttempted($claim, $actor->id, $action, $hash)) {
                return $this->failure('duplicate', 'Esta resolución ya fue procesada recientemente. Actualiza el reclamo antes de volver a intentarlo.');
            }

            try {
                $prepared = $this->prepare($actor, $claim, $action);
            } catch (Throwable $error) {
                report($error);

                return $this->failure('verification_failed', 'No fue posible verificar la resolución con Mercado Libre. No se realizó ninguna acción económica.');
            }
            if (! $prepared['ok']) {
                return $prepared + ['refresh_failed' => false];
            }

            $offer = null;
            if ($action === 'partial_refund') {
                $offer = collect($prepared['offers'])->first(
                    fn (array $candidate): bool => (float) $candidate['percentage'] === (float) $percentage
                );
                if ($offer === null || $percentage === null || $percentage <= 0 || $percentage >= 100) {
                    throw ValidationException::withMessages(['percentage' => 'Selecciona una oferta de reembolso vigente de Mercado Libre.']);
                }
                if ($expectedOffer !== null && ! $this->sameOffer($offer, $expectedOffer)) {
                    return $this->failure('invalid_amount', 'El importe indicado ya no es válido en Mercado Libre.');
                }
            }

            $payload = $offer === null ? [] : [
                'percentage' => $offer['percentage'],
                'amount' => $offer['amount'],
                'currency_id' => $offer['currency_id'],
            ];
            $audit = MeliClaimActionLog::query()->create([
                'meli_claim_id' => $claim->id,
                'meli_account_id' => $account->id,
                'user_id' => $actor->id,
                'source' => $source,
                'telegram_chat_id' => $this->auditChatId($source, $telegramChatId),
                'action' => $action,
                'request_payload_sanitized' => $payload,
                'message_hash' => $hash,
            ]);

            Cache::put('meli-claim-resolution-cooldown:'.$hash, true, now()->addSeconds(self::COOLDOWN_SECONDS));
            try {
                $this->resolutions->ensureFreshToken($account);
                $response = $this->resolutions->execute($account, $claim, $action, $offer['percentage'] ?? null);
                $audit->forceFill([
                    'remote_status' => $response->status(),
                    'remote_response_id' => data_get($response->json(), 'id'),
                    'success' => true,
                ])->save();
            } catch (Throwable $error) {
                $status = $error instanceof MeliApiRequestException ? $error->httpStatus() : 0;
                $audit->forceFill([
                    'remote_status' => $status ?: null,
                    'success' => $status === 0 ? null : false,
                    'error_code' => $status === 0 ? 'uncertain_delivery' : 'http_'.$status,
                    'error_message' => $this->claims->safeErrorMessage($error),
                ])->save();
                report($error);

                return $this->failure(
                    $status === 0 ? 'uncertain_delivery' : 'remote_rejected',
                    $status === 0
                        ? 'No fue posible confirmar si Mercado Libre procesó la resolución. Actualiza el reclamo antes de intentar cualquier otra acción.'
                        : 'Mercado Libre no procesó la resolución. No se realizó ningún reintento automático.'
                );
            }

            try {
                $this->claims->syncClaim($account, $claim->claim_id, true);
            } catch (Throwable $error) {
                Log::warning('MELI CLAIMS: resolución enviada pero refresh posterior falló', [
                    'meli_claim_id' => $claim->id,
                    'action' => $action,
                    'error' => $this->claims->safeErrorMessage($error),
                ]);

                return [
                    'ok' => true,
                    'code' => 'refresh_failed',
                    'message' => 'La resolución fue enviada correctamente, pero no fue posible actualizar el reclamo. Actualízalo manualmente.',
                    'refresh_failed' => true,
                ];
            }

            return ['ok' => true, 'code' => 'completed', 'message' => 'Resolución enviada correctamente.', 'refresh_failed' => false];
        } finally {
            $lock->release();
        }
    }

    public function canAct(User $actor, MeliClaim $claim): bool
    {
        return UserAccess::canAccessRoute($actor, 'meli.claims.resolutions.refund')
            && $actor->meliAccounts()->whereKey($claim->meli_account_id)->exists();
    }

    private function accountFor(User $actor, MeliClaim $claim): MeliAccount
    {
        if (! $this->canAct($actor, $claim)) {
            throw new AuthorizationException('El operador no tiene permiso para resolver este reclamo.');
        }

        return $actor->meliAccounts()->findOrFail($claim->meli_account_id);
    }

    private function assertSupported(string $action): void
    {
        if (! in_array($action, self::ECONOMIC_ACTIONS, true)) {
            throw new \InvalidArgumentException('Resolución económica no soportada.');
        }
    }

    private function wasRecentlyAttempted(MeliClaim $claim, int $userId, string $action, string $hash): bool
    {
        return Cache::has('meli-claim-resolution-cooldown:'.$hash)
            || MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)
                ->where('user_id', $userId)->where('action', $action)->where('message_hash', $hash)
                ->where('created_at', '>=', now()->subSeconds(self::COOLDOWN_SECONDS))->exists();
    }

    /** @return array{ok:false,code:string,message:string,offers:array{},refresh_failed:false}|null */
    private function reconcileUncertainDelivery(MeliClaim $claim, array $raw): ?array
    {
        $pending = MeliClaimActionLog::query()
            ->where('meli_claim_id', $claim->id)
            ->whereNull('success')
            ->where('error_code', 'uncertain_delivery')
            ->whereNull('reconciled_at')
            ->oldest('id')
            ->get();

        foreach ($pending as $audit) {
            if (! in_array((string) ($raw['status'] ?? ''), ['open', 'opened'], true)) {
                $audit->forceFill(['reconciliation_result' => 'claim_not_open'])->save();

                return $this->uncertainDeliveryFailure();
            }

            if (! $this->policy->allows($raw, $audit->action)) {
                $audit->forceFill(['reconciliation_result' => 'action_not_available'])->save();

                return $this->uncertainDeliveryFailure();
            }

            try {
                $remoteUpdatedAt = filled($raw['last_updated'] ?? null)
                    ? CarbonImmutable::parse((string) $raw['last_updated'])
                    : null;
            } catch (Throwable) {
                $remoteUpdatedAt = null;
            }

            if ($remoteUpdatedAt === null || $audit->created_at === null || ! $remoteUpdatedAt->isAfter($audit->created_at)) {
                $audit->forceFill(['reconciliation_result' => 'snapshot_not_newer'])->save();

                return $this->uncertainDeliveryFailure();
            }

            $audit->forceFill([
                'reconciled_at' => now(),
                'reconciliation_result' => 'action_still_available',
            ])->save();
        }

        return null;
    }

    /** @return array{ok:false,code:string,message:string,offers:array{},refresh_failed:false} */
    private function uncertainDeliveryFailure(): array
    {
        return $this->failure(
            'uncertain_delivery_pending_review',
            'Existe una resolución con resultado incierto. Requiere revisión antes de intentar otra acción económica.'
        );
    }

    private function auditChatId(string $source, ?string $chatId): ?string
    {
        if ($source !== 'telegram' || $chatId === null || ! preg_match('/^-?\d{1,20}$/', $chatId)) {
            return null;
        }

        return $chatId;
    }

    private function sameOffer(array $current, array $expected): bool
    {
        return (float) ($current['percentage'] ?? -1) === (float) ($expected['percentage'] ?? -2)
            && $this->minorUnits($current['amount'] ?? null) === $this->minorUnits($expected['amount'] ?? null)
            && (string) ($current['currency_id'] ?? '') === (string) ($expected['currency_id'] ?? '');
    }

    private function minorUnits(mixed $amount): ?int
    {
        return is_numeric($amount) ? (int) round((float) $amount * 100) : null;
    }

    /** @return array{ok:false,code:string,message:string,offers:array{},refresh_failed:false} */
    private function failure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'offers' => [], 'refresh_failed' => false];
    }
}
