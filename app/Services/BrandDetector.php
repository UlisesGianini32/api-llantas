<?php

namespace App\Services;

class BrandDetector
{
    /**
     * Detecta marca a partir de una descripción (texto libre).
     * Si no encuentra, regresa null.
     */
    public static function detect(?string $text): ?string
    {
        if (!$text) return null;

        // Normaliza: mayúsculas y espacios
        $t = strtoupper(trim($text));
        $t = preg_replace('/\s+/', ' ', $t);

        // Normaliza guiones a espacio para casos tipo GOOD-YEAR
        $t2 = str_replace(['-', '_'], ' ', $t);
        $t2 = preg_replace('/\s+/', ' ', $t2);

        // ✅ Sinónimos / casos especiales
        // GOOD YEAR -> GOODYEAR
        if (preg_match('/\bGOOD\s+YEAR\b/', $t2)) return 'GOODYEAR';

        // GUTE ROAD viene a veces como GUTEROAD en tu lista vieja
        if (preg_match('/\bGUTE\s+ROAD\b/', $t2)) return 'GUTE ROAD';

        // JK TYRE (por si viene JKTYRE pegado)
        if (preg_match('/\bJK\s*TYRE\b/', $t2)) return 'JK TYRE';

        // Lista grande (puedes seguir agregando)
        $brands = [
            // compuestas primero
            'COOPER TIRES',
            'GENERAL TIRE',
            'GUTE ROAD',
            'JK TYRE',

            // marcas comunes
            'BFGOODRICH',
            'BLACKARROW',
            'BLACKHAWK',
            'BLACKLION',
            'BRIDGESTONE',
            'BROADPEAK',
            'COMPASAL',
            'CONTINENTAL',
            'COOPER',
            'DOUBLESTAR',
            'DUNLOP',
            'FALKEN',
            'FEDERAL',
            'FIRESTONE',
            'FORCELAND',
            'FORERUNNER',
            'FRONWAY',
            'FULLRUN',
            'GOODRIDE',
            'GOODYEAR',
            'GRANDSTONE',
            'GREENTRAC',
            'HAIDA',
            'HALBERD',
            'HANKOOK',
            'HAULKING',
            'HIFLY',
            'ILINK',
            'KAPSEN',
            'KUMHO',
            'LANDY',
            'LCH',
            'MAXTREK',
            'MAXXIS',
            'MICHELIN',
            'MINNELL',
            'NEXEN',
            'NITTO',
            'PEGASUS',
            'PIRELLI',
            'POWERTRAC',
            'POWERCITY',
            'ROADKING',
            'ROCKBLADE',
            'SAFETY',
            'SAILUN',
            'SUNFULL',
            'TORQUE',
            'TRIANGLE',
            'WESTLAKE',
            'WINRUN',
            'YOKOHAMA',
            'ZEETEX',

            // del Excel / adicionales
            'ACCELERA',
            'AGATE',
            'ALFAMOTORS',
            'AMULET',
            'ANSU',
            'ANTARES',
            'ARCRON',
            'ARDENT',
            'ARDUZZA',
            'ATLAS',
            'BCT',
			'MAZZINI',
			'TECHSHIELD',
			'MILEVER',
			'SUMAXX',
			'SUNEW',
			'MILEKING',
			'TDI TIRES',
			'TERAFLEX',
			'EPSILON',
			'SAFERICH',
			'TORNEL',
			'NOVAMAXX',
			'SURETRAC',
			'GRIT MASTER',
			'YUSTA',
			'TERCELO',
			'YEADA',
			'JOYROAD',
			'MIRAGE',
			'EAGLE',
			'VOLCATO',
			'KELLY',
			'GALLANT',
			'RACEALONE',
			'PROLOAD',
			'ECOSAVER',
			'AMULETAD',
			'WANLI',
			'DSTAR',
        ];

        // ✅ Ordena por longitud (para que "COOPER TIRES" gane antes que "COOPER")
        usort($brands, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($brands as $b) {
            // Busca como palabra completa dentro del texto normalizado con guiones
            $pattern = '/\b' . preg_quote($b, '/') . '\b/';
            if (preg_match($pattern, $t2)) {
                return $b;
            }
        }

        return null;
    }
}
