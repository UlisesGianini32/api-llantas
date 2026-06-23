<?php

namespace App\Services;

use App\Models\Llanta;
use App\Models\ProductoCompuesto;

class TitleGenerator
{
    public static function llanta(Llanta $llanta): string
    {
        $medida = trim((string) $llanta->medida);

        // Marca: si viene GENERICA o vacía, intenta detectarla desde la descripción
        $marca = self::brandFromLlanta($llanta);

        $desc  = self::limpiarDescripcion((string) $llanta->descripcion);

        return trim('LLANTA ' . strtoupper(trim("$medida $marca $desc")));
    }

    public static function compuesto(ProductoCompuesto $compuesto): string
    {
        // En tu BD: tipo = par | juego4
        $cantidad = $compuesto->tipo === 'juego4' ? 4 : 2;

        $llanta = $compuesto->llanta;

        $medida = trim((string) optional($llanta)->medida);

        // Marca desde llanta (con detección en descripción si es GENERICA)
        $marca = self::brandFromText(
            (string) optional($llanta)->marca,
            (string) optional($llanta)->descripcion
        );

        // Descripción: usa la del compuesto si existe; si no, usa la de la llanta
        $descRaw = $compuesto->descripcion ?: (optional($llanta)->descripcion ?? '');
        $desc    = self::limpiarDescripcion((string) $descRaw);

        return trim($cantidad . ' PACK DE LLANTAS ' . strtoupper(trim("$medida $marca $desc")));
    }

    /**
     * Marca para llanta, usando la columna marca + fallback a descripción
     */
    private static function brandFromLlanta(Llanta $llanta): string
    {
        return self::brandFromText((string) $llanta->marca, (string) $llanta->descripcion);
    }

    /**
     * Si marca es GENERICA/vacía, intenta detectar marca dentro del texto (descripción).
     */
    private static function brandFromText(string $marcaDb, string $descripcion): string
    {
        $marcaDb = strtoupper(trim($marcaDb));
        $descripcion = strtoupper((string) $descripcion);

        if ($marcaDb === '' || $marcaDb === 'GENERICA') {
            $detectada = self::detectBrand($descripcion);
            if ($detectada) return $detectada;
        }

        return $marcaDb !== '' ? $marcaDb : 'GENERICA';
    }

    /**
     * Detector simple por lista. Aquí puedes ir agregando marcas.
     */
    public static function detectBrand(string $text): ?string
    {
        $brands = [
            'JK TYRE',
            'GOODYEAR',
            'BFGOODRICH',
            'BLACKHAWK',
            'GREENTRAC',
            'FULLRUN',
            'POWERCITY',
            'PEGASUS',
            'ILINK',
            'HAIDA',
            'WINRUN',
            'ANTARES',
            'TRIANGLE',
            'SAILUN',
            'MAXXIS',
            'LCH',
            'MINNELL',
            'KUMHO',
            'YOKOHAMA',
            'MICHELIN',
            'CONTINENTAL',
            'BRIDGESTONE',
            'PIRELLI',
            'HANKOOK',
            'TOYO',
            'NITTO',
            'FALKEN',
			'ACCELERA','AGATE','ALFAMOTORS','AMULET','ANSU','ANTARES','ARCRON',
        'ARDENT','ARDUZZA','ATLAS','BCT','BFGOODRICH','BLACKARROW','BLACKHAWK',
        'BRIDGESTONE','BROADPEAK','COOPER','DOUBLESTAR','DUNLOP','FEDERAL',
        'FIRESTONE','FORERUNNER','FRONWAY','FULLRUN','GOODRIDE','GOODYEAR',
        'GREENTRAC','GUTEROAD','HAIDA','ILINK','LANDY','MAXTREK','MICHELIN',
        'NITTO','PEGASUS','PIRELLI','SAILUN','TRIANGLE','WINRUN','YOKOHAMA',
        'FORCELAND','GRANDSTONE','HALBERD','HAULKING','MINNELL','LCH',
        'POWERTRAC','BLACKLION','COMPASAL','HIFLY','ROADKING','SAFETY',
        'SUNFULL','TORQUE','WESTLAKE','ZEETEX','ROCKBLADE','KAPSEN'
        ];

        foreach ($brands as $b) {
            if (preg_match('/\b' . preg_quote($b, '/') . '\b/', $text)) {
                return $b;
            }
        }

        return null;
    }

    private static function limpiarDescripcion(string $texto): string
    {
        $texto = preg_replace('/\s+/', ' ', trim($texto));
        return $texto ?? '';
    }
}
