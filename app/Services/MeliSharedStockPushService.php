<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MeliSharedStockPushService
{
    public function __construct(private readonly MeliOAuthService $oauth)
    {
    }

    /** @return array<string, int> */
    public function pushGroup(MeliSharedStockGroup|int $group): array
    {
        $group = $group instanceof MeliSharedStockGroup
            ? $group
            : MeliSharedStockGroup::query()->findOrFail($group);

        $group->load(['members' => fn ($query) => $query->where('is_active', true)]);

        if (! $group->is_enabled) {
            return ['updated' => 0, 'skipped' => $group->members->count(), 'errors' => 0];
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $lastError = null;

        foreach ($group->members as $member) {
            try {
                $result = $this->pushMember($member, max(0, (int) $group->stock));
                if ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $errors++;
                $lastError = $exception->getMessage();

                $member->forceFill([
                    'last_push_at' => now(),
                    'last_push_status' => 'error',
                    'last_error' => $exception->getMessage(),
                ])->save();

                Log::warning('MELI SHARED STOCK: error al actualizar miembro', [
                    'group_id' => $group->id,
                    'member_id' => $member->id,
                    'account_id' => $member->meli_account_id,
                    'mlm' => $member->mlm,
                    'variation_id' => $member->variation_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $group->forceFill([
            'last_pushed_at' => now(),
            'last_error' => $lastError,
        ])->save();

        return compact('updated', 'skipped', 'errors');
    }

    private function pushMember(MeliSharedStockMember $member, int $stock): string
    {
        $publication = MeliPublication::query()
            ->with('meliAccount')
            ->whereKey($member->meli_publication_id)
            ->first();

        if (! $publication || ! $publication->meliAccount) {
            throw new RuntimeException('La publicación o la cuenta vinculada ya no existe.');
        }

        if (! in_array(strtolower((string) $publication->status), ['active', 'paused'], true)) {
            $this->markSkipped($member, 'Estado no editable: '.($publication->status ?: 'sin estado'));

            return 'skipped';
        }

        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $logisticType = strtolower(trim((string) data_get($item, 'shipping.logistic_type', '')));
        if ($member->is_fulfillment || $logisticType === 'fulfillment') {
            $this->markSkipped($member, 'FULL es administrado por Mercado Libre.');

            return 'skipped';
        }

        $account = $publication->meliAccount;
        $this->ensureFreshAccessToken($account);

        $payload = $member->variation_id
            ? ['variations' => [[
                'id' => is_numeric($member->variation_id) ? (int) $member->variation_id : $member->variation_id,
                'available_quantity' => $stock,
            ]]]
            : ['available_quantity' => $stock];

        $response = $this->request($account, 'put', '/items/'.$member->mlm, $payload);
        $responseItem = $response->json();

        if (is_array($responseItem) && ! empty($responseItem['id'])) {
            $this->savePublicationSnapshot($publication, $responseItem);
        } else {
            // Algunos PUT responden solo confirmación; refrescamos para no dejar el panel atrasado.
            $fresh = $this->request($account, 'get', '/items/'.$member->mlm)->json();
            if (is_array($fresh)) {
                $this->savePublicationSnapshot($publication, $fresh);
            }
        }

        $member->forceFill([
            'last_push_at' => now(),
            'last_push_status' => 'updated',
            'last_error' => null,
        ])->save();

        return 'updated';
    }

    private function markSkipped(MeliSharedStockMember $member, string $reason): void
    {
        $member->forceFill([
            'last_push_at' => now(),
            'last_push_status' => 'skipped',
            'last_error' => $reason,
        ])->save();
    }

    private function savePublicationSnapshot(MeliPublication $publication, array $item): void
    {
        $oldRaw = is_array($publication->raw) ? $publication->raw : [];
        $metadata = [];

        foreach (['metrics', 'moderations', 'visits', 'conversion', 'account_catalog_sync'] as $key) {
            if (array_key_exists($key, $oldRaw)) {
                $metadata[$key] = $oldRaw[$key];
            }
        }

        $publication->forceFill([
            'status' => strtolower(trim((string) ($item['status'] ?? $publication->status))),
            'sub_status' => array_values((array) ($item['sub_status'] ?? $publication->sub_status ?? [])),
            'permalink' => $item['permalink'] ?? $publication->permalink,
            'category_id' => $item['category_id'] ?? $publication->category_id,
            'pictures' => $item['pictures'] ?? $publication->pictures,
            'raw' => ['item' => $item, ...$metadata],
            'last_sync_at' => now(),
        ])->save();
    }

    private function ensureFreshAccessToken(MeliAccount $account, bool $force = false): void
    {
        $usable = filled($account->access_token)
            && ($account->expires_at === null || $account->expires_at->greaterThan(now()->addMinutes(5)));

        if (! $force && $usable) {
            return;
        }

        if (! filled($account->refresh_token)) {
            if (filled($account->access_token)) {
                return;
            }

            throw new RuntimeException('La cuenta no tiene access_token ni refresh_token.');
        }

        $clientId = (string) config('services.meli.client_id', config('services.meli.app_id', ''));
        $clientSecret = (string) config('services.meli.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Faltan MELI_CLIENT_ID/MELI_APP_ID o MELI_CLIENT_SECRET.');
        }

        $data = $this->oauth->refreshAccessToken(
            $clientId,
            $clientSecret,
            (string) $account->refresh_token,
        );

        $account->forceFill([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 21600))->subMinutes(2),
        ])->save();

        $account->refresh();
        $account->user?->syncMeliColumnsFromDefaultAccount();
    }

    /** @param array<string, mixed> $payload */
    private function request(MeliAccount $account, string $method, string $path, array $payload = []): Response
    {
        $lastResponse = null;

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $client = Http::withToken((string) $account->access_token)
                ->acceptJson()
                ->timeout(45);

            $url = 'https://api.mercadolibre.com'.$path;
            $response = match (strtolower($method)) {
                'post' => $client->post($url, $payload),
                'put' => $client->put($url, $payload),
                'delete' => $client->delete($url, $payload),
                default => $client->get($url, $payload),
            };
            $lastResponse = $response;

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 401 && $attempt === 1) {
                $this->ensureFreshAccessToken($account, true);
                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                sleep(min(8, 2 ** ($attempt - 1)));
                continue;
            }

            break;
        }

        $status = $lastResponse?->status() ?? 0;
        $json = $lastResponse?->json();
        $message = (string) (
            data_get($json, 'cause.0.message')
            ?? data_get($json, 'message')
            ?? $lastResponse?->body()
            ?? 'Sin respuesta'
        );

        throw new RuntimeException("Mercado Libre HTTP {$status}: {$message}");
    }
}
