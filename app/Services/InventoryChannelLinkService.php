<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryChannelLinkService
{
    public function create(array $data): InventoryChannelLink
    {
        return DB::transaction(function () use ($data): InventoryChannelLink {
            $normalized = $this->normalize($data);
            $this->validateChannelData($normalized);
            $this->ensureUnique($normalized);

            try {
                return InventoryChannelLink::create($normalized);
            } catch (UniqueConstraintViolationException $exception) {
                throw new InvalidArgumentException('Ya existe un enlace para ese identificador externo.', 0, $exception);
            }
        });
    }

    public function update(InventoryChannelLink|int $link, array $data): InventoryChannelLink
    {
        $link = $link instanceof InventoryChannelLink ? $link : InventoryChannelLink::query()->findOrFail($link);

        return DB::transaction(function () use ($link, $data): InventoryChannelLink {
            $normalized = $this->normalize(array_merge($link->toArray(), $data));
            $this->validateChannelData($normalized);
            $this->ensureUnique($normalized, $link->getKey());
            $link->update($normalized);

            return $link->fresh();
        });
    }

    public function normalize(array $data): array
    {
        $data['channel'] = strtolower(trim((string) ($data['channel'] ?? '')));
        foreach (['account_key', 'external_product_id', 'external_variant_id', 'external_listing_id', 'external_url', 'remote_status'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            $data[$key] = $value === '' ? null : $value;
        }
        $data['remote_currency'] = isset($data['remote_currency']) && trim((string) $data['remote_currency']) !== ''
            ? strtoupper(trim((string) $data['remote_currency']))
            : null;
        $data['stock_sync_enabled'] = (bool) ($data['stock_sync_enabled'] ?? false);
        $data['identity_key'] = $this->identityKey($data);

        return $data;
    }

    public function identityKey(array $data): string
    {
        $channel = strtolower(trim((string) ($data['channel'] ?? '')));
        $account = trim((string) ($data['account_key'] ?? ''));
        $account = $account === '' ? '-' : $account;
        $identifier = match ($channel) {
            InventoryChannelLink::MERCADO_LIBRE => filled($data['external_variant_id'] ?? null)
                ? 'listing:'.trim((string) ($data['external_listing_id'] ?? '')).'|variation:'.trim((string) $data['external_variant_id'])
                : 'listing:'.trim((string) ($data['external_listing_id'] ?? '')),
            InventoryChannelLink::AMAZON => filled($data['external_listing_id'] ?? null)
                ? 'listing:'.trim((string) $data['external_listing_id'])
                : 'product:'.trim((string) ($data['external_product_id'] ?? '')),
            InventoryChannelLink::SHOPIFY => 'variant:'.trim((string) ($data['external_variant_id'] ?? '')),
            default => 'unknown',
        };

        return $channel.'|account:'.$account.'|'.$identifier;
    }

    private function validateChannelData(array $data): void
    {
        if (! in_array($data['channel'], InventoryChannelLink::CHANNELS, true)) {
            throw new InvalidArgumentException('El canal seleccionado no es válido.');
        }
        if ($data['channel'] === InventoryChannelLink::MERCADO_LIBRE && blank($data['external_listing_id'])) {
            throw new InvalidArgumentException('Mercado Libre requiere el ID de publicación.');
        }
        if ($data['channel'] === InventoryChannelLink::AMAZON
            && blank($data['external_product_id'])
            && blank($data['external_listing_id'])) {
            throw new InvalidArgumentException('Amazon requiere el ID de producto o de publicación.');
        }
        if ($data['channel'] === InventoryChannelLink::SHOPIFY && blank($data['external_variant_id'])) {
            throw new InvalidArgumentException('Shopify requiere el ID de variante.');
        }
    }

    private function ensureUnique(array $data, ?int $ignoreId = null): void
    {
        $query = InventoryChannelLink::query()->where('identity_key', $data['identity_key']);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw new InvalidArgumentException('Ya existe un enlace para ese identificador externo.');
        }

        // Keep each channel/account external identity unique even when an
        // Amazon link carries both a product ID and a listing ID.
        $channel = $data['channel'];
        $account = $data['account_key'] ?? null;
        $identifiers = match ($channel) {
            InventoryChannelLink::MERCADO_LIBRE => [
                'external_listing_id' => $data['external_listing_id'] ?? null,
                'external_variant_id' => $data['external_variant_id'] ?? null,
            ],
            InventoryChannelLink::AMAZON => [
                'external_listing_id' => $data['external_listing_id'] ?? null,
                'external_product_id' => $data['external_product_id'] ?? null,
            ],
            InventoryChannelLink::SHOPIFY => ['external_variant_id' => $data['external_variant_id'] ?? null],
            default => [],
        };
        $query = InventoryChannelLink::query()
            ->where('channel', $channel)
            ->when(blank($account), fn ($builder) => $builder->whereNull('account_key'), fn ($builder) => $builder->where('account_key', $account));
        if ($channel === InventoryChannelLink::MERCADO_LIBRE) {
            $query->where('external_listing_id', $identifiers['external_listing_id'])
                ->when(
                    blank($identifiers['external_variant_id']),
                    fn ($builder) => $builder->whereNull('external_variant_id'),
                    fn ($builder) => $builder->where('external_variant_id', $identifiers['external_variant_id']),
                );
        } else {
            $query->where(function ($nested) use ($identifiers): void {
                foreach ($identifiers as $column => $value) {
                    if ($value !== null && $value !== '') {
                        $nested->orWhere($column, $value);
                    }
                }
            });
        }
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw new InvalidArgumentException('Ya existe un enlace para ese identificador externo.');
        }
    }
}
