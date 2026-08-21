<?php

namespace App\Services;

use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use App\Models\MeliSharedStockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MeliSharedStockManager
{
    public function __construct(private readonly MeliSharedStockPushService $pushService)
    {
    }

    public function memberForPublication(int $publicationId, ?string $variationId = null): ?MeliSharedStockMember
    {
        $variationId = filled($variationId) ? trim((string) $variationId) : null;

        return MeliSharedStockMember::query()
            ->with('group')
            ->where('meli_publication_id', $publicationId)
            ->where('is_active', true)
            ->when(
                $variationId !== null,
                fn ($query) => $query->where('variation_id', $variationId),
                fn ($query) => $query->whereNull('variation_id'),
            )
            ->first();
    }

    /** @return array{group:MeliSharedStockGroup,push:array<string,int>} */
    public function setStockFromMaster(
        MeliSharedStockMember $member,
        int $stock,
        int $actorUserId,
        array $metadata = [],
    ): array {
        if ($member->role !== 'master') {
            throw new RuntimeException('La cuenta 1 controla el stock; la cuenta secundaria es solamente espejo.');
        }

        $stock = max(0, $stock);

        $group = DB::transaction(function () use ($member, $stock, $actorUserId, $metadata): MeliSharedStockGroup {
            /** @var MeliSharedStockGroup $group */
            $group = MeliSharedStockGroup::query()
                ->whereKey($member->group_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $group->is_enabled) {
                throw new RuntimeException('El grupo de stock compartido está deshabilitado.');
            }

            $before = max(0, (int) $group->stock);
            $group->forceFill([
                'stock' => $stock,
                'last_reconciled_at' => now(),
                'last_error' => null,
            ])->save();

            MeliSharedStockMovement::query()->create([
                'group_id' => $group->id,
                'user_id' => $actorUserId,
                'meli_account_id' => $member->meli_account_id,
                'movement_key' => sha1('manual|'.$group->id.'|'.now()->format('YmdHis.u').'|'.random_int(1, PHP_INT_MAX)),
                'type' => 'manual_set',
                'item_id' => $member->mlm,
                'variation_id' => $member->variation_id,
                'sku' => $member->sku,
                'applied_quantity' => 0,
                'last_adjustment' => $stock - $before,
                'last_status' => 'manual',
                'stock_before' => $before,
                'stock_after' => $stock,
                'metadata' => $metadata,
                'processed_at' => now(),
            ]);

            return $group->fresh();
        });

        return [
            'group' => $group,
            'push' => $this->pushService->pushGroup($group),
        ];
    }
}
