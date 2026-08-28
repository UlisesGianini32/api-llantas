<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliPriceChange;
use App\Models\MeliPriceChangeBatch;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MeliPriceUpdateService
{
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly MeliAccountApiClient $api,
        private readonly MeliPriceSimulationTokenService $tokens,
    ) {}

    /** @return array<string, float|int|string> */
    public function update(
        int $userId,
        MeliAccount $account,
        MeliPriceManagerItem $item,
        string $simulationToken,
        ?float $submittedPrice = null,
    ): array {
        $snapshot = $this->tokens->resolve($simulationToken);
        $this->validateSnapshot($snapshot, $userId, $account, $item, $submittedPrice);

        $lock = Cache::lock($this->lockKey($item), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new MeliPriceUpdateException(
                'Ya existe una actualización de precio en proceso para esta publicación.',
                'update_in_progress',
                409,
            );
        }

        try {
            $snapshot = $this->tokens->resolve($simulationToken);
            $this->validateSnapshot($snapshot, $userId, $account, $item, $submittedPrice);
            $this->assertWritable($account, $item);

            [$batch, $change] = $this->createAudit($userId, $account, $item, $snapshot, $simulationToken);

            return $this->performUpdate($account, $item, $snapshot, $simulationToken, $batch, $change);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, float|int|string>
     */
    private function performUpdate(
        MeliAccount $account,
        MeliPriceManagerItem $item,
        array $snapshot,
        string $simulationToken,
        MeliPriceChangeBatch $batch,
        MeliPriceChange $change,
    ): array {
        $requestedPrice = round((float) $snapshot['proposed_price'], 2);
        $remoteConfirmed = false;

        try {
            $this->api->ensureFreshAccessToken($account);
            $this->assertNoPricingAutomation($account, $item);

            $remoteOldPrice = $this->remoteStandardPrice($account, $item);
            if (! $this->samePrice($remoteOldPrice, (float) $snapshot['current_price'])) {
                throw new MeliPriceUpdateException(
                    'El precio de Mercado Libre cambió mientras revisabas la simulación. Vuelve a calcular antes de confirmar.',
                    'concurrent_price_change',
                    409,
                );
            }

            // The catalog may change while the user confirms or while the remote checks run.
            // Re-run the ownership/exclusion barrier immediately before the only write request.
            $this->assertWritable($account, $item);

            try {
                $response = $this->api->request(
                    $account,
                    'put',
                    '/items/'.rawurlencode((string) $item->meli_item_id),
                    ['price' => $requestedPrice],
                );
            } catch (MeliApiRequestException $exception) {
                if ($this->containsPriceNotModifiable($exception->getMessage())) {
                    throw new MeliPriceUpdateException(
                        'Mercado Libre tiene una automatización de precios activa para esta publicación. El precio no fue modificado.',
                        'pricing_automation_active',
                        409,
                        $exception,
                    );
                }

                throw new MeliPriceUpdateException(
                    'Mercado Libre rechazó el cambio de precio: '.$this->api->sanitizeMessage($exception->getMessage()),
                    'meli_api_error',
                    502,
                    $exception,
                );
            }

            if ($this->containsPriceNotModifiable($response->json())) {
                throw new MeliPriceUpdateException(
                    'Mercado Libre informó que el precio no es modificable por una automatización activa. El precio no fue modificado.',
                    'pricing_automation_active',
                    409,
                );
            }

            $confirmedPrice = $this->remoteStandardPrice($account, $item);
            if (! $this->samePrice($confirmedPrice, $requestedPrice)) {
                throw new MeliPriceUpdateException(
                    'Mercado Libre respondió, pero el precio confirmado no coincide con el solicitado. El cambio se registró como fallido.',
                    'remote_price_not_updated',
                    502,
                );
            }

            $remoteConfirmed = true;
            DB::transaction(function () use ($item, $confirmedPrice, $change, $batch): void {
                $item->forceFill(['current_price' => $confirmedPrice])->save();
                $change->forceFill([
                    'status' => 'success',
                    'error_message' => null,
                    'changed_at' => now(),
                ])->save();
                $batch->forceFill([
                    'status' => 'completed',
                    'successful_items' => 1,
                    'failed_items' => 0,
                ])->save();
            });
            $this->tokens->consume($simulationToken);

            return [
                'batch_id' => (int) $batch->id,
                'change_id' => (int) $change->id,
                'item_id' => (int) $item->id,
                'meli_item_id' => (string) $item->meli_item_id,
                'old_price' => round((float) $snapshot['current_price'], 2),
                'new_price' => round($confirmedPrice, 2),
                'updated_at' => now()->toISOString(),
            ];
        } catch (Throwable $exception) {
            if ($remoteConfirmed) {
                $this->tokens->consume($simulationToken);
            }

            $safeMessage = $this->api->sanitizeMessage($exception->getMessage());
            DB::transaction(function () use ($change, $batch, $safeMessage): void {
                $change->forceFill([
                    'status' => 'failed',
                    'error_message' => $safeMessage,
                    'changed_at' => now(),
                ])->save();
                $batch->forceFill([
                    'status' => 'failed',
                    'successful_items' => 0,
                    'failed_items' => 1,
                ])->save();
            });

            if ($exception instanceof MeliPriceUpdateException) {
                throw $exception;
            }

            if ($exception instanceof MeliApiRequestException) {
                throw new MeliPriceUpdateException(
                    'No fue posible completar el cambio con Mercado Libre: '.$safeMessage,
                    'meli_api_error',
                    502,
                    $exception,
                );
            }

            throw new MeliPriceUpdateException(
                'No fue posible completar el cambio de precio.',
                'price_update_failed',
                500,
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function validateSnapshot(
        array $snapshot,
        int $userId,
        MeliAccount $account,
        MeliPriceManagerItem $item,
        ?float $submittedPrice,
    ): void {
        if ((int) ($snapshot['user_id'] ?? 0) !== $userId) {
            throw new MeliPriceUpdateException('La simulación pertenece a otro usuario.', 'simulation_user_mismatch', 403);
        }

        if ((int) ($snapshot['account_id'] ?? 0) !== (int) $account->id) {
            throw new MeliPriceUpdateException('La simulación pertenece a otra cuenta.', 'simulation_account_mismatch', 403);
        }

        if ((int) ($snapshot['item_id'] ?? 0) !== (int) $item->id
            || (string) ($snapshot['meli_item_id'] ?? '') !== (string) $item->meli_item_id) {
            throw new MeliPriceUpdateException('La simulación pertenece a otra publicación.', 'simulation_item_mismatch', 403);
        }

        if ($submittedPrice !== null && ! $this->samePrice($submittedPrice, (float) $snapshot['proposed_price'])) {
            throw new MeliPriceUpdateException(
                'El precio enviado no coincide con la simulación. Vuelve a calcular los cargos.',
                'simulation_price_mismatch',
            );
        }
    }

    private function assertWritable(MeliAccount $account, MeliPriceManagerItem $item): void
    {
        if ((int) $item->meli_account_id !== (int) $account->id) {
            throw new AuthorizationException('La publicación no pertenece a la cuenta seleccionada.');
        }

        $managed = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->whereKey($item->id)
            ->where('meli_account_id', $account->id)
            ->exists();
        if (! $managed) {
            throw new MeliPriceUpdateException(
                'La publicación está excluida del catálogo administrable.',
                'excluded_catalog_item',
                404,
            );
        }

        if (! in_array((string) $item->status, ['active', 'paused'], true)) {
            throw new MeliPriceUpdateException(
                'El estado actual de la publicación no permite modificar su precio.',
                'item_status_not_writable',
                409,
            );
        }
    }

    private function assertNoPricingAutomation(MeliAccount $account, MeliPriceManagerItem $item): void
    {
        if (in_array('dynamic_standard_price', (array) data_get($item->raw_item, 'tags', []), true)) {
            throw new MeliPriceUpdateException(
                'Esta publicación tiene automatización de precios activa en Mercado Libre y no puede modificarse desde Price Manager.',
                'pricing_automation_active',
                409,
            );
        }

        try {
            $response = $this->api->request(
                $account,
                'get',
                '/pricing-automation/items/'.rawurlencode((string) $item->meli_item_id).'/automation',
            );
        } catch (MeliApiRequestException $exception) {
            if ($exception->httpStatus() === 404) {
                return;
            }

            throw new MeliPriceUpdateException(
                'No fue posible verificar la automatización de precios. Por seguridad no se realizó ningún cambio.',
                'pricing_automation_check_failed',
                502,
                $exception,
            );
        }

        $automation = $response->json();
        if (is_array($automation)) {
            $status = strtolower(trim((string) ($automation['status'] ?? $automation['state'] ?? $automation['automation_status'] ?? '')));
            if (in_array($status, ['active', 'enabled', 'running'], true)) {
                throw new MeliPriceUpdateException(
                    'Esta publicación tiene automatización de precios activa en Mercado Libre y no puede modificarse desde Price Manager.',
                    'pricing_automation_active',
                    409,
                );
            }
        }

        throw new MeliPriceUpdateException(
            'Mercado Libre reportó una automatización existente. Por seguridad el precio no se modificó.',
            'pricing_automation_present',
            409,
        );
    }

    private function remoteStandardPrice(MeliAccount $account, MeliPriceManagerItem $item): float
    {
        $response = $this->api->request(
            $account,
            'get',
            '/items/'.rawurlencode((string) $item->meli_item_id).'/prices',
        );
        $payload = $response->json();
        $prices = is_array($payload) && array_is_list($payload)
            ? $payload
            : (is_array($payload) && is_array($payload['prices'] ?? null) ? $payload['prices'] : []);
        $standardPrices = array_values(array_filter(
            $prices,
            static fn (mixed $price): bool => is_array($price)
                && strtolower(trim((string) ($price['type'] ?? ''))) === 'standard',
        ));
        $selected = count($standardPrices) === 1 ? $standardPrices[0] : null;

        if (count($standardPrices) > 1) {
            $marketplacePrices = array_values(array_filter(
                $standardPrices,
                static fn (array $price): bool => in_array(
                    'channel_marketplace',
                    (array) data_get($price, 'conditions.context_restrictions', []),
                    true,
                ),
            ));
            $selected = count($marketplacePrices) === 1 ? $marketplacePrices[0] : null;
        }

        if ($selected === null || ! is_numeric($selected['amount'] ?? null)) {
            throw new MeliPriceUpdateException(
                'No fue posible determinar de forma inequívoca el precio standard de marketplace. No se realizó ningún cambio.',
                'ambiguous_standard_price',
                409,
            );
        }

        return round((float) $selected['amount'], 2);
    }

    /** @param array<string, mixed> $snapshot
     * @return array{MeliPriceChangeBatch, MeliPriceChange}
     */
    private function createAudit(
        int $userId,
        MeliAccount $account,
        MeliPriceManagerItem $item,
        array $snapshot,
        string $simulationToken,
    ): array {
        return DB::transaction(function () use ($userId, $account, $item, $snapshot, $simulationToken): array {
            $simulation = (array) ($snapshot['simulation'] ?? []);
            $sellingFee = data_get($simulation, 'charges.sale_fee.amount', $simulation['sale_fee'] ?? null);
            $shippingCost = data_get($simulation, 'charges.shipping.seller_cost', $simulation['shipping_cost'] ?? null);
            $meliChargesTotal = $simulation['meli_charges_total']
                ?? $simulation['confirmed_charges_total']
                ?? $simulation['total_charges']
                ?? null;
            $taxWithholding = data_get($simulation, 'charges.taxes.available') === true
                && is_numeric(data_get($simulation, 'charges.taxes.amount'))
                    ? (float) data_get($simulation, 'charges.taxes.amount')
                    : null;
            $totalCharges = $simulation['total_charges']
                ?? ($meliChargesTotal !== null ? (float) $meliChargesTotal + (float) ($taxWithholding ?? 0) : null);
            $batch = MeliPriceChangeBatch::query()->create([
                'meli_account_id' => $account->id,
                'brand_group_id' => $item->brand_group_id,
                'created_by' => $userId,
                'type' => 'individual',
                'status' => 'processing',
                'notes' => json_encode([
                    'source' => 'meli_price_manager_phase_7c',
                    'simulation_token_sha256' => hash('sha256', $simulationToken),
                    'simulation_calculated_at' => $simulation['calculated_at'] ?? null,
                    'estimated_total_charges' => $totalCharges,
                    'tax_profile_snapshot' => data_get($simulation, 'charges.taxes.profile'),
                    'tax_rule_snapshot' => data_get($simulation, 'charges.taxes.rule'),
                    'simulation_snapshot' => $simulation,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'total_items' => 1,
                'successful_items' => 0,
                'failed_items' => 0,
            ]);
            $change = MeliPriceChange::query()->create([
                'batch_id' => $batch->id,
                'price_manager_item_id' => $item->id,
                'meli_item_id' => $item->meli_item_id,
                'old_price' => $snapshot['current_price'],
                'new_price' => $snapshot['proposed_price'],
                'selling_fee' => $sellingFee,
                'shipping_cost' => $shippingCost,
                'tax_withholding' => $taxWithholding,
                'other_charges' => max(0, round(
                    (float) ($meliChargesTotal ?? 0)
                    - (float) ($sellingFee ?? 0)
                    - (float) ($shippingCost ?? 0),
                    2,
                )),
                'estimated_net' => $simulation['estimated_receivable'] ?? null,
                'status' => 'processing',
                'error_message' => null,
                'changed_by' => $userId,
                'changed_at' => null,
            ]);

            return [$batch, $change];
        });
    }

    private function containsPriceNotModifiable(mixed $value): bool
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = strtolower((string) $text);

        return str_contains($text, 'item.price.not_modifiable')
            || str_contains($text, 'cannot modify price on items with dynamic pricing');
    }

    private function samePrice(float $first, float $second): bool
    {
        return (int) round($first * 100) === (int) round($second * 100);
    }

    private function lockKey(MeliPriceManagerItem $item): string
    {
        return 'meli-price-manager:price-update:'.$item->meli_account_id.':'.$item->id;
    }

    private function releaseLock(Lock $lock): void
    {
        $lock->release();
    }
}
