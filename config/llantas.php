<?php

return [
    /*
     * Desde este puntaje se guarda un candidato para revisión.
     */
    'candidate_min_score' => 86.0,

    /*
     * Para crear un alias automático se exige prácticamente una
     * coincidencia exacta y sin diferencias técnicas.
     */
    'auto_alias_min_score' => 99.5,

    /*
     * Configuración del buscador global de duplicados.
     */
    'duplicate_detector' => [
        'minimum_score' => 86.0,
        'exact_score' => 99.5,
        'default_limit' => 200,
    ],

    'known_brands' => [
        'FIRESTONE',
        'BRIDGESTONE',
        'BLACKHAWK',
        'MAXTREK',
        'FORERUNNER',
        'BROADPEAK',
        'TECHSHIELD',
        'SUMAXX',
        'LANVIGATOR',
        'ILINK',
        'MICHELIN',
        'GOODYEAR',
        'CONTINENTAL',
        'PIRELLI',
        'HANKOOK',
        'KUMHO',
        'YOKOHAMA',
        'TOYO',
        'NITTO',
        'SAILUN',
        'WESTLAKE',
        'DOUBLE COIN',
        'LINGLONG',
        'CENTARA',
        'APTANY',
        'ROADX',

        /*
         * Marcas detectadas en tu inventario actual.
         */
        'GREENTRAC',
        'TBBTIRES',
        'TBB TIRES',
        'MILEVER',
        'FORERUNNER',
    ],

    /*
     * Fusiones que ya fueron revisadas y aprobadas manualmente.
     */
    'known_changes' => [
    // Ya fusionadas anteriormente. Es normal que ahora se omitan.
    'FS-11225FS591' => '11225FSFS591',
    '2656517BHRIDGECRAWLERRTKH' => '2656517BHRIDGECRAWLERR',
    '2657516MKHILLTRACKERLTMY' => '2657516MKHILLTRACKERLTM',

    // Nuevas coincidencias exactas.
    '2057014SACOMFORTRIDEHPST' => '2057014SACOMFORTRIDEHP',
    '2756020GTROUGHMASTERX' => '2756020GTROUGHMASTERXT',
    '2656018T TTS07HT' => '2656018TTTS07HT',
    // '2755520P SRTXLTVT' => '2755520PSRTXLTVT',
    '2856518P SATX4SLTVT' => '2856518PSATX4SLTVT',
    '2857017BHRIDGECRAWLERR' => '2857017BHRIDGECRAWLERRT',
],
];