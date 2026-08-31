<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliAccount;
use App\Models\MeliClaim;
use App\Models\MeliClaimReason;
use App\Models\MeliOrder;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class MeliClaimsService
{
    private const BASE = '/post-purchase/v1/claims';

    public function __construct(private readonly MeliAccountApiClient $api) {}

    public function safeErrorMessage(Throwable $error): string
    {
        return $this->api->sanitizeMessage($error->getMessage());
    }

    public function ensureFreshToken(MeliAccount $account): void
    {
        $this->api->ensureFreshAccessToken($account);
    }

    public function uploadAttachment(MeliAccount $account, MeliClaim $claim, UploadedFile $file, string $safeFilename): Response
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) throw new \RuntimeException('No fue posible leer el archivo temporal.');
        try {
            return $this->api->postMultipartOnce($account, self::BASE.'/'.rawurlencode($claim->claim_id).'/attachments', 'file', $stream, $safeFilename);
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    public function safeAttachmentFilename(UploadedFile $file, string $hash): string
    {
        $extension = match ($file->getMimeType()) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf', default => throw new \InvalidArgumentException('Formato no permitido.') };
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::ascii($base)) ?? '', '_-');
        $base = $base !== '' ? $base : 'archivo';
        $suffix = '_'.substr($hash, 0, 10).'.'.$extension;
        return substr($base, 0, 125 - strlen($suffix)).$suffix;
    }

    public function sendMessage(MeliAccount $account, MeliClaim $claim, string $receiverRole, string $message, array $attachments = []): Response
    {
        $this->api->ensureFreshAccessToken($account);

        $payload = ['receiver_role' => $receiverRole, 'message' => $message];
        if ($attachments !== []) $payload['attachments'] = array_values($attachments);

        return $this->api->request(
            $account,
            'post',
            self::BASE.'/'.rawurlencode($claim->claim_id).'/actions/send-message',
            $payload,
            refreshAfterUnauthorized: false,
            maxAttempts: 1,
        );
    }

    public function downloadAttachment(MeliAccount $account, MeliClaim $claim, string $attachment): Response
    {
        $this->api->ensureFreshAccessToken($account);
        return $this->api->getReadOnly($account, self::BASE.'/'.rawurlencode($claim->claim_id).'/attachments/'.rawurlencode($attachment).'/download', [], 1);
    }

    /** @return array{received:int,saved:int,failed:int} */
    public function syncAccount(MeliAccount $account, ?string $status = null, int $days = 30, bool $force = false): array
    {
        $this->api->ensureFreshAccessToken($account);
        $offset = 0;
        $limit = 50;
        $result = ['received' => 0, 'saved' => 0, 'failed' => 0];

        do {
            $query = [
                'players.user_id' => (string) $account->meli_user_id,
                'players.role' => 'respondent',
                'offset' => $offset,
                'limit' => $limit,
            ];
            if (filled($status)) $query['status'] = $status;
            if ($days > 0) $query['range'] = 'date_created:after:'.now()->subDays($days)->toISOString().',before:'.now()->toISOString();
            $payload = (array) $this->api->getReadOnly($account, self::BASE.'/search', $query)->json();
            $claims = array_values(array_filter((array) ($payload['data'] ?? $payload['claims'] ?? $payload['results'] ?? []), 'is_array'));
            $result['received'] += count($claims);

            foreach ($claims as $raw) {
                $claimId = trim((string) ($raw['id'] ?? $raw['claim_id'] ?? ''));
                if ($claimId === '') continue;
                try {
                    $this->syncClaim($account, $claimId, $force, $raw);
                    $result['saved']++;
                } catch (Throwable $e) {
                    $result['failed']++;
                    $this->recordError($account, $claimId, $e);
                }
            }

            $offset += count($claims);
            $total = (int) data_get($payload, 'paging.total', $offset);
        } while ($claims !== [] && $offset < $total);

        return $result;
    }

    public function syncClaim(MeliAccount $account, string $claimId, bool $force = false, array $seed = []): MeliClaim
    {
        $this->api->ensureFreshAccessToken($account);
        $claimId = trim($claimId);
        $claim = $this->read($account, self::BASE.'/'.rawurlencode($claimId));
        $record = $this->persist($account, $claimId, $claim);

        $resources = [
            'raw_detail' => '/detail',
            'reputation' => '/affects-reputation',
            'status_history' => '/status-history',
            'actions_history' => '/actions-history',
            'expected_resolutions' => '/expected-resolutions',
            'messages' => '/messages',
            'changes' => '/changes',
        ];
        $updates = [];
        foreach ($resources as $key => $suffix) {
            try {
                $data = $this->read($account, self::BASE.'/'.rawurlencode($claimId).$suffix);
                if ($key === 'reputation') {
                    $updates += $this->mapReputation($data);
                } else {
                    $updates[$key] = in_array($key, ['messages', 'changes'], true)
                        ? $this->withoutParticipantIds($data)
                        : $data;
                }
            } catch (Throwable $e) {
                Log::notice('MELI CLAIMS: recurso opcional no disponible', [
                    'meli_account_id' => $account->id, 'claim_id' => $claimId,
                    'resource' => $key, 'error' => $this->api->sanitizeMessage($e->getMessage()),
                ]);
            }
        }

        $updates['available_actions'] = $this->respondentActions($claim);
        $updates += $this->actionResponsibility($updates['available_actions']);
        if (is_array($updates['raw_detail'] ?? null)) {
            $updates += $this->mapDetail($updates['raw_detail']);
        }
        try {
            $reason = $this->syncReason($account, $record->reason_id);
            if ($reason !== null) {
                $updates['raw_detail'] = [...(array) ($updates['raw_detail'] ?? []), 'reason' => $reason->raw_data];
            }
        } catch (Throwable $e) {
            Log::notice('MELI CLAIMS: motivo no disponible', [
                'meli_account_id' => $account->id, 'claim_id' => $claimId,
                'reason_id' => $record->reason_id, 'error' => $this->api->sanitizeMessage($e->getMessage()),
            ]);
        }
        $record->forceFill([...$updates, 'last_synced_at' => now(), 'sync_error' => null])->save();

        return $record->fresh(['reason', 'order.items', 'meliAccount']);
    }

    private function persist(MeliAccount $account, string $claimId, array $raw): MeliClaim
    {
        $resourceId = is_array($raw['resource'] ?? null) ? data_get($raw, 'resource.id') : ($raw['resource_id'] ?? null);
        $resource = is_array($raw['resource'] ?? null) ? data_get($raw, 'resource.name') : ($raw['resource'] ?? null);
        $orderId = $raw['order_id'] ?? ($resource === 'order' ? $resourceId : null);
        $meliOrderId = filled($orderId) ? MeliOrder::query()
            ->where('meli_account_id', $account->id)
            ->where('order_id', (string) $orderId)
            ->value('id') : null;

        return MeliClaim::query()->updateOrCreate(
            ['meli_account_id' => $account->id, 'claim_id' => $claimId],
            [
                'meli_order_id' => $meliOrderId,
                'resource' => $this->text($resource), 'resource_id' => $this->text($resourceId),
                'order_id' => $this->text($orderId), 'pack_id' => $this->text($raw['pack_id'] ?? data_get($raw, 'related_entities.0.id')),
                'type' => $this->text($raw['type'] ?? null), 'stage' => $this->text($raw['stage'] ?? null),
                'status' => $this->text($raw['status'] ?? null), 'reason_id' => $this->text($raw['reason_id'] ?? data_get($raw, 'reason.id')),
                'fulfilled' => $raw['fulfilled'] ?? null, 'claimed_quantity' => $raw['claimed_quantity'] ?? null,
                'action_responsible' => $this->text($raw['action_responsible'] ?? null),
                'due_date' => $this->date($raw['due_date'] ?? null), 'resolution_reason' => $this->text(data_get($raw, 'resolution.reason')),
                'date_created' => $this->date($raw['date_created'] ?? null), 'last_updated' => $this->date($raw['last_updated'] ?? null),
                'raw_claim' => $this->sanitizeClaimPayload($raw), 'last_synced_at' => now(), 'sync_error' => null,
            ],
        );
    }

    private function mapReputation(array $data): array
    {
        $affects = $data['affects_reputation'] ?? $data['affected'] ?? null;
        $affects = match ($affects) {
            true, 'affected' => true,
            false, 'not_affected', 'not_applies' => false,
            default => null,
        };
        return [
            'affects_reputation' => $affects,
            'reputation_has_incentive' => isset($data['has_incentive']) ? (bool) $data['has_incentive'] : null,
            'reputation_due_date' => $this->date($data['due_date'] ?? null),
        ];
    }

    private function mapDetail(array $data): array
    {
        return [
            'detail_title' => $this->text($data['title'] ?? null),
            'detail_description' => $this->text($data['description'] ?? null),
            'problem' => $this->text($data['problem'] ?? null),
            'action_responsible' => $this->text($data['action_responsible'] ?? null),
            'due_date' => $this->date($data['due_date'] ?? null),
        ];
    }

    private function syncReason(MeliAccount $account, ?string $reasonId): ?MeliClaimReason
    {
        if (! filled($reasonId)) return null;
        $cached = MeliClaimReason::query()->where('reason_id', $reasonId)->first();
        if ($cached?->last_synced_at?->gte(now()->subDays(30))) return $cached;
        $reason = $this->read($account, self::BASE.'/reasons/'.rawurlencode($reasonId));
        return MeliClaimReason::query()->updateOrCreate(['reason_id' => $reasonId], [
            'name' => $this->text($reason['name'] ?? null),
            'detail' => $this->text($reason['detail'] ?? null),
            'flow' => $this->text($reason['flow'] ?? null), 'raw_data' => $reason,
            'last_synced_at' => now(),
        ]);
    }

    private function respondentActions(array $claim): array
    {
        foreach ((array) ($claim['players'] ?? []) as $player) {
            if (is_array($player) && (($player['role'] ?? null) === 'respondent' || ($player['type'] ?? null) === 'seller')) {
                return array_values(array_filter((array) ($player['available_actions'] ?? []), 'is_array'));
            }
        }
        return [];
    }

    private function actionResponsibility(array $actions): array
    {
        $dates = array_values(array_filter(array_map(fn ($action) => $this->date($action['due_date'] ?? null), $actions)));
        usort($dates, fn (Carbon $a, Carbon $b) => $a->getTimestamp() <=> $b->getTimestamp());
        if ($actions === []) return [];
        return array_filter([
            'action_responsible' => 'respondent',
            'due_date' => $dates[0] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function read(MeliAccount $account, string $path): array
    {
        $data = $this->api->getReadOnly($account, $path, [], 1)->json();
        return is_array($data) ? $data : [];
    }

    private function recordError(MeliAccount $account, string $claimId, Throwable $e): void
    {
        MeliClaim::query()->where('meli_account_id', $account->id)->where('claim_id', $claimId)
            ->update(['sync_error' => $this->api->sanitizeMessage($e->getMessage())]);
    }

    private function sanitizeClaimPayload(array $payload): array
    {
        foreach (['buyer', 'complainant', 'shipping_address', 'receiver_address'] as $key) unset($payload[$key]);
        foreach ((array) ($payload['players'] ?? []) as $index => $player) {
            if (! is_array($player)) continue;
            unset($player['user_id'], $player['id'], $player['email'], $player['nickname']);
            $payload['players'][$index] = $player;
        }
        return $payload;
    }

    private function withoutParticipantIds(mixed $value): mixed
    {
        if (! is_array($value)) return $value;

        return collect($value)
            ->reject(fn (mixed $_, string|int $key) => in_array($key, ['user_id', 'buyer_id', 'seller_id'], true))
            ->map(fn (mixed $item) => $this->withoutParticipantIds($item))
            ->all();
    }

    private function text(mixed $value): ?string { $value = trim((string) ($value ?? '')); return $value === '' ? null : $value; }
    private function date(mixed $value): ?Carbon { try { return filled($value) ? Carbon::parse((string) $value) : null; } catch (Throwable) { return null; } }
}
