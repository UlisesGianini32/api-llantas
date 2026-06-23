<?php

namespace App\Support;

use App\Models\SyscomProduct;
use App\Services\SyscomApiService;
use Illuminate\Support\Facades\Log;

/**
 * Durante sync de catálogo: el listado /productos casi nunca trae precios USD.
 * Este helper pide detalle cuando faltan y no borra precios ya guardados en BD.
 */
class SyscomProductPriceHydrator
{
    /**
     * @return array{precio_lista: ?float, precio_especial: ?float, precio_descuento: ?float}
     */
    public static function extractUsdPrices(array $item, array $detail): array
    {
        return SyscomPrecioExtractor::extractSyscomPrecios($item, $detail);
    }

    /**
     * @param  array{precio_lista: ?float, precio_especial: ?float, precio_descuento: ?float}  $prices
     */
    public static function hasUsdPrices(array $prices): bool
    {
        foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $key) {
            if ((float) ($prices[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el listado/detalle no trae USD, consulta GET /productos/{id} (una vez).
     *
     * @return array{detail: array, fetched_detail: bool}
     */
    public static function ensureDetailWithPrices(
        SyscomApiService $api,
        string $token,
        int $productoId,
        string $branchCode,
        array $item,
        array $detail,
        ?SyscomProduct $existing = null
    ): array {
        $prices = self::extractUsdPrices($item, $detail);
        if (self::hasUsdPrices($prices)) {
            return ['detail' => $detail, 'fetched_detail' => false];
        }

        if ($existing !== null && self::existingProductHasUsd($existing)) {
            return ['detail' => $detail, 'fetched_detail' => false];
        }

        // Ya se pidió detalle en esta pasada (--with-detail) aunque venga sin USD.
        if ($detail !== []) {
            return ['detail' => $detail, 'fetched_detail' => false];
        }

        try {
            $fetched = $api->getProduct($token, $productoId, $branchCode);
            if (is_array($fetched) && self::hasUsdPrices(self::extractUsdPrices($item, $fetched))) {
                return ['detail' => $fetched, 'fetched_detail' => true];
            }

            return ['detail' => is_array($fetched) ? $fetched : [], 'fetched_detail' => true];
        } catch (\Throwable $e) {
            Log::warning('SYSCOM hydrate: detalle falló', [
                'syscom_producto_id' => $productoId,
                'err' => $e->getMessage(),
            ]);

            return ['detail' => $detail, 'fetched_detail' => false];
        }
    }

    public static function existingProductHasUsd(SyscomProduct $existing): bool
    {
        if ((float) ($existing->precio_descuento ?? 0) > 0
            || (float) ($existing->precio_lista ?? 0) > 0
            || (float) ($existing->precio_especial ?? 0) > 0) {
            return true;
        }

        $item = is_array($existing->raw_list) ? $existing->raw_list : [];
        $detail = is_array($existing->raw_detail) ? $existing->raw_detail : [];

        return self::hasUsdPrices(self::extractUsdPrices($item, $detail));
    }

    /**
     * Completa precios extraídos con columnas BD y JSON previos (no borrar en sync).
     *
     * @param  array{precio_lista: ?float, precio_especial: ?float, precio_descuento: ?float}  $fromPayload
     * @return array{precio_lista: ?float, precio_especial: ?float, precio_descuento: ?float}
     */
    public static function mergeWithExistingProduct(array $fromPayload, ?SyscomProduct $existing): array
    {
        if ($existing === null) {
            return $fromPayload;
        }

        $fromDb = [
            'precio_lista' => (float) ($existing->precio_lista ?? 0),
            'precio_especial' => (float) ($existing->precio_especial ?? 0),
            'precio_descuento' => (float) ($existing->precio_descuento ?? 0),
        ];
        $item = is_array($existing->raw_list) ? $existing->raw_list : [];
        $detail = is_array($existing->raw_detail) ? $existing->raw_detail : [];
        $fromRaw = self::extractUsdPrices($item, $detail);

        $out = $fromPayload;
        foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $key) {
            if ((float) ($out[$key] ?? 0) > 0) {
                continue;
            }
            if ($fromDb[$key] > 0) {
                $out[$key] = $fromDb[$key];
                continue;
            }
            if ((float) ($fromRaw[$key] ?? 0) > 0) {
                $out[$key] = $fromRaw[$key];
            }
        }

        return $out;
    }

    /**
     * Guarda listado nuevo pero conserva bloques de precio del JSON anterior si el listado viene vacío.
     *
     * @return array<string, mixed>
     */
    public static function mergeListPayload(array $item, mixed $existingRawList): array
    {
        if (! is_array($existingRawList) || $existingRawList === []) {
            return $item;
        }

        if (self::hasUsdPrices(self::extractUsdPrices($item, []))) {
            return $item;
        }

        $oldPrices = $existingRawList['precios'] ?? null;
        if (! is_array($oldPrices) || $oldPrices === []) {
            return $item;
        }

        $merged = $item;
        $merged['precios'] = $oldPrices;

        return $merged;
    }

    /**
     * @param  array{precio_lista: ?float, precio_especial: ?float, precio_descuento: ?float}  $prices
     * @return array<string, float> solo claves con valor > 0 para updateOrCreate
     */
    public static function pricesForDatabase(array $prices): array
    {
        $out = [];
        foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $pk) {
            if ((float) ($prices[$pk] ?? 0) > 0) {
                $out[$pk] = (float) $prices[$pk];
            }
        }

        return $out;
    }
}
