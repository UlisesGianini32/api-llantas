<?php

namespace App\Support;

/**
 * Parsea GET /carrito/pago.
 *
 * POST /carrito/generar:
 * - metodo_pago = forma.pue|ppd cuando existe; si no, codigo SAT (cuentas que no envían forma).
 * - tipo_pago   = pue|ppd → PDF «Método de pago».
 * - codigo SAT 04 → tarjeta de crédito → PDF «Forma de pago».
 */
class SyscomCarritoPagoHelper
{
    /**
     * @return list<array{
     *   nombre: string,
     *   titulo: ?string,
     *   codigo_sat: ?string,
     *   metodo_pago_pue: ?string,
     *   metodo_pago_ppd: ?string
     * }>
     */
    public static function flattenPaymentMethods(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $tops = array_is_list($json) ? $json : [$json];
        $out = [];

        foreach ($tops as $top) {
            if (! is_array($top)) {
                continue;
            }

            $groupName = trim((string) ($top['nombre'] ?? ''));
            $metodoBlocks = $top['metodo'] ?? null;

            if (! is_array($metodoBlocks)) {
                if ($groupName !== '') {
                    $out[] = self::row($groupName, null, null, null, null);
                }

                continue;
            }

            $blocks = array_is_list($metodoBlocks) ? $metodoBlocks : [$metodoBlocks];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                foreach ($block as $items) {
                    if (! is_array($items)) {
                        continue;
                    }
                    $list = array_is_list($items) ? $items : [$items];
                    foreach ($list as $item) {
                        if (! is_array($item)) {
                            continue;
                        }

                        [$pue, $ppd] = self::extractFormaIds($item);
                        $codigoSat = trim((string) ($item['codigo'] ?? $item['codigo_sat'] ?? ''));
                        $titulo = trim((string) ($item['titulo'] ?? ''));

                        $out[] = self::row(
                            $groupName,
                            $titulo !== '' ? $titulo : null,
                            $codigoSat !== '' ? $codigoSat : null,
                            $pue,
                            $ppd
                        );
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array{metodo_pago: string, codigo_sat: ?string, label: string, source: string}
     */
    public static function resolvePaymentForOrder(
        mixed $json,
        string $tipoPago = 'pue',
        ?string $preferName = null,
        ?string $preferCodigoSat = null
    ): array {
        $preferName = $preferName ?? trim((string) config('syscom.orders_from_meli.metodo_pago_prefer', 'tarjeta+credito'));
        $preferCodigoSat = $preferCodigoSat ?? trim((string) config('syscom.orders_from_meli.forma_pago_sat', '04'));
        $usePpd = mb_strtolower(trim($tipoPago)) === 'ppd';
        $flat = self::flattenPaymentMethods($json);

        $pickFromRow = static function (array $row) use ($usePpd): array {
            $formaId = $usePpd
                ? trim((string) ($row['metodo_pago_ppd'] ?? ''))
                : trim((string) ($row['metodo_pago_pue'] ?? ''));

            if ($formaId !== '') {
                return ['id' => $formaId, 'source' => 'forma.pue|ppd'];
            }

            $codigo = trim((string) ($row['codigo_sat'] ?? ''));
            if ($codigo !== '') {
                return ['id' => $codigo, 'source' => 'codigo_sat (API sin forma.pue)'];
            }

            return ['id' => '', 'source' => ''];
        };

        $labelOf = static fn (array $row): string => trim($row['nombre'].' '.($row['titulo'] ?? ''));

        $tryRows = static function (array $rows, bool $requirePrefer, bool $requireSat) use (
            $pickFromRow,
            $labelOf,
            $preferName,
            $preferCodigoSat
        ): array {
            $prefer = self::normalizeSearch($preferName);
            foreach ($rows as $row) {
                $label = self::normalizeSearch($labelOf($row));
                if ($requirePrefer && $prefer !== '' && ! self::labelMatchesPrefer($label, $prefer)) {
                    continue;
                }
                if ($requireSat && $preferCodigoSat !== '') {
                    $sat = trim((string) ($row['codigo_sat'] ?? ''));
                    if ($sat === '' || $sat !== $preferCodigoSat) {
                        continue;
                    }
                }
                $picked = $pickFromRow($row);
                if ($picked['id'] === '') {
                    continue;
                }

                return [
                    'metodo_pago' => $picked['id'],
                    'codigo_sat' => $row['codigo_sat'] ?? null,
                    'label' => $labelOf($row),
                    'source' => $picked['source'],
                ];
            }

            return ['metodo_pago' => '', 'codigo_sat' => null, 'label' => '', 'source' => ''];
        };

        foreach ([
            ['requirePrefer' => true, 'requireSat' => true],
            ['requirePrefer' => true, 'requireSat' => false],
            ['requirePrefer' => false, 'requireSat' => true],
        ] as $pass) {
            $hit = $tryRows($flat, $pass['requirePrefer'], $pass['requireSat']);
            if ($hit['metodo_pago'] !== '') {
                return $hit;
            }
        }

        return ['metodo_pago' => '', 'codigo_sat' => null, 'label' => '', 'source' => ''];
    }

    public static function resolveMetodoPagoIdForOrder(mixed $json, string $tipoPago = 'pue', ?string $preferName = null): string
    {
        return self::resolvePaymentForOrder($json, $tipoPago, $preferName)['metodo_pago'];
    }

    /**
     * @return array{0: ?string, 1: ?string} pue, ppd
     */
    private static function extractFormaIds(array $item): array
    {
        $pue = self::readScalar($item, 'pue', 'metodo_pago_pue', 'metodo_pago');
        $ppd = self::readScalar($item, 'ppd', 'metodo_pago_ppd');

        $formas = $item['forma'] ?? null;
        if ($formas !== null) {
            $formaList = is_array($formas) ? (array_is_list($formas) ? $formas : [$formas]) : [];
            foreach ($formaList as $forma) {
                if (! is_array($forma)) {
                    continue;
                }
                if ($pue === null && array_key_exists('pue', $forma)) {
                    $pue = self::scalarOrNull($forma['pue']);
                }
                if ($ppd === null && array_key_exists('ppd', $forma)) {
                    $ppd = self::scalarOrNull($forma['ppd']);
                }
            }
        }

        return [$pue, $ppd];
    }

    private static function readScalar(array $item, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $item)) {
                continue;
            }
            $v = self::scalarOrNull($item[$key]);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    private static function scalarOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $s = trim($value);

            return $s !== '' ? $s : null;
        }

        return null;
    }

    /**
     * @return array{nombre: string, titulo: ?string, codigo_sat: ?string, metodo_pago_pue: ?string, metodo_pago_ppd: ?string}
     */
    private static function row(
        string $nombre,
        ?string $titulo,
        ?string $codigoSat,
        ?string $pue,
        ?string $ppd
    ): array {
        return [
            'nombre' => $nombre,
            'titulo' => $titulo,
            'codigo_sat' => $codigoSat,
            'metodo_pago_pue' => $pue,
            'metodo_pago_ppd' => $ppd,
        ];
    }

    private static function normalizeSearch(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $s
        );
    }

    private static function labelMatchesPrefer(string $labelNormalized, string $preferNormalized): bool
    {
        if ($preferNormalized === '') {
            return true;
        }

        if (str_contains($preferNormalized, '+')) {
            foreach (explode('+', $preferNormalized) as $token) {
                $token = trim($token);
                if ($token !== '' && ! str_contains($labelNormalized, $token)) {
                    return false;
                }
            }

            return true;
        }

        return str_contains($labelNormalized, $preferNormalized);
    }
}
