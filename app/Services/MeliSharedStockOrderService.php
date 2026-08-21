<?php

namespace App\Services;

use App\Jobs\PushMeliSharedStockGroupJob;
use App\Models\MeliOrder;
use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use App\Models\MeliSharedStockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeliSharedStockOrderService
{
    public function reconcile(MeliOrder $order): void
    {
        $accountId = (int) ($order->meli_account_id ?? 0);
        if ($accountId <= 0) {
            return;
        }

        $status = strtolower(trim((string) $order->status));
        if (! in_array($status, ['paid', 'partially_paid', 'cancelled'], true)) {
            return;
        }

        $raw = is_array($order->raw) ? $order->raw : [];
        $rawItems = collect((array) ($raw['order_items'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->values();

        $affectedGroupIds = [];

        // En una cancelación rara sin order_items usamos los movimientos ya existentes.
        if ($status === 'cancelled' && $rawItems->isEmpty()) {
            $movements = MeliSharedStockMovement::query()
                ->where('meli_account_id', $accountId)
                ->where('order_id', (string) $order->order_id)
                ->where('type', 'meli_order')
                ->get();

            foreach ($movements as $movement) {
                if ($this->reconcileMovementToQuantity($movement, 0, $status)) {
                    $affectedGroupIds[$movement->group_id] = true;
                }
            }

            $this->dispatchAfterCommit(array_keys($affectedGroupIds));

            return;
        }

        foreach ($rawItems as $lineIndex => $rawItem) {
            $itemInfo = is_array($rawItem['item'] ?? null) ? $rawItem['item'] : [];
            $itemId = strtoupper(trim((string) ($itemInfo['id'] ?? '')));
            $variationId = trim((string) (
                $itemInfo['variation_id']
                ?? $rawItem['variation_id']
                ?? ''
            ));
            $sku = trim((string) ($itemInfo['seller_sku'] ?? ''));
            $quantity = max(0, (int) ($rawItem['quantity'] ?? 0));

            if ($itemId === '' || $quantity <= 0) {
                continue;
            }

            $movementKey = sha1(implode('|', [
                'meli-order',
                $accountId,
                (string) $order->order_id,
                (string) $lineIndex,
                $itemId,
                $variationId,
                mb_strtoupper($sku),
            ]));

            if ($status === 'cancelled') {
                $existingMovement = MeliSharedStockMovement::query()
                    ->where('movement_key', $movementKey)
                    ->first();

                if ($existingMovement) {
                    if ($this->reconcileMovementToQuantity($existingMovement, 0, $status)) {
                        $affectedGroupIds[$existingMovement->group_id] = true;
                    }
                    continue;
                }
            }

            $member = $this->findMember(
                accountId: $accountId,
                mlm: $itemId,
                variationId: $variationId !== '' ? $variationId : null,
                sku: $sku,
            );

            if (! $member || ! $member->group || ! $member->group->is_enabled) {
                Log::info('MELI SHARED STOCK: artículo de orden sin conexión', [
                    'order_id' => $order->order_id,
                    'account_id' => $accountId,
                    'item_id' => $itemId,
                    'variation_id' => $variationId ?: null,
                    'sku' => $sku,
                ]);
                continue;
            }

            $orderDate = $this->orderDate($raw, $order);
            if ($member->group->activated_at && $orderDate->lt($member->group->activated_at)) {
                continue;
            }

            $desiredQuantity = in_array($status, ['paid', 'partially_paid'], true)
                ? $quantity
                : 0;

            $movement = MeliSharedStockMovement::query()->firstOrCreate(
                ['movement_key' => $movementKey],
                [
                    'group_id' => $member->group_id,
                    'user_id' => $member->user_id,
                    'meli_account_id' => $accountId,
                    'meli_order_id' => $order->id,
                    'order_id' => (string) $order->order_id,
                    'type' => 'meli_order',
                    'item_id' => $itemId,
                    'variation_id' => $variationId !== '' ? $variationId : null,
                    'sku' => $sku !== '' ? $sku : null,
                    'applied_quantity' => 0,
                    'last_adjustment' => 0,
                    'last_status' => null,
                    'stock_before' => (int) $member->group->stock,
                    'stock_after' => (int) $member->group->stock,
                    'metadata' => [
                        'line_index' => $lineIndex,
                        'quantity_from_order' => $quantity,
                    ],
                ],
            );

            if ($this->reconcileMovementToQuantity($movement, $desiredQuantity, $status)) {
                $affectedGroupIds[$member->group_id] = true;
            }
        }

        $this->dispatchAfterCommit(array_keys($affectedGroupIds));
    }

    private function findMember(int $accountId, string $mlm, ?string $variationId, string $sku): ?MeliSharedStockMember
    {
        $base = MeliSharedStockMember::query()
            ->with('group')
            ->where('meli_account_id', $accountId)
            ->where('mlm', $mlm)
            ->where('is_active', true);

        if ($variationId !== null) {
            $exact = (clone $base)->where('variation_id', $variationId)->first();
            if ($exact) {
                return $exact;
            }
        }

        $simple = (clone $base)->whereNull('variation_id')->first();
        if ($simple) {
            return $simple;
        }

        $sku = mb_strtoupper(preg_replace('/\s+/', '', trim($sku)) ?? '');
        if ($sku !== '') {
            $matches = MeliSharedStockMember::query()
                ->with('group')
                ->where('meli_account_id', $accountId)
                ->where('is_active', true)
                ->whereRaw("UPPER(REPLACE(COALESCE(sku, ''), ' ', '')) = ?", [$sku])
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    private function reconcileMovementToQuantity(
        MeliSharedStockMovement $movement,
        int $desiredQuantity,
        string $status,
    ): bool {
        return DB::transaction(function () use ($movement, $desiredQuantity, $status): bool {
            /** @var MeliSharedStockMovement $lockedMovement */
            $lockedMovement = MeliSharedStockMovement::query()
                ->whereKey($movement->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var MeliSharedStockGroup $group */
            $group = MeliSharedStockGroup::query()
                ->whereKey($lockedMovement->group_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $group->is_enabled) {
                return false;
            }

            $currentApplied = max(0, (int) $lockedMovement->applied_quantity);
            $desiredQuantity = max(0, $desiredQuantity);
            $difference = $desiredQuantity - $currentApplied;

            if ($difference === 0) {
                $lockedMovement->forceFill([
                    'last_status' => $status,
                    'processed_at' => now(),
                ])->save();

                return false;
            }

            $before = max(0, (int) $group->stock);
            // difference positiva = nueva venta; negativa = cancelación/devolución.
            $after = max(0, $before - $difference);

            $group->forceFill([
                'stock' => $after,
                'last_reconciled_at' => now(),
                'last_error' => null,
            ])->save();

            $lockedMovement->forceFill([
                'applied_quantity' => $desiredQuantity,
                'last_adjustment' => -$difference,
                'last_status' => $status,
                'stock_before' => $before,
                'stock_after' => $after,
                'processed_at' => now(),
            ])->save();

            Log::info('MELI SHARED STOCK: movimiento aplicado', [
                'group_id' => $group->id,
                'order_id' => $lockedMovement->order_id,
                'status' => $status,
                'quantity_before' => $currentApplied,
                'quantity_after' => $desiredQuantity,
                'stock_before' => $before,
                'stock_after' => $after,
            ]);

            return true;
        });
    }

    /** @param list<int> $groupIds */
    private function dispatchAfterCommit(array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        DB::afterCommit(function () use ($groupIds): void {
            foreach (array_values(array_unique(array_map('intval', $groupIds))) as $groupId) {
                PushMeliSharedStockGroupJob::dispatch($groupId)->onQueue('meli');
            }
        });
    }

    private function orderDate(array $raw, MeliOrder $order): Carbon
    {
        $rawDate = $raw['date_created'] ?? null;
        if (is_string($rawDate) && trim($rawDate) !== '') {
            try {
                return Carbon::parse($rawDate);
            } catch (\Throwable) {
                // fallback abajo
            }
        }

        return $order->created_at ? Carbon::parse($order->created_at) : now();
    }
}
