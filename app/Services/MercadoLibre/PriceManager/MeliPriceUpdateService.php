<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliPriceChange;
use App\Models\MeliPriceChangeBatch;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Services\MercadoLibre\LinkedPublications\MeliLinkedPublicationService;
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
        private readonly MeliLinkedPublicationService $linkedPublications,
        private readonly MeliEstimatedReceivableSnapshotService $receivableSnapshots,
    ) {}

    /** @return array<string, mixed> */
    public function update(
        int $userId,
        MeliAccount $account,
        MeliPriceManagerItem $item,
        string $simulationToken,
        ?float $submittedPrice = null,
        ?string $submittedListingTypeId = null,
    ): array {
        $snapshot = $this->tokens->resolve($simulationToken);
        $this->validateSnapshot(
            $snapshot,
            $userId,
            $account,
            $item,
            $submittedPrice,
            $submittedListingTypeId,
        );
        $changes = $this->requestedChanges($snapshot);

        if ($changes['price'] && $changes['listing_type']) {
            throw new MeliPriceUpdateException(
                'Mercado Libre requiere actualizar el precio y el tipo de publicación mediante operaciones separadas. Por seguridad, aplica primero uno de los cambios y después realiza el otro.',
                'combined_update_not_supported',
                422,
            );
        }

        if (! $changes['price'] && ! $changes['listing_type']) {
            $this->tokens->consume($simulationToken);

            return $this->noOpResult($item, $snapshot);
        }

        $lock = Cache::lock($this->lockKey($item), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new MeliPriceUpdateException(
                'Ya existe una actualización en proceso para esta publicación.',
                'update_in_progress',
                409,
            );
        }

        try {
            $snapshot = $this->tokens->resolve($simulationToken);
            $item->refresh();
            $this->validateSnapshot(
                $snapshot,
                $userId,
                $account,
                $item,
                $submittedPrice,
                $submittedListingTypeId,
            );
            $changes = $this->requestedChanges($snapshot);
            if ($changes['price'] && $changes['listing_type']) {
                throw new MeliPriceUpdateException(
                    'Mercado Libre requiere actualizar el precio y el tipo de publicación mediante operaciones separadas. Por seguridad, aplica primero uno de los cambios y después realiza el otro.',
                    'combined_update_not_supported',
                    422,
                );
            }
            if (! $changes['price'] && ! $changes['listing_type']) {
                $this->tokens->consume($simulationToken);

                return $this->noOpResult($item, $snapshot);
            }
            $this->assertWritable($account, $item);

            [$batch, $change] = $this->createAudit($userId, $account, $item, $snapshot, $simulationToken);

            return $this->performUpdate($account, $item, $snapshot, $changes, $simulationToken, $batch, $change);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{price: bool, listing_type: bool}  $changes
     * @return array<string, mixed>
     */
    private function performUpdate(
        MeliAccount $account,
        MeliPriceManagerItem $item,
        array $snapshot,
        array $changes,
        string $simulationToken,
        MeliPriceChangeBatch $batch,
        MeliPriceChange $change,
    ): array {
        $requestedPrice = round((float) $snapshot['proposed_price'], 2);
        $currentListingTypeId = trim((string) ($snapshot['current_listing_type_id'] ?? $item->listing_type_id));
        $requestedListingTypeId = trim((string) ($snapshot['proposed_listing_type_id'] ?? data_get($snapshot, 'simulation.listing_type_id')));
        $remoteConfirmed = false;
        $listingTypeWriteAttempted = false;

        try {
            $this->api->ensureFreshAccessToken($account);
            $priceRelation = ['linked' => false, 'items' => []];
            if ($changes['price']) {
                $this->assertNoPricingAutomation($account, $item);
                $priceRelation = $this->linkedPublications->refreshPriceRelations($account, $item);

                $remoteOldPrice = $this->remoteStandardPrice($account, $item);
                if (! $this->samePrice($remoteOldPrice, (float) $snapshot['current_price'])) {
                    throw new MeliPriceUpdateException(
                        'El precio de Mercado Libre cambió mientras revisabas la proyección. Vuelve a calcular antes de confirmar.',
                        'concurrent_price_change',
                        409,
                    );
                }
            }

            if ($changes['listing_type']) {
                $remoteOldListingTypeId = $this->remoteListingType($account, $item);
                if ($remoteOldListingTypeId !== $currentListingTypeId) {
                    throw new MeliPriceUpdateException(
                        'El tipo de publicación de Mercado Libre cambió mientras revisabas la proyección. Vuelve a calcular antes de confirmar.',
                        'concurrent_listing_type_change',
                        409,
                    );
                }
            }

            // The catalog may change while the user confirms or while the remote checks run.
            // Re-run the ownership/exclusion barrier immediately before the only write request.
            $this->assertWritable($account, $item);

            try {
                if ($changes['listing_type']) {
                    // Changing listing type is a non-idempotent remote operation. From the
                    // moment the POST is attempted, this projection token must never be
                    // reusable, even if the HTTP outcome is ambiguous (for example, a
                    // timeout after Mercado Libre applied the change).
                    $listingTypeWriteAttempted = true;
                    $response = $this->api->request(
                        $account,
                        'post',
                        '/items/'.rawurlencode((string) $item->meli_item_id).'/listing_type',
                        ['id' => $requestedListingTypeId],
                        refreshAfterUnauthorized: false,
                        maxAttempts: 1,
                    );
                } else {
                    $response = $this->api->request(
                        $account,
                        'put',
                        '/items/'.rawurlencode((string) $item->meli_item_id),
                        ['price' => $requestedPrice],
                    );
                }
            } catch (MeliApiRequestException $exception) {
                if ($changes['price'] && $this->containsPriceNotModifiable($exception->getMessage())) {
                    throw new MeliPriceUpdateException(
                        'Mercado Libre tiene una automatización de precios activa para esta publicación. El precio no fue modificado.',
                        'pricing_automation_active',
                        409,
                        $exception,
                    );
                }

                throw new MeliPriceUpdateException(
                    ($changes['listing_type']
                        ? 'Mercado Libre no permitió cambiar el tipo de publicación: '
                        : 'Mercado Libre rechazó el cambio de precio: ')
                        .$this->api->sanitizeMessage($exception->getMessage()),
                    $changes['listing_type'] ? 'listing_type_change_rejected' : 'meli_api_error',
                    502,
                    $exception,
                );
            }

            if ($changes['price'] && $this->containsPriceNotModifiable($response->json())) {
                throw new MeliPriceUpdateException(
                    'Mercado Libre informó que el precio no es modificable por una automatización activa. El precio no fue modificado.',
                    'pricing_automation_active',
                    409,
                );
            }

            $confirmedPrice = round((float) $snapshot['current_price'], 2);
            $confirmedListingTypeId = $currentListingTypeId;
            if ($changes['price']) {
                $confirmedPrice = $this->remoteStandardPrice($account, $item);
                if (! $this->samePrice($confirmedPrice, $requestedPrice)) {
                    throw new MeliPriceUpdateException(
                        'Mercado Libre respondió, pero el precio confirmado no coincide con el solicitado. El cambio se registró como fallido.',
                        'remote_price_not_updated',
                        502,
                    );
                }
            }
            if ($changes['listing_type']) {
                $listingTypePayload = $response->json();
                $confirmedListingTypeId = is_array($listingTypePayload)
                    ? trim((string) ($listingTypePayload['listing_type_id'] ?? ''))
                    : '';

                if ($confirmedListingTypeId === '' || $confirmedListingTypeId !== $requestedListingTypeId) {
                    throw new MeliPriceUpdateException(
                        'Mercado Libre respondió al cambio de tipo, pero no fue posible confirmar de forma segura el tipo aplicado. No se repetirá la operación; sincroniza la publicación antes de intentar otro cambio.',
                        'listing_type_change_unconfirmed',
                        502,
                    );
                }
            }

            $remoteConfirmed = true;
            $relatedPrices = [];
            if ($changes['price'] && ($priceRelation['linked'] ?? false)) {
                foreach ((array) ($priceRelation['items'] ?? []) as $member) {
                    $snapshotItem = MeliPriceManagerItem::query()
                        ->where('meli_account_id', $account->id)
                        ->where('meli_item_id', (string) ($member['meli_item_id'] ?? ''))
                        ->first();
                    if ($snapshotItem !== null) {
                        $this->refreshItemSnapshot($account, $snapshotItem);
                    }
                }
            }
            foreach (($changes['price'] && ($priceRelation['linked'] ?? false)) ? (array) ($priceRelation['items'] ?? []) : [] as $member) {
                $relatedId = (string) ($member['meli_item_id'] ?? '');
                if ($relatedId === '' || $relatedId === (string) $item->meli_item_id) {
                    continue;
                }
                $relatedItem = MeliPriceManagerItem::query()
                    ->where('meli_account_id', $account->id)
                    ->where('meli_item_id', $relatedId)
                    ->first();
                if ($relatedItem !== null) {
                    $relatedPrices[$relatedId] = $this->remoteStandardPrice($account, $relatedItem);
                }
            }
            if ($changes['price']) {
                $priceRelation = $this->linkedPublications->refreshPriceRelations($account, $item);
            }
            $pricePropagated = collect($relatedPrices)->every(
                fn (float $price): bool => $this->samePrice($price, $requestedPrice),
            );

            DB::transaction(function () use ($item, $changes, $confirmedPrice, $confirmedListingTypeId, $relatedPrices, $account, $change, $batch): void {
                $localUpdates = [];
                if ($changes['price']) {
                    $localUpdates['current_price'] = $confirmedPrice;
                }
                if ($changes['listing_type']) {
                    $localUpdates['listing_type_id'] = $confirmedListingTypeId;

                    // A receivable snapshot is only valid for the listing type under
                    // which it was calculated. Clear the previous snapshot before
                    // trying to persist the newly confirmed projection below.
                    $localUpdates['estimated_receivable'] = null;
                    $localUpdates['estimated_receivable_price'] = null;
                    $localUpdates['estimated_receivable_calculated_at'] = null;
                }
                $item->forceFill($localUpdates)->save();
                foreach ($relatedPrices as $relatedId => $relatedPrice) {
                    MeliPriceManagerItem::query()
                        ->where('meli_account_id', $account->id)
                        ->where('meli_item_id', $relatedId)
                        ->update(['current_price' => $relatedPrice]);
                }
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
            $receivableSnapshot = $this->receivableSnapshots->storeForCurrentPrice(
                $item->refresh(),
                (array) ($snapshot['simulation'] ?? []),
            );
            $this->tokens->consume($simulationToken);

            return [
                'batch_id' => (int) $batch->id,
                'change_id' => (int) $change->id,
                'item_id' => (int) $item->id,
                'meli_item_id' => (string) $item->meli_item_id,
                'old_price' => round((float) $snapshot['current_price'], 2),
                'new_price' => round($confirmedPrice, 2),
                'old_listing_type_id' => $currentListingTypeId,
                'new_listing_type_id' => $confirmedListingTypeId,
                'listing_type_name' => MeliPriceManagerItem::listingTypeName($confirmedListingTypeId),
                'price_changed' => $changes['price'],
                'listing_type_changed' => $changes['listing_type'],
                'no_op' => false,
                'updated_at' => now()->toISOString(),
                'price_propagated' => $pricePropagated,
                'price_relations' => $priceRelation,
                'receivable_snapshot' => $receivableSnapshot,
            ];
        } catch (Throwable $exception) {
            if ($remoteConfirmed || $listingTypeWriteAttempted) {
                // Never allow the same token to repeat a listing-type POST after the
                // remote write was attempted. The remote outcome may be uncertain even
                // when the client receives an error or an unexpected response body.
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
                'No fue posible completar el cambio solicitado.',
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
        ?string $submittedListingTypeId,
    ): void {
        if ((int) ($snapshot['user_id'] ?? 0) !== $userId) {
            throw new MeliPriceUpdateException('La proyección pertenece a otro usuario.', 'simulation_user_mismatch', 403);
        }

        if ((int) ($snapshot['account_id'] ?? 0) !== (int) $account->id) {
            throw new MeliPriceUpdateException('La proyección pertenece a otra cuenta.', 'simulation_account_mismatch', 403);
        }

        if ((int) ($snapshot['item_id'] ?? 0) !== (int) $item->id
            || (string) ($snapshot['meli_item_id'] ?? '') !== (string) $item->meli_item_id) {
            throw new MeliPriceUpdateException('La proyección pertenece a otra publicación.', 'simulation_item_mismatch', 403);
        }

        if ($submittedPrice !== null && ! $this->samePrice($submittedPrice, (float) $snapshot['proposed_price'])) {
            throw new MeliPriceUpdateException(
                'El precio enviado no coincide con la proyección. Vuelve a calcular el resultado.',
                'simulation_price_mismatch',
            );
        }

        $proposedListingTypeId = trim((string) ($snapshot['proposed_listing_type_id'] ?? data_get($snapshot, 'simulation.listing_type_id')));
        if (! in_array($proposedListingTypeId, MeliPriceManagerItem::SUPPORTED_LISTING_TYPE_IDS, true)) {
            throw new MeliPriceUpdateException(
                'La proyección no contiene un tipo de publicación compatible.',
                'simulation_listing_type_invalid',
            );
        }

        if ($submittedListingTypeId !== null && $submittedListingTypeId !== $proposedListingTypeId) {
            throw new MeliPriceUpdateException(
                'El tipo de publicación enviado no coincide con la proyección. Vuelve a calcular el resultado.',
                'simulation_listing_type_mismatch',
            );
        }

        $snapshotListingTypeId = trim((string) ($snapshot['current_listing_type_id'] ?? $item->listing_type_id));
        if (! $this->samePrice((float) $item->current_price, (float) $snapshot['current_price'])
            || trim((string) $item->listing_type_id) !== $snapshotListingTypeId) {
            throw new MeliPriceUpdateException(
                'La publicación cambió mientras revisabas la proyección. Vuelve a calcular antes de confirmar.',
                'concurrent_local_change',
                409,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{price: bool, listing_type: bool}
     */
    private function requestedChanges(array $snapshot): array
    {
        return [
            'price' => ! $this->samePrice(
                (float) $snapshot['current_price'],
                (float) $snapshot['proposed_price'],
            ),
            'listing_type' => trim((string) ($snapshot['current_listing_type_id'] ?? ''))
                !== trim((string) ($snapshot['proposed_listing_type_id'] ?? data_get($snapshot, 'simulation.listing_type_id'))),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function noOpResult(MeliPriceManagerItem $item, array $snapshot): array
    {
        $price = round((float) $snapshot['current_price'], 2);
        $listingTypeId = trim((string) ($snapshot['current_listing_type_id'] ?? $item->listing_type_id));

        return [
            'item_id' => (int) $item->id,
            'meli_item_id' => (string) $item->meli_item_id,
            'old_price' => $price,
            'new_price' => $price,
            'old_listing_type_id' => $listingTypeId,
            'new_listing_type_id' => $listingTypeId,
            'listing_type_name' => MeliPriceManagerItem::listingTypeName($listingTypeId),
            'price_changed' => false,
            'listing_type_changed' => false,
            'no_op' => true,
            'updated_at' => now()->toISOString(),
        ];
    }

    private function assertWritable(MeliAccount $account, MeliPriceManagerItem $item): void
    {
        if ((int) $item->meli_account_id !== (int) $account->id) {
            throw new AuthorizationException('La publicación no pertenece a la cuenta seleccionada.');
        }

        $managed = MeliPriceManagerItem::query()
            ->focusedCatalog()
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
                'El estado actual de la publicación no permite modificarla.',
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
            ['display_version' => 'true'],
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
        $marketplacePrices = array_values(array_filter(
            $standardPrices,
            static fn (array $price): bool => in_array(
                'channel_marketplace',
                (array) data_get($price, 'conditions.context_restrictions', []),
                true,
            ),
        ));
        $generalPrices = array_values(array_filter(
            $standardPrices,
            static fn (array $price): bool => (array) data_get($price, 'conditions.context_restrictions', []) === [],
        ));
        $selected = count($marketplacePrices) === 1
            ? $marketplacePrices[0]
            : (count($marketplacePrices) === 0 && count($generalPrices) === 1 ? $generalPrices[0] : null);

        if ($selected === null || ! is_numeric($selected['amount'] ?? null)) {
            throw new MeliPriceUpdateException(
                'No fue posible determinar de forma inequívoca el precio standard de marketplace. No se realizó ningún cambio.',
                'ambiguous_standard_price',
                409,
            );
        }

        return round((float) $selected['amount'], 2);
    }

    private function remoteListingType(MeliAccount $account, MeliPriceManagerItem $item): string
    {
        $payload = $this->api->request(
            $account,
            'get',
            '/items/'.rawurlencode((string) $item->meli_item_id),
        )->json();
        $listingTypeId = is_array($payload) ? trim((string) ($payload['listing_type_id'] ?? '')) : '';

        if ($listingTypeId === '') {
            throw new MeliPriceUpdateException(
                'Mercado Libre no devolvió el tipo actual de la publicación. No se realizó ningún cambio.',
                'remote_listing_type_unavailable',
                502,
            );
        }

        return $listingTypeId;
    }

    private function refreshItemSnapshot(MeliAccount $account, MeliPriceManagerItem $item): void
    {
        $remote = (array) $this->api->request(
            $account,
            'get',
            '/items/'.rawurlencode((string) $item->meli_item_id),
        )->json();

        $item->forceFill([
            'current_price' => is_numeric($remote['price'] ?? null) ? (float) $remote['price'] : $item->current_price,
            'available_quantity' => is_numeric($remote['available_quantity'] ?? null) ? (int) $remote['available_quantity'] : $item->available_quantity,
            'user_product_id' => filled($remote['user_product_id'] ?? null) ? (string) $remote['user_product_id'] : $item->user_product_id,
            'inventory_id' => filled($remote['inventory_id'] ?? null) ? (string) $remote['inventory_id'] : $item->inventory_id,
            'catalog_listing' => (bool) ($remote['catalog_listing'] ?? $item->catalog_listing),
            'raw_item' => $remote,
            'last_synced_at' => now(),
        ])->save();
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
                    'current_listing_type_id' => $snapshot['current_listing_type_id'] ?? $item->listing_type_id,
                    'proposed_listing_type_id' => $snapshot['proposed_listing_type_id'] ?? data_get($simulation, 'listing_type_id'),
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
