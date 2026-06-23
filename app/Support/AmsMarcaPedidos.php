<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Orden fijo de marcas para AMS "Procesar" (primera coincidencia en título/SKU gana).
 */
final class AmsMarcaPedidos
{
    public const UNKNOWN_INDEX = 9999;

    public const UNKNOWN_LABEL = 'Sin clasificar';

    /**
     * @var list<array{label: string, aliases: list<string>}>
     */
    private const MARCAS = [
        ['label' => 'Alfaparf', 'aliases' => [
            'alfaparf',
            'alfa parf',
            'alfa-parf',
            'alfa parf milano',
            // Línea Yellow / Liss Therapy / kits y duos (título o SKU tipo KITYELLOW10)
            'yellow liss',
            'liss therapy',
            'liss therapy yellow',
            'kityellow',
        ]],
        ['label' => 'Nioxin', 'aliases' => ['nioxin']],
        ['label' => "L'Oréal", 'aliases' => ['loreal', "l'oreal", 'loreal paris', 'elvive', 'excellence loreal', 'preference loreal', 'inforcer', 'serie expert', 'serieexpert']],
        ['label' => 'Tec Italy', 'aliases' => ['tec italy', 'tecitaly', 'tec-italy', 'tec italia']],
        ['label' => 'Blondme', 'aliases' => ['blondme', 'blond me', 'blond-me']],
        ['label' => 'Igora', 'aliases' => ['igora', 'igor royal', 'schwarzkopf igora']],
        ['label' => 'OSiS+', 'aliases' => ['osis', 'osis+', 'osis plus']],
        ['label' => 'Bonacure', 'aliases' => ['bonacure', 'bc bonacure', 'b.c. bonacure']],
        ['label' => 'Good Bye Yellow', 'aliases' => ['good bye yellow', 'goodbye yellow', 'good-bye yellow', 'good bye yelow', 'gby ', ' gby']],
        ['label' => 'Salerm', 'aliases' => ['salerm']],
        ['label' => 'Crioxidil', 'aliases' => ['crioxidil', 'crioxydil']],
        ['label' => 'Alea', 'aliases' => ['alea']],
        ['label' => 'Davines', 'aliases' => ['davines']],
        ['label' => 'Lendan', 'aliases' => ['lendan']],
        ['label' => 'Authentic Beauty Concept', 'aliases' => ['authentic beauty concept', 'authentic beauty', 'abc authentic']],
        ['label' => 'The Ordinary', 'aliases' => ['the ordinary', 'theordinary']],
        ['label' => 'CeraVe', 'aliases' => ['cerave', 'cera ve', 'cera-ve']],
        ['label' => 'Paul Mitchell', 'aliases' => ['paul mitchell', 'paulmitchell']],
        ['label' => 'Tea Tree', 'aliases' => ['tea tree', 'teatree', 'tea-tree']],
        ['label' => 'Joico', 'aliases' => ['joico']],
        ['label' => 'Wella', 'aliases' => ['wella', 'koleston', 'color touch', 'illumina', 'color fresh']],
        ['label' => 'Sevich', 'aliases' => ['sevich']],
        ['label' => 'Dexe', 'aliases' => ['dexe']],
        ['label' => "Dee'Lash", 'aliases' => ["dee'lash", 'deelash', 'dee lash', 'dee-lash']],
        ['label' => 'Degree', 'aliases' => ['degree']],
        ['label' => 'Kirkland', 'aliases' => ['kirkland']],
        ['label' => 'Capiluxe', 'aliases' => ['capiluxe', 'capi luxe', 'capi-luxe']],
        ['label' => 'J.Denis', 'aliases' => ['j.denis', 'j denis', 'j-denis', 'jdenis', 'j. denis']],
        ['label' => 'BaByliss', 'aliases' => ['babyliss', 'baby liss', 'baby-liss', 'babylis']],
        ['label' => 'Horacio Lares', 'aliases' => ['horacio lares', 'horaciolares', 'horacio lare']],
    ];

    /**
     * @return array{0: int, 1: string} [índice de orden, etiqueta]
     */
    public static function resolve(string $titulo, string $sku = ''): array
    {
        $haystack = self::normalizeHaystack($titulo.' '.$sku);

        foreach (self::MARCAS as $index => $row) {
            foreach ($row['aliases'] as $alias) {
                $needle = self::normalizeHaystack($alias);
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return [$index, $row['label']];
                }
            }
        }

        return [self::UNKNOWN_INDEX, self::UNKNOWN_LABEL];
    }

    public static function normalizeHaystack(string $text): string
    {
        $s = Str::lower(Str::ascii(trim($text)));
        $s = preg_replace('/[\s_\-]+/u', ' ', $s) ?? $s;

        return ' '.trim($s).' ';
    }
}
