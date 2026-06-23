<?php

namespace App\Support;

/**
 * Stock en una sucursal SYSCOM a partir del bloque `existencia` del detalle de producto.
 *
 * Formatos reales reportados por la API:
 *
 * - Por nombre de sucursal (lo más comun en /productos/{id}):
 *   { "hermosillo": 5, "mexico": 0, "tijuana": 2 }
 *
 * - Por nombre con estados (nuevo / asterisco / caja abierta):
 *   { "hermosillo": { "nuevo": 5, "asterisco": 1 } }
 *
 * - Estados primero, luego sucursal:
 *   { "nuevo": { "hermosillo": 5, "tijuana": 2 }, "asterisco": { "hermosillo": 1 } }
 *
 * - Por codigo numérico de sucursal (poco común):
 *   { "1": 5, "8": 2 } o { "1": { "existencia": 5 } }
 *
 * - Lista de filas con metadatos:
 *   [ { "codigo": "1", "nombre_sucursal": "hermosillo", "existencia": 5 }, ... ]
 *
 * - Envuelto en una clave contenedora:
 *   { "sucursales": { ... } } o { "detalle": { ... } }
 */
class SyscomHermosilloStock
{
    /**
     * Claves que NO son nombres ni códigos de sucursal: representan condiciones / calidades
     * (nuevo, asterisco con subgrados a/b/c/d, etc.). Si todas las top-keys del bloque
     * `existencia` caen aquí, el bloque ya viene acotado a UNA sucursal y sólo hay que sumarlo.
     */
    private const STATUS_KEYS = [
        'nuevo',
        'asterisco',
        'caja_abierta',
        'caja',
        'abierta',
        'detalle',
        'inventario',
        'inventario_disponible',
        'inventario_cd',
        'disponible',
        'disponible_sucursal',
        'cantidad',
        'stock',
        'existencia',
        'existencia_total',
        'existencia_sucursal',
        'total',
        'total_existencia',
        'a',
        'b',
        'c',
        'd',
    ];

    /**
     * Resuelve cantidad para la sucursal (código + nombre como respaldo).
     */
    public static function forBranch(
        mixed $existencia,
        string $branchCode,
        string $branchNameLabel = '',
        bool $trustBranchScopedBlock = false
    ): int {
        $data = self::unwrapExistencia($existencia);
        if ($data === null) {
            return 0;
        }

        if (self::isListArray($data)) {
            $listSum = self::fromRowList($data, $branchCode, $branchNameLabel);
            if ($listSum !== null) {
                return $listSum;
            }
        }

        $needles = [];
        if ($branchCode !== '') {
            $needles[] = mb_strtolower(trim($branchCode));
        }
        if ($branchNameLabel !== '') {
            $needles[] = mb_strtolower(trim($branchNameLabel));
        }
        $needles = array_values(array_unique(array_filter($needles, static fn (string $n) => $n !== '')));

        if ($needles !== []) {
            $found = false;
            $sum = self::sumByBranchKey($data, $needles, $found);
            if ($found) {
                return max(0, $sum);
            }
        }

        // Solo confiar en un bloque status-only ({nuevo, asterisco, detalle, ...}) cuando el
        // caller AFIRMA que la respuesta vino realmente acotada a la sucursal pedida (típicamente
        // un detalle traído con ?sucursal=<code> y SYSCOM respetando el filtro). Nunca trusteamos
        // por config global: en cuentas donde la API ignora ?sucursal el bloque es NACIONAL y al
        // confiar en él terminamos publicando stock que no existe en la sucursal local.
        if ($trustBranchScopedBlock && self::looksLikeBranchScopedBlock($data)) {
            return self::sumNumericLeaves($data);
        }

        return 0;
    }

    /**
     * Stock Hermosillo (u otra sucursal config) a partir del detalle SYSCOM.
     * Nunca usa total_existencia nacional cuando import_only_hermosillo_stock está activo.
     */
    public static function fromProductDetail(
        mixed $existencia,
        string $branchCode,
        string $branchNameLabel,
        int $totalExistencia = 0,
        bool $trustBranchScopedBlock = false
    ): int {
        $existArray = is_array($existencia) ? $existencia : [];
        $stock = self::forBranch($existencia, $branchCode, $branchNameLabel, $trustBranchScopedBlock);

        if ($stock > 0) {
            return $stock;
        }

        if ($existArray !== [] || $totalExistencia <= 0) {
            return 0;
        }

        if (self::importOnlyHermosilloStock()) {
            return 0;
        }

        return max(0, $totalExistencia);
    }

    /**
     * @deprecated Usar {@see forBranch}
     */
    public static function fromExistencia(mixed $existencia, string $branchCode): int
    {
        return self::forBranch($existencia, $branchCode, '');
    }

    /**
     * @deprecated Usar {@see forBranch}
     */
    public static function fromExistenciaByName(mixed $existencia, string $nameContains): int
    {
        return self::forBranch($existencia, '', $nameContains);
    }

    private static function unwrapExistencia(mixed $existencia): ?array
    {
        if (! is_array($existencia) || $existencia === []) {
            return null;
        }

        foreach (['sucursales', 'detalle', 'por_sucursal', 'inventario_sucursal', 'almacenes', 'inventario'] as $k) {
            if (isset($existencia[$k]) && is_array($existencia[$k]) && $existencia[$k] !== []) {
                return $existencia[$k];
            }
        }

        return $existencia;
    }

    private static function importOnlyHermosilloStock(): bool
    {
        if (! function_exists('config')) {
            return true;
        }

        return (bool) config('syscom.import_only_hermosillo_stock', true);
    }

    /**
     * Suma recursiva: cuando una clave del árbol matchea con `needles`, contabiliza todos
     * los valores numéricos a partir de ese nodo. Si NO matchea aún, sigue descendiendo.
     *
     * Esto permite manejar `{"nuevo": {"hermosillo": 5}}`, `{"hermosillo": {"nuevo": 5}}`,
     * `{"hermosillo": 5}` y mezclas con un solo recorrido.
     *
     * @param  array<int|string, mixed>  $data
     * @param  array<int, string>  $needles
     */
    private static function sumByBranchKey(array $data, array $needles, bool &$found): int
    {
        $sum = 0;

        foreach ($data as $key => $value) {
            $keyMatch = self::keyMatchesAnyNeedle((string) $key, $needles);

            if ($keyMatch) {
                $found = true;
                $sum += self::sumNumericLeaves($value);
                continue;
            }

            if (is_array($value) && $value !== []) {
                $sum += self::sumByBranchKey($value, $needles, $found);
            }
        }

        return $sum;
    }

    /**
     * Suma de todos los valores numéricos hoja dentro de un nodo (escalares, recursivo).
     */
    private static function sumNumericLeaves(mixed $value): int
    {
        if (is_array($value)) {
            $sum = 0;
            foreach ($value as $v) {
                $sum += self::sumNumericLeaves($v);
            }

            return $sum;
        }

        if (is_numeric($value)) {
            $n = (int) $value;

            return $n > 0 ? $n : 0;
        }

        return 0;
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private static function looksLikeBranchScopedBlock(array $data): bool
    {
        if ($data === []) {
            return false;
        }

        foreach (array_keys($data) as $k) {
            $kl = mb_strtolower(trim((string) $k));
            if ($kl === '') {
                return false;
            }
            if (! in_array($kl, self::STATUS_KEYS, true)) {
                return false;
            }
        }

        return true;
    }

    private static function keyMatchesAnyNeedle(string $key, array $needles): bool
    {
        $k = mb_strtolower(trim($key));
        if ($k === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if ($k === $needle) {
                return true;
            }
            if (is_numeric($k) && is_numeric($needle) && (float) $k === (float) $needle) {
                return true;
            }
            if (! is_numeric($k) && ! is_numeric($needle) && str_contains($k, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lista de filas con codigo / nombre de sucursal.
     * Pueden venir varias filas para la misma sucursal (inventario + caja abierta) → se suman.
     *
     * @param  array<int|string, mixed>  $data
     */
    private static function fromRowList(array $data, string $branchCode, string $branchNameLabel): ?int
    {
        if (! self::isListArray($data)) {
            return null;
        }

        $needles = array_values(array_filter([
            mb_strtolower(trim($branchCode)),
            mb_strtolower(trim($branchNameLabel)),
        ], static fn (string $n) => $n !== ''));

        if ($needles === []) {
            return null;
        }

        $sum = 0;
        $matched = false;
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = (string) ($row['codigo'] ?? $row['id_sucursal'] ?? $row['sucursal_id'] ?? $row['clave_sucursal'] ?? $row['codigo_sucursal'] ?? '');
            $name = (string) ($row['nombre_sucursal'] ?? $row['nombre'] ?? $row['sucursal'] ?? '');

            $rowKeys = array_filter([
                mb_strtolower(trim($code)),
                mb_strtolower(trim($name)),
            ], static fn (string $n) => $n !== '');

            if ($rowKeys === []) {
                continue;
            }

            $hit = false;
            foreach ($rowKeys as $rk) {
                foreach ($needles as $n) {
                    if ($rk === $n) {
                        $hit = true;
                        break 2;
                    }
                    if (is_numeric($rk) && is_numeric($n) && (float) $rk === (float) $n) {
                        $hit = true;
                        break 2;
                    }
                    if (! is_numeric($rk) && ! is_numeric($n) && str_contains($rk, $n)) {
                        $hit = true;
                        break 2;
                    }
                }
            }

            if (! $hit) {
                continue;
            }

            $matched = true;
            $sum += self::qtyFromRow($row);
        }

        return $matched ? max(0, $sum) : null;
    }

    /**
     * Suma cantidades de una fila: normal + caja abierta, nuevo, etc.
     *
     * @param  array<string, mixed>  $row
     */
    private static function qtyFromRow(array $row): int
    {
        $qtyKeys = [
            'inventario',
            'inventario_disponible',
            'inventario_cd',
            'existencia',
            'caja_abierta',
            'caja',
            'abierta',
            'nuevo',
            'asterisco',
            'disponible',
            'disponible_sucursal',
            'cantidad',
            'stock',
        ];

        $sum = 0;
        $any = false;
        foreach ($qtyKeys as $k) {
            if (! array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if (is_numeric($v)) {
                $sum += max(0, (int) $v);
                $any = true;
            } elseif (is_array($v)) {
                $sum += self::sumNumericLeaves($v);
                $any = true;
            }
        }

        if ($any) {
            return max(0, $sum);
        }

        return self::sumNumericLeaves($row);
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private static function isListArray(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_is_list($data);
    }
}
