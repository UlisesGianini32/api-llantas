<?php

namespace App\Support;

/**
 * Lee precios USD de respuestas SYSCOM (columnas persistidas o JSON raw_list/raw_detail).
 */
class SyscomPrecioExtractor
{
    /**
     * @return array{precio_lista: float, precio_especial: float, precio_descuento: float}
     */
    public static function fromProductLike(array $item, ?array $detail = null): array
    {
        $detail = $detail ?? [];
        $prices = self::extractSyscomPrecios($item, $detail);

        return [
            'precio_lista' => (float) ($prices['precio_lista'] ?? 0),
            'precio_especial' => (float) ($prices['precio_especial'] ?? 0),
            'precio_descuento' => (float) ($prices['precio_descuento'] ?? 0),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $item
     * @param  array<int|string, mixed>  $detail
     * @return array{precio_lista: ?float, precio_especial: ?float, precio_descuento: ?float}
     */
    public static function extractSyscomPrecios(array $item, array $detail): array
    {
        $lista = self::firstNumeric(
            self::readPrecioField($detail, 'precio_lista', 'precio_de_lista', 'list_price', 'precio'),
            self::readPrecioField($item, 'precio_lista', 'precio_de_lista', 'list_price', 'precio'),
            self::deepFindPrecio($detail, 'precio_lista', 'precio_de_lista', 'list_price'),
            self::deepFindPrecio($item, 'precio_lista', 'precio_de_lista', 'list_price')
        );
        $especial = self::firstNumeric(
            self::readPrecioField($detail, 'precio_especial', 'precio_venta', 'venta', 'pventa', 'importe_venta', 'precio_especial_tc'),
            self::readPrecioField($item, 'precio_especial', 'precio_venta', 'venta', 'pventa', 'importe_venta', 'precio_especial_tc'),
            self::deepFindPrecio($detail, 'precio_especial', 'precio_venta', 'venta', 'importe_venta'),
            self::deepFindPrecio($item, 'precio_especial', 'precio_venta', 'venta', 'importe_venta')
        );
        $desc = self::firstNumeric(
            self::readPrecioField($detail, 'precio_descuento', 'precio_descuentos', 'ultimo_costo', 'costo', 'desde', 'precio_descuento_tc'),
            self::readPrecioField($item, 'precio_descuento', 'precio_descuentos', 'ultimo_costo', 'costo', 'desde', 'precio_descuento_tc'),
            self::deepFindPrecio($detail, 'precio_descuento', 'precio_descuentos', 'ultimo_costo', 'costo', 'desde'),
            self::deepFindPrecio($item, 'precio_descuento', 'precio_descuentos', 'ultimo_costo', 'costo', 'desde')
        );

        return [
            'precio_lista' => $lista,
            'precio_especial' => $especial,
            'precio_descuento' => $desc,
        ];
    }

    /**
     * Recorre `precios` anidado (varios niveles) por si la API cambió la forma del JSON.
     *
     * @param  array<int|string, mixed>  $src
     */
    public static function deepFindPrecio(array $src, string ...$keys): ?float
    {
        $found = [];
        $walk = function (mixed $node, int $depth) use (&$walk, &$found, $keys): void {
            if ($depth > 6 || ! is_array($node)) {
                return;
            }
            foreach ($keys as $key) {
                if (array_key_exists($key, $node)) {
                    $n = self::numericOrNull($node[$key]);
                    if ($n !== null && $n > 0) {
                        $found[] = $n;
                    }
                }
            }
            foreach ($node as $k => $v) {
                if ($k === 'imagenes' || $k === 'existencia' || $k === 'descripcion') {
                    continue;
                }
                $walk($v, $depth + 1);
            }
        };
        $walk($src['precios'] ?? $src, 0);

        return $found === [] ? null : (float) max($found);
    }

    /**
     * @param  ?float  ...$candidates
     */
    public static function firstNumeric(?float ...$candidates): ?float
    {
        foreach ($candidates as $c) {
            if ($c !== null && (float) $c > 0) {
                return (float) $c;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $src
     */
    public static function readPrecioField(array $src, string ...$keys): ?float
    {
        if ($keys === []) {
            return null;
        }

        $blocks = self::mergePrecioBlocks($src);
        foreach ($keys as $key) {
            if (array_key_exists($key, $blocks)) {
                $n = self::numericOrNull($blocks[$key] ?? null);
                if ($n !== null && $n > 0) {
                    return $n;
                }
            }
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $src) && $key !== 'precios') {
                $n = self::numericOrNull($src[$key] ?? null);
                if ($n !== null && $n > 0) {
                    return $n;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $src
     * @return array<string, mixed>
     */
    public static function mergePrecioBlocks(array $src): array
    {
        $b = is_array($src['precios'] ?? null) ? $src['precios'] : [];
        $out = [];
        foreach ($b as $k => $v) {
            if (is_array($v) && $v !== []) {
                foreach ($v as $k2 => $v2) {
                    if (! is_array($v2)) {
                        $out[$k2] = $v2;
                    } elseif ($v2 !== []) {
                        foreach ($v2 as $k3 => $v3) {
                            if (! is_array($v3)) {
                                $out[$k3] = $v3;
                            }
                        }
                    }
                }
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public static function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            return self::parseMoneyString($value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    public static function parseMoneyString(string $value): ?float
    {
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        $s = str_replace(['$', 'MXN', 'mxn', "\xc2\xa0", "\xa0", ' '], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace(',', '', $s);
        } elseif (preg_match('/^\d+,\d{1,2}$/', $s)) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}
