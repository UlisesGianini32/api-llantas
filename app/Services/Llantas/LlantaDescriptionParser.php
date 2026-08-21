<?php

namespace App\Services\Llantas;

class LlantaDescriptionParser
{
    public function parse(string $description): array
    {
        $normalized = $this->normalize($description);

        $medida = $this->extractSize($normalized);
        $marca = $this->extractBrand($normalized);

        return [
            'normalized' => $normalized,
            'marca' => $marca,
            'medida' => $medida,

            'load_index' => $this->extractLoadIndex($normalized),
            'speed_index' => $this->extractSpeedIndex($normalized),
            'ply_rating' => $this->extractPlyRating($normalized),
            'construction' => $this->extractConstruction($normalized),
            'volume_ml' => $this->extractVolumeMl($normalized),
            'category' => $this->extractCategory($normalized),
            'is_xl' => $this->containsToken($normalized, 'XL'),
            'is_lt' => $this->containsLt($normalized),
            'is_rwl' => $this->containsToken($normalized, 'RWL'),

            'tokens' => $this->importantTokens(
                description: $normalized,
                marca: $marca,
                medida: $medida
            ),
        ];
    }

    public function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value));

        $value = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N'],
            $value
        );

        /*
         * Unifica ciertas variantes habituales:
         *
         * 245/35ZR19  -> 245/35R19
         * 185 R15 C   -> 185R15C
         */
        $value = preg_replace(
            '/(\d{2,3}\/\d{2})ZR(\d{2}(?:\.5)?)/',
            '$1R$2',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/\b(\d{2,3})\s+R\s*(\d{2}(?:\.5)?)(C?)\b/',
            '$1R$2$3',
            $value
        ) ?? $value;

        $value = preg_replace('/[^A-Z0-9\.\/\-]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function extractBrand(string $description): string
    {
        $brands = config('llantas.known_brands', []);

        /*
         * Ordena las marcas de mayor a menor longitud para evitar que una
         * marca corta coincida antes que una marca compuesta.
         */
        usort($brands, static function (string $a, string $b): int {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        foreach ($brands as $brand) {
            $normalizedBrand = $this->normalize($brand);

            if ($this->containsPhrase($description, $normalizedBrand)) {
                return $normalizedBrand;
            }
        }

        return 'GENERICA';
    }

    private function extractSize(string $description): string
    {
        $patterns = [
            /*
             * 245/35R19
             * 295/75R22.5
             */
            '/\b\d{3}\/\d{2}R\d{2}(?:\.5)?\b/',

            /*
             * 12.5/80-18
             * 16.9/24-28
             */
            '/\b\d{1,2}(?:\.\d+)?\/\d{2}-\d{2}(?:\.5)?\b/',

            /*
             * 185R15C
             * 195R15
             */
            '/\b\d{3}R\d{2}(?:\.5)?C?\b/',

            /*
             * 11R22.5
             * 12R22.5
             */
            '/\b\d{2}R\d{2}(?:\.5)?\b/',

            /*
             * 20.5-25
             * 17.5-25
             */
            '/\b\d{1,2}(?:\.\d+)?-\d{2}(?:\.5)?\b/',

            /*
             * 27X9-12
             * 27X9R12
             * 30X10.00R14
             */
            '/\b\d{2,3}X\d{1,2}(?:\.\d{1,2})?(?:R|-)\d{2}(?:\.5)?\b/',

            /*
             * 14.00-24
             */
            '/\b\d{1,2}\.\d{2}-\d{2}\b/',

            /*
             * 10.00/20R20
             */
            '/\b\d{2}\.\d\/\d{2}R\d{2}\b/',

            /*
             * 750-16
             */
            '/\b\d{3}\/?\d{0,2}-\d{2}(?:\.5)?\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $match)) {
                return $this->normalizeSize($match[0]);
            }
        }

        return 'N/A';
    }

    private function normalizeSize(string $size): string
    {
        $size = mb_strtoupper(trim($size));
        $size = str_replace('ZR', 'R', $size);
        $size = preg_replace('/\s+/', '', $size) ?? $size;

        return $size;
    }

    private function extractLoadIndex(string $description): ?string
    {
        /*
         * Ejemplos:
         * 121/118Q
         * 149/146L
         */
        if (preg_match(
            '/\b(\d{2,3}\/\d{2,3})(?=[A-Z]|\s|$)/',
            $description,
            $match
        )) {
            return $match[1];
        }

        /*
         * Ejemplos:
         * 115T
         * 93W
         * 100Y
         *
         * Se evita confundir números que forman parte de una medida.
         */
        if (preg_match_all(
            '/\b(\d{2,3})([A-Z])\b/',
            $description,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $number = (int) $match[1];
                $letter = $match[2];

                if (
                    $number >= 50
                    && $number <= 200
                    && !in_array($letter, ['C', 'R'], true)
                ) {
                    return (string) $number;
                }
            }
        }

        return null;
    }

    private function extractSpeedIndex(string $description): ?string
    {
        /*
         * 121/118Q
         */
        if (preg_match(
            '/\b\d{2,3}\/\d{2,3}([A-Z])\b/',
            $description,
            $match
        )) {
            return $match[1];
        }

        /*
         * 115T, 93W, 100Y, 84H
         */
        if (preg_match_all(
            '/\b(\d{2,3})([A-Z])\b/',
            $description,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $number = (int) $match[1];
                $letter = $match[2];

                if (
                    $number >= 50
                    && $number <= 200
                    && !in_array($letter, ['C', 'R'], true)
                ) {
                    return $letter;
                }
            }
        }

        return null;
    }

    private function extractPlyRating(string $description): ?int
    {
        /*
         * Ejemplos:
         * -8C
         * -10C
         * -16C
         * 20C
         *
         * No toma la C de 185R15C como número de capas.
         */
        $patterns = [
            '/(?:^|\s|-)(\d{1,2})C(?:\s|$)/',
            '/\bPR\s*(\d{1,2})\b/',
            '/\b(\d{1,2})\s*PR\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $match)) {
                $value = (int) $match[1];

                if ($value >= 2 && $value <= 30) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractConstruction(string $description): ?string
    {
        if ($this->containsToken($description, 'TL')) {
            return 'TL';
        }

        if ($this->containsToken($description, 'TT')) {
            return 'TT';
        }

        return null;
    }

    private function extractVolumeMl(string $description): ?int
    {
        if (preg_match('/\b(\d{1,5})\s*ML\b/', $description, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractCategory(string $description): ?string
    {
        $categories = [
            'AUTO',
            'CAMION',
            'CAMIONETA',
            'ATV',
            'UTV',
            'OTR',
            'AGRICOLA',
            'MOTO',
            'INDUSTRIAL',
        ];

        foreach ($categories as $category) {
            if ($this->containsToken($description, $category)) {
                return $category;
            }
        }

        return null;
    }

    private function containsLt(string $description): bool
    {
        return $this->containsToken($description, 'LT')
            || preg_match('/\bLT\d/', $description) === 1;
    }

    private function importantTokens(
        string $description,
        string $marca,
        string $medida
    ): array {
        $clean = $description;

        if ($marca !== 'GENERICA') {
            $clean = str_replace($marca, ' ', $clean);
        }

        if ($medida !== 'N/A') {
            $clean = str_replace($medida, ' ', $clean);
        }

        /*
         * Elimina atributos técnicos que ya se comparan por separado.
         */
        $clean = preg_replace('/\b\d{2,3}\/\d{2,3}[A-Z]?\b/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b\d{2,3}[A-Z]\b/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/(?:^|\s|-)\d{1,2}C(?:\s|$)/', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b\d{1,5}\s*ML\b/', ' ', $clean) ?? $clean;

        $stop = [
            'LLANTA',
            'AUTO',
            'CAMION',
            'CAMIONETA',
            'ATV',
            'UTV',
            'OTR',
            'AGRICOLA',
            'MOTO',
            'INDUSTRIAL',
            'RWL',
            'XL',
            'TL',
            'TT',
            'LT',
            'VIETNAM',
            'MALASIA',
            'CHINA',
            'RADIAL',
        ];

        $tokens = preg_split('/\s+/', trim($clean)) ?: [];

        $tokens = array_filter(
            $tokens,
            static function (string $token) use ($stop): bool {
                return mb_strlen($token) >= 2
                    && !in_array($token, $stop, true)
                    && !preg_match('/^\d+$/', $token);
            }
        );

        return array_values(array_unique($tokens));
    }

    private function containsToken(string $description, string $token): bool
    {
        return preg_match(
            '/(?:^|[\s\-\/])' . preg_quote($token, '/') . '(?:$|[\s\-\/])/',
            $description
        ) === 1;
    }

    private function containsPhrase(string $description, string $phrase): bool
    {
        return str_contains(
            ' ' . $description . ' ',
            ' ' . $phrase . ' '
        );
    }
}