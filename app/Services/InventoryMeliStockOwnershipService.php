<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\MeliAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryMeliStockOwnershipService
{
    public const PROTECTED = 'PROTECTED';

    public const LEGACY_ALLOWED = 'LEGACY_ALLOWED';

    public const BLOCKED_LEGACY_VARIATION_OWNERSHIP = 'BLOCKED_LEGACY_VARIATION_OWNERSHIP';

    public const UNKNOWN_ACCOUNT = 'UNKNOWN_ACCOUNT';

    /** @var array<string, bool> */
    private array $legacySkipCache = [];

    /**
     * The enabled Inventory link owns the stock portion of this listing for
     * legacy-writer purposes. Price and status remain independently writable.
     */
    public function shouldSkipLegacyListing(?int $accountId, string $listingId): bool
    {
        if (! $accountId || trim($listingId) === '') {
            return false;
        }

        $key = $accountId.'|'.trim($listingId);
        if (array_key_exists($key, $this->legacySkipCache)) {
            return $this->legacySkipCache[$key];
        }

        return $this->legacySkipCache[$key] = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', (string) $accountId)
            ->where('external_listing_id', trim($listingId))
            ->where('is_active', true)
            ->where('stock_sync_enabled', true)
            ->exists();
    }

    /** @return array{status:string,legacy_writer_detected:bool,legacy_sources:list<string>,account:?MeliAccount} */
    public function inspect(InventoryChannelLink $link): array
    {
        $account = ctype_digit((string) $link->account_key)
            ? MeliAccount::query()->find((int) $link->account_key)
            : null;
        if (! $account) {
            return [
                'status' => self::UNKNOWN_ACCOUNT,
                'legacy_writer_detected' => false,
                'legacy_sources' => [],
                'account' => null,
            ];
        }

        $sources = $this->legacySources($link, $account);
        $status = self::PROTECTED;
        if (! $link->is_active || ! $link->stock_sync_enabled) {
            $status = self::LEGACY_ALLOWED;
        } elseif (filled($link->external_variant_id) && $sources !== []) {
            $status = self::BLOCKED_LEGACY_VARIATION_OWNERSHIP;
        }

        return [
            'status' => $status,
            'legacy_writer_detected' => $sources !== [],
            'legacy_sources' => $sources,
            'account' => $account,
        ];
    }

    public function legacyAccountIdForUser(int $userId, string $meliUserId): ?int
    {
        return MeliAccount::query()
            ->where('user_id', $userId)
            ->where('meli_user_id', $meliUserId)
            ->value('id');
    }

    /** @return list<string> */
    private function legacySources(InventoryChannelLink $link, MeliAccount $account): array
    {
        $sources = [];
        $sku = trim((string) ($link->product?->sku ?? ''));
        $listing = trim((string) $link->external_listing_id);

        if ($sku !== '' && Schema::hasTable('llantas') && DB::table('llantas')->where('sku', $sku)->exists()) {
            $sources[] = 'llantas';
        }
        if ($sku !== '' && Schema::hasTable('producto_compuestos') && DB::table('producto_compuestos')->where('sku', $sku)->exists()) {
            $sources[] = 'producto_compuestos';
        }
        if ($listing !== '' && Schema::hasTable('syscom_meli_queues') && DB::table('syscom_meli_queues')
            ->where('user_id', $account->user_id)
            ->where('mlm', $listing)
            ->exists()) {
            $sources[] = 'syscom_meli_queues';
        }

        return array_values(array_unique($sources));
    }
}
