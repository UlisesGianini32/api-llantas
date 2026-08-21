<?php

namespace App\Services;

use App\Jobs\PushMeliSharedStockGroupJob;
use App\Models\MeliAccount;
use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use App\Models\MeliSharedStockMovement;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MeliSharedStockMasterReconcileService
{
    public function __construct(private readonly MeliOAuthService $oauth)
    {
    }

    /** @return array<string,int> */
    public function reconcile(int $masterAccountId, bool $push = false): array
    {
        $account = MeliAccount::query()->findOrFail($masterAccountId);
        $this->ensureFreshAccessToken($account);

        $groups = MeliSharedStockGroup::query()
            ->where('master_account_id', $masterAccountId)
            ->where('is_enabled', true)
            ->with(['members' => fn ($query) => $query->where('role', 'master')->where('is_active', true)])
            ->get();

        $canonical = collect();
        foreach ($groups as $group) {
            $member = $group->members->first(function (MeliSharedStockMember $member) use ($group): bool {
                return strtoupper($member->mlm) === strtoupper((string) $group->master_mlm)
                    && (string) ($member->variation_id ?? '') === (string) ($group->master_variation_id ?? '');
            }) ?? $group->members->first();

            if ($member) {
                $canonical->put($group->id, $member);
            }
        }

        $itemsByMlm = [];
        foreach ($canonical->pluck('mlm')->map(fn ($mlm) => strtoupper((string) $mlm))->unique()->chunk(20) as $chunk) {
            $response = $this->request($account, 'get', '/items', ['ids' => $chunk->implode(',')]);
            foreach ((array) $response->json() as $entry) {
                if ((int) ($entry['code'] ?? 0) !== 200 || ! is_array($entry['body'] ?? null)) {
                    continue;
                }

                $item = $entry['body'];
                $itemsByMlm[strtoupper((string) ($item['id'] ?? ''))] = $item;
            }
        }

        $checked = $changed = $errors = 0;
        $changedIds = [];

        foreach ($groups as $group) {
            /** @var MeliSharedStockMember|null $member */
            $member = $canonical->get($group->id);
            if (! $member) {
                $errors++;
                continue;
            }

            $item = $itemsByMlm[strtoupper($member->mlm)] ?? null;
            if (! is_array($item)) {
                $errors++;
                continue;
            }

            $checked++;
            $remoteStock = $this->stockFromItem($item, $member->variation_id);
            if ($remoteStock === null || $remoteStock === (int) $group->stock) {
                continue;
            }

            DB::transaction(function () use ($group, $member, $remoteStock): void {
                $locked = MeliSharedStockGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                $before = max(0, (int) $locked->stock);
                $after = max(0, $remoteStock);

                if ($before === $after) {
                    return;
                }

                $locked->forceFill([
                    'stock' => $after,
                    'last_reconciled_at' => now(),
                    'last_error' => null,
                ])->save();

                MeliSharedStockMovement::query()->create([
                    'group_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'meli_account_id' => $member->meli_account_id,
                    'movement_key' => sha1('master-pull|'.$locked->id.'|'.now()->format('YmdHis.u').'|'.random_int(1, PHP_INT_MAX)),
                    'type' => 'master_pull',
                    'item_id' => $member->mlm,
                    'variation_id' => $member->variation_id,
                    'sku' => $member->sku,
                    'applied_quantity' => 0,
                    'last_adjustment' => $after - $before,
                    'last_status' => 'master_pull',
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'metadata' => ['source' => 'account_1_live_item'],
                    'processed_at' => now(),
                ]);
            });

            $changed++;
            $changedIds[] = (int) $group->id;
        }

        if ($push) {
            foreach (array_unique($changedIds) as $groupId) {
                PushMeliSharedStockGroupJob::dispatch($groupId)->onQueue('meli');
            }
        }

        return [
            'groups' => $groups->count(),
            'checked' => $checked,
            'changed' => $changed,
            'errors' => $errors,
            'queued' => $push ? count(array_unique($changedIds)) : 0,
        ];
    }

    /** @param array<string,mixed> $item */
    private function stockFromItem(array $item, ?string $variationId): ?int
    {
        if (! filled($variationId)) {
            return isset($item['available_quantity']) ? max(0, (int) $item['available_quantity']) : null;
        }

        foreach ((array) ($item['variations'] ?? []) as $variation) {
            if (! is_array($variation) || (string) ($variation['id'] ?? '') !== (string) $variationId) {
                continue;
            }

            return max(0, (int) ($variation['available_quantity'] ?? 0));
        }

        return null;
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
        $data = $this->oauth->refreshAccessToken($clientId, $clientSecret, (string) $account->refresh_token);

        $account->forceFill([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 21600))->subMinutes(2),
        ])->save();
        $account->refresh();
    }

    /** @param array<string,mixed> $payload */
    private function request(MeliAccount $account, string $method, string $path, array $payload = []): Response
    {
        $client = Http::withToken((string) $account->access_token)->acceptJson()->timeout(60);
        $response = strtolower($method) === 'put'
            ? $client->put('https://api.mercadolibre.com'.$path, $payload)
            : $client->get('https://api.mercadolibre.com'.$path, $payload);

        if ($response->status() === 401) {
            $this->ensureFreshAccessToken($account, true);
            $client = Http::withToken((string) $account->access_token)->acceptJson()->timeout(60);
            $response = strtolower($method) === 'put'
                ? $client->put('https://api.mercadolibre.com'.$path, $payload)
                : $client->get('https://api.mercadolibre.com'.$path, $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Mercado Libre HTTP '.$response->status().': '.($response->json('message') ?: $response->body()));
        }

        return $response;
    }
}
