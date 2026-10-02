<?php

namespace App\Support;

use App\Models\InventoryKitReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\MeliAccount;
use App\Models\MeliOrder;

class InventoryActorPresenter
{
    /** @var array<int, string> */
    private static array $meliAccountLabels = [];

    /** @var array<string, ?string> */
    private static array $meliOrderAccountLabels = [];

    public static function labelForMovement(InventoryMovement $movement): string
    {
        if ($movement->createdBy?->name) {
            return $movement->createdBy->name;
        }

        // Si la referencia directa apunta a una orden de MeLi
        if ($movement->reference_type === 'meli_order' && $movement->reference_id) {
            $accountLabel = self::getAccountLabelByOrderId($movement->reference_id);
            if ($accountLabel) {
                return $accountLabel;
            }
        }

        // Si la referencia apunta a una reserva de kit
        if ($movement->reference_type === InventoryKitReservation::SOURCE_TYPE && $movement->reference_id) {
            $kitParent = InventoryKitReservation::query()->find($movement->reference_id);
            if ($kitParent) {
                if ($kitParent->createdBy?->name) {
                    return $kitParent->createdBy->name;
                }
                if ($kitParent->source_type === 'meli_order' && $kitParent->source_id) {
                    $accountLabel = self::getAccountLabelByOrderId($kitParent->source_id);
                    if ($accountLabel) {
                        return $accountLabel;
                    }
                }
                return self::resolveChannelLabel(
                    array_merge($kitParent->metadata ?? [], $movement->metadata ?? []),
                    $movement->reference ?: $kitParent->reference,
                    $movement->notes
                );
            }
        }

        return self::resolveChannelLabel(
            $movement->metadata,
            $movement->reference,
            $movement->notes
        );
    }

    public static function labelForReservation(InventoryReservation $reservation): string
    {
        if ($reservation->createdBy?->name) {
            return $reservation->createdBy->name;
        }

        // Si proviene directamente de una orden de MeLi
        if ($reservation->source_type === 'meli_order' && $reservation->source_id) {
            $accountLabel = self::getAccountLabelByOrderId($reservation->source_id);
            if ($accountLabel) {
                return $accountLabel;
            }
        }

        // Si proviene de una reserva de kit
        if ($reservation->source_type === InventoryKitReservation::SOURCE_TYPE && $reservation->source_id) {
            $kitParent = InventoryKitReservation::query()->find($reservation->source_id);
            if ($kitParent) {
                if ($kitParent->createdBy?->name) {
                    return $kitParent->createdBy->name;
                }
                if ($kitParent->source_type === 'meli_order' && $kitParent->source_id) {
                    $accountLabel = self::getAccountLabelByOrderId($kitParent->source_id);
                    if ($accountLabel) {
                        return $accountLabel;
                    }
                }
                return self::resolveChannelLabel(
                    array_merge($kitParent->metadata ?? [], $reservation->metadata ?? []),
                    $reservation->reference ?: $kitParent->reference,
                    $reservation->source_type
                );
            }
        }

        return self::resolveChannelLabel(
            $reservation->metadata,
            $reservation->reference,
            $reservation->source_type
        );
    }

    public static function labelForKitReservation(InventoryKitReservation $kitReservation): string
    {
        if ($kitReservation->createdBy?->name) {
            return $kitReservation->createdBy->name;
        }

        if ($kitReservation->source_type === 'meli_order' && $kitReservation->source_id) {
            $accountLabel = self::getAccountLabelByOrderId($kitReservation->source_id);
            if ($accountLabel) {
                return $accountLabel;
            }
        }

        return self::resolveChannelLabel(
            $kitReservation->metadata,
            $kitReservation->reference,
            $kitReservation->source_type
        );
    }

    private static function resolveChannelLabel(?array $metadata, ?string $reference, ?string $extra = ''): string
    {
        $channel = strtolower((string) ($metadata['channel'] ?? ''));
        $accountKey = (string) ($metadata['account_key'] ?? '');

        // 1. Canal explícito en metadatos
        if ($channel === 'mercado_libre' || $channel === 'meli') {
            return self::meliLabel($accountKey, $metadata['remote_order_id'] ?? null);
        }
        if ($channel === 'amazon') {
            return 'Amazon';
        }
        if ($channel === 'shopify') {
            return 'Shopify';
        }

        // 2. Si la referencia o notas contienen indicios de Mercado Libre
        $text = ($reference ?? '').' '.($extra ?? '');
        if (preg_match('/(?:ML\s+orde(?:n|r)|meli_order|MLM\d+)/iu', $text)) {
            if (preg_match('/(?:ML\s+orde(?:n|r)|meli_order\s*-\s*#?)\s*(\d+)/iu', $text, $orderMatch)) {
                $orderId = $orderMatch[1];
                $accountLabel = self::getAccountLabelByRemoteOrderId($orderId);
                if ($accountLabel) {
                    return $accountLabel;
                }
            }

            return self::meliLabel($accountKey);
        }

        // 3. Amazon
        if (stripos($text, 'amazon') !== false || stripos($text, 'amz') !== false) {
            return 'Amazon';
        }

        // 4. Shopify
        if (stripos($text, 'shopify') !== false) {
            return 'Shopify';
        }

        return 'Sistema';
    }

    private static function meliLabel(string $accountKey = '', ?string $remoteOrderId = null): string
    {
        if ($accountKey !== '' && ctype_digit($accountKey)) {
            $label = self::getAccountLabel((int) $accountKey);
            if ($label) {
                return $label;
            }
        }

        if ($remoteOrderId !== null) {
            $label = self::getAccountLabelByRemoteOrderId($remoteOrderId);
            if ($label) {
                return $label;
            }
        }

        // Si sólo hay una cuenta configurada en la BD o hay default
        $accountsCount = MeliAccount::query()->count();
        if ($accountsCount === 1) {
            $account = MeliAccount::query()->first();
            if ($account) {
                return self::formatMeliAccount($account);
            }
        }

        $defaultAccount = MeliAccount::query()->where('is_default', true)->first();
        if ($defaultAccount) {
            return self::formatMeliAccount($defaultAccount);
        }

        return 'Mercado Libre';
    }

    private static function getAccountLabelByOrderId(int|string $orderId): ?string
    {
        $key = 'pk:'.((string) $orderId);
        if (array_key_exists($key, self::$meliOrderAccountLabels)) {
            return self::$meliOrderAccountLabels[$key];
        }

        $order = MeliOrder::query()->with('meliAccount')->find($orderId);
        if ($order?->meliAccount) {
            $label = self::formatMeliAccount($order->meliAccount);
            self::$meliOrderAccountLabels[$key] = $label;

            return $label;
        }

        self::$meliOrderAccountLabels[$key] = null;

        return null;
    }

    private static function getAccountLabelByRemoteOrderId(string $remoteOrderId): ?string
    {
        $key = 'remote:'.$remoteOrderId;
        if (array_key_exists($key, self::$meliOrderAccountLabels)) {
            return self::$meliOrderAccountLabels[$key];
        }

        $order = MeliOrder::query()
            ->where('order_id', $remoteOrderId)
            ->orWhere('id', $remoteOrderId)
            ->with('meliAccount')
            ->first();

        if ($order?->meliAccount) {
            $label = self::formatMeliAccount($order->meliAccount);
            self::$meliOrderAccountLabels[$key] = $label;

            return $label;
        }

        self::$meliOrderAccountLabels[$key] = null;

        return null;
    }

    private static function getAccountLabel(int $accountId): ?string
    {
        if (isset(self::$meliAccountLabels[$accountId])) {
            return self::$meliAccountLabels[$accountId];
        }

        $account = MeliAccount::query()->find($accountId);
        if (! $account) {
            return null;
        }

        $label = self::formatMeliAccount($account);
        self::$meliAccountLabels[$accountId] = $label;

        return $label;
    }

    private static function formatMeliAccount(MeliAccount $account): string
    {
        $type = $account->is_default ? 'Principal' : 'Secundaria';
        $nickname = trim((string) $account->nickname);

        if ($nickname !== '' && ! in_array(strtolower($nickname), ['principal', 'secundaria'], true)) {
            return "Mercado Libre ({$type} · {$nickname})";
        }

        return "Mercado Libre ({$type})";
    }
}
