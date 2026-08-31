<?php

namespace App\Http\Controllers;

use App\Models\MeliClaim;
use App\Models\MeliClaimActionLog;
use App\Models\MeliAccount;
use App\Services\MercadoLibre\Claims\MeliClaimResolutionPolicy;
use App\Services\MercadoLibre\Claims\MeliClaimResolutionService;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use App\Services\MercadoLibre\MeliApiRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeliClaimResolutionController extends Controller
{
    private const COOLDOWN_SECONDS = 60;

    public function refund(Request $request, MeliClaim $claim, MeliClaimResolutionPolicy $policy, MeliClaimResolutionService $resolutions, MeliClaimsService $claims): RedirectResponse
    {
        $account = $this->accountFor($request, $claim);
        $request->validate(['confirmed' => ['required', 'accepted']]);

        return $this->resolve($request, $claim, $account, 'refund', $policy, $resolutions, $claims);
    }

    public function allowReturn(Request $request, MeliClaim $claim, MeliClaimResolutionPolicy $policy, MeliClaimResolutionService $resolutions, MeliClaimsService $claims): RedirectResponse
    {
        $account = $this->accountFor($request, $claim);
        $request->validate(['confirmed' => ['required', 'accepted']]);

        return $this->resolve($request, $claim, $account, 'allow_return', $policy, $resolutions, $claims);
    }

    public function partialOffers(Request $request, MeliClaim $claim, MeliClaimResolutionPolicy $policy, MeliClaimResolutionService $resolutions): JsonResponse
    {
        $account = $this->accountFor($request, $claim);
        $resolutions->ensureFreshToken($account);
        $raw = $resolutions->preflight($account, $claim);
        $resolutions->persistPreflight($claim, $raw);
        abort_unless($policy->allows($raw, 'partial_refund'), 409, 'Mercado Libre ya no permite realizar esta acción. El reclamo fue actualizado.');

        return response()->json(['offers' => $resolutions->partialRefundOffers($account, $claim)]);
    }

    public function partialRefund(Request $request, MeliClaim $claim, MeliClaimResolutionPolicy $policy, MeliClaimResolutionService $resolutions, MeliClaimsService $claims): RedirectResponse
    {
        $account = $this->accountFor($request, $claim);
        $validated = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'percentage' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'amount' => ['prohibited'],
            'currency_id' => ['prohibited'],
        ]);

        return $this->resolve($request, $claim, $account, 'partial_refund', $policy, $resolutions, $claims, (float) $validated['percentage']);
    }

    private function resolve(Request $request, MeliClaim $claim, MeliAccount $account, string $action, MeliClaimResolutionPolicy $policy, MeliClaimResolutionService $resolutions, MeliClaimsService $claims, ?float $percentage = null): RedirectResponse
    {
        $intent = implode('|', [$claim->id, $request->user()->id, $action, $percentage ?? '']);
        $hash = hash('sha256', $intent);
        // One claim-level lock also prevents two different economic actions from racing.
        $lock = Cache::lock('meli-claim-resolution:'.$claim->id, self::COOLDOWN_SECONDS);
        if (! $lock->get()) return back()->with('err', 'Esta resolución ya se está procesando.');

        try {
            if ($this->wasRecentlyAttempted($claim, $request->user()->id, $action, $hash)) {
                return back()->with('err', 'Esta resolución ya fue procesada recientemente. Actualiza el reclamo antes de volver a intentarlo.');
            }

            $resolutions->ensureFreshToken($account);
            $raw = $resolutions->preflight($account, $claim);
            $resolutions->persistPreflight($claim, $raw);
            if (! $policy->allows($raw, $action)) {
                return back()->with('err', 'Mercado Libre ya no permite realizar esta acción. El reclamo fue actualizado.');
            }

            $offer = null;
            if ($action === 'partial_refund') {
                $offer = collect($resolutions->partialRefundOffers($account, $claim))
                    ->first(fn (array $candidate): bool => (float) $candidate['percentage'] === $percentage);
                if ($offer === null || $percentage >= 100) {
                    throw ValidationException::withMessages(['percentage' => 'Selecciona una oferta de reembolso vigente de Mercado Libre.']);
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
                'user_id' => $request->user()->id,
                'action' => $action,
                'request_payload_sanitized' => $payload,
                'message_hash' => $hash,
            ]);

            Cache::put('meli-claim-resolution-cooldown:'.$hash, true, now()->addSeconds(self::COOLDOWN_SECONDS));
            $resolutions->ensureFreshToken($account);
            try {
                $response = $resolutions->execute($account, $claim, $action, $offer['percentage'] ?? null);
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
                    'error_message' => $claims->safeErrorMessage($error),
                ])->save();
                report($error);

                return back()->with('err', $status === 0
                    ? 'No fue posible confirmar si Mercado Libre procesó la resolución. Actualiza el reclamo antes de intentar cualquier otra acción.'
                    : 'Mercado Libre no procesó la resolución. No se realizó ningún reintento automático.');
            }

            try {
                $claims->syncClaim($account, $claim->claim_id, true);
            } catch (Throwable $error) {
                Log::warning('MELI CLAIMS: resolución enviada pero refresh posterior falló', [
                    'meli_claim_id' => $claim->id,
                    'action' => $action,
                    'error' => $claims->safeErrorMessage($error),
                ]);

                return redirect()->route('meli.claims.show', $claim)
                    ->with('err', 'La resolución fue enviada correctamente, pero no fue posible actualizar el reclamo. Actualízalo manualmente.');
            }

            return redirect()->route('meli.claims.show', $claim)->with('ok', 'Resolución enviada correctamente.');
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return back()->with('err', 'No fue posible verificar la resolución con Mercado Libre. No se realizó ninguna acción económica.');
        } finally {
            $lock->release();
        }
    }

    private function wasRecentlyAttempted(MeliClaim $claim, int $userId, string $action, string $hash): bool
    {
        return Cache::has('meli-claim-resolution-cooldown:'.$hash)
            || MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)
                ->where('user_id', $userId)->where('action', $action)->where('message_hash', $hash)
                ->where('created_at', '>=', now()->subSeconds(self::COOLDOWN_SECONDS))->exists();
    }

    private function accountFor(Request $request, MeliClaim $claim): MeliAccount
    {
        return $request->user()->meliAccounts()->findOrFail($claim->meli_account_id);
    }
}
