<?php

namespace App\Services;

use App\Models\SyscomProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolvedor de categorías SYSCOM -> Mercado Libre — Fase 4.
 *
 * Clasifica primero la familia del producto y sólo después consulta categorías.
 * Usa categorías fijas únicamente cuando fueron verificadas y búsqueda ponderada
 * para el resto. domain_discovery histórico queda como último recurso.
 */
class SyscomMeliCategoryResolverService
{
    public function __construct(
        private MeliPublishService $meli,
        private SyscomMeliCategoryGuardService $guard
    ) {}

    /**
     * @return array{category_id:string,family:string,score:int,gap:int,path:string,top:array<string,int>,source:string}|null
     */
    public function resolve(User $user, SyscomProduct $product): ?array
    {
        /*
         * PRIORIDAD 1:
         * Si la categoría SYSCOM primaria ya tiene una equivalencia
         * Mercado Libre aprobada, no intentamos volver a adivinarla
         * mediante texto, descripción, familia o predictor.
         */
        /*
         * PRIORIDAD ABSOLUTA AUTOMÁTICA:
         * una excepción aprobada para este producto específico
         * tiene prioridad sobre el mapping de su categoría SYSCOM.
         */
        $productOverride = $this->resolveApprovedProductOverride($product);

        if ($productOverride !== null) {
            return $productOverride;
        }

        $mapped = $this->resolveApprovedSyscomMapping($product);

        if ($mapped !== null) {
            return $mapped;
        }

        /*
         * FALLBACK:
         * Sólo cuando todavía no conocemos el mapeo SYSCOM -> ML
         * seguimos utilizando el resolvedor histórico.
         */
        $classification = $this->guard->classifyProduct($product);
        $family = (string) ($classification['family'] ?? '');
        if ($family === '') {
            return null;
        }

        $profiles = $this->profiles($product);
        if (! isset($profiles[$family])) {
            return null;
        }

        $profile = $profiles[$family];
        $fixedId = strtoupper(trim((string) ($profile['fixed'] ?? '')));
        if ($fixedId !== '') {
            $fixed = $this->validateFixed($user, $family, $fixedId, $profile);
            if ($fixed !== null) {
                return $fixed;
            }
        }

        return $this->resolveByRanking($user, $product, $classification, $family, $profile);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{category_id:string,family:string,score:int,gap:int,path:string,top:array<string,int>,source:string}|null
     */
    /**
     * Busca primero una equivalencia SYSCOM -> Mercado Libre aprobada.
     *
     * Un mapping aprobado tiene prioridad sobre cualquier clasificación
     * heurística del título o descripción.
     *
     * @return array{category_id:string,family:string,score:int,gap:int,path:string,top:array<string,int>,source:string}|null
     */
    /**
     * Busca una excepción de categoría aprobada específicamente
     * para este producto.
     *
     * Tiene prioridad sobre el mapping global de la categoría SYSCOM.
     *
     * @return array{category_id:string,family:string,score:int,gap:int,path:string,top:array<string,int>,source:string}|null
     */
    private function resolveApprovedProductOverride(
        SyscomProduct $product
    ): ?array {
        $productId = (int) ($product->id ?? 0);

        if ($productId <= 0) {
            return null;
        }

        $override = DB::table(
            'syscom_meli_product_category_overrides'
        )
            ->where('syscom_product_id', $productId)
            ->where('approved', true)
            ->whereNotNull('meli_category_id')
            ->first();

        if (! $override) {
            return null;
        }

        $categoryId = strtoupper(
            trim((string) $override->meli_category_id)
        );

        if (! preg_match('/^MLM\d+$/', $categoryId)) {
            Log::warning(
                'SyscomMeliCategoryResolver: override aprobado con MLM inválido',
                [
                    'syscom_product_id' => $productId,
                    'syscom_producto_id' =>
                        $product->syscom_producto_id,
                    'modelo' => $product->modelo,
                    'meli_category_id' => $categoryId,
                ]
            );

            return null;
        }

        $confidence = max(
            1,
            min(
                100,
                (int) ($override->confidence ?? 100)
            )
        );

        $path = trim(
            (string) (
                $override->meli_category_path ?? ''
            )
        );

        if ($path === '') {
            $path = trim(
                (string) (
                    $override->meli_category_name ?? ''
                )
            );
        }

        Log::info(
            'SyscomMeliCategoryResolver: usando override de producto aprobado',
            [
                'phase' => 6,
                'syscom_product_id' => $productId,
                'syscom_producto_id' =>
                    $product->syscom_producto_id,
                'modelo' => $product->modelo,
                'meli_category_id' => $categoryId,
                'meli_category' =>
                    $override->meli_category_name,
                'confidence' => $confidence,
                'override_source' =>
                    $override->source,
            ]
        );

        return [
            'category_id' => $categoryId,
            'family' => 'product_override',
            'score' => $confidence,
            'gap' => PHP_INT_MAX,
            'path' => $path,
            'top' => [
                $categoryId => $confidence,
            ],
            'source' => 'product_category_override',
        ];
    }

    private function resolveApprovedSyscomMapping(SyscomProduct $product): ?array
    {
        $primaryCategoryId = (int) ($product->syscom_primary_category_id ?? 0);

        if ($primaryCategoryId <= 0) {
            return null;
        }

        $mapping = DB::table('syscom_meli_category_maps as m')
            ->join(
                'syscom_categories as c',
                'c.id',
                '=',
                'm.syscom_category_id'
            )
            ->where('m.syscom_category_id', $primaryCategoryId)
            ->where('m.approved', true)
            ->whereNotNull('m.meli_category_id')
            ->select([
                'm.meli_category_id',
                'm.meli_category_name',
                'm.meli_category_path',
                'm.confidence',
                'm.source',
                'c.syscom_category_id',
                'c.name as syscom_category_name',
                'c.path as syscom_category_path',
            ])
            ->first();

        if (! $mapping) {
            return null;
        }

        $categoryId = strtoupper(
            trim((string) $mapping->meli_category_id)
        );

        if (! preg_match('/^MLM\d+$/', $categoryId)) {
            Log::warning(
                'SyscomMeliCategoryResolver: mapping aprobado con MLM inválido',
                [
                    'syscom_producto_id' => $product->syscom_producto_id,
                    'syscom_category_id' => $mapping->syscom_category_id,
                    'meli_category_id' => $categoryId,
                ]
            );

            return null;
        }

        $confidence = max(
            1,
            min(100, (int) ($mapping->confidence ?? 100))
        );

        /*
         * meli_category_path puede estar vacío en mappings creados
         * manualmente. En ese caso mostramos por lo menos el nombre.
         */
        $path = trim(
            (string) ($mapping->meli_category_path ?? '')
        );

        if ($path === '') {
            $path = trim(
                (string) ($mapping->meli_category_name ?? '')
            );
        }

        Log::info(
            'SyscomMeliCategoryResolver: usando mapping SYSCOM -> ML aprobado',
            [
                'phase' => 5,
                'syscom_producto_id' => $product->syscom_producto_id,
                'modelo' => $product->modelo,
                'syscom_category_id' => $mapping->syscom_category_id,
                'syscom_category' => $mapping->syscom_category_name,
                'syscom_path' => $mapping->syscom_category_path,
                'meli_category_id' => $categoryId,
                'meli_category' => $mapping->meli_category_name,
                'confidence' => $confidence,
                'mapping_source' => $mapping->source,
            ]
        );

        return [
            'category_id' => $categoryId,

            /*
             * No usamos aquí la familia heurística porque justamente
             * queremos que un mapping aprobado tenga autoridad superior.
             */
            'family' => 'syscom_mapped',

            'score' => $confidence,
            'gap' => PHP_INT_MAX,
            'path' => $path,
            'top' => [
                $categoryId => $confidence,
            ],
            'source' => 'syscom_category_map',
        ];
    }

    private function validateFixed(User $user, string $family, string $categoryId, array $profile): ?array
    {
        if (! preg_match('/^MLM\d+$/', $categoryId)) {
            return null;
        }

        $meta = $this->safeGetCategory($user, $categoryId);
        if ($meta === []) {
            return null;
        }

        // Algunas categorías verificadas permiten publicar aunque Mercado Libre
        // todavía devuelva categorías hijas. En esos casos no debemos descartarlas
        // únicamente porque children_categories no esté vacío.
        $allowVerifiedWithChildren = (bool) ($profile['allow_fixed_with_children'] ?? false);

        if (
            ! $allowVerifiedWithChildren
            && ! $this->isFinalListingCategory($meta)
        ) {
            return null;
        }

        if (data_get($meta, 'settings.listing_allowed') === false) {
            return null;
        }

        $path = $this->categoryPath($meta);

        // Las categorías fijas ya fueron verificadas manualmente.
        // No deben rechazarse por las penalizaciones usadas en el ranking,
        // porque una ruta padre puede contener términos válidos pero genéricos,
        // como "Cables de Red y Accesorios".
        $score = max(
            (int) ($profile['fixed_min_score'] ?? 15),
            $this->scorePath($path, $meta, $profile)
        );

        Log::info('SyscomMeliCategoryResolver: categoría fija aceptada', [
            'phase' => 4,
            'family' => $family,
            'category_id' => $categoryId,
            'path' => $path,
            'score' => $score,
            'raw_score' => $this->scorePath($path, $meta, $profile),
        ]);

        return [
            'category_id' => $categoryId,
            'family' => $family,
            'score' => $score,
            'gap' => PHP_INT_MAX,
            'path' => $path,
            'top' => [$categoryId => $score],
            'source' => 'fixed_verified',
        ];
    }

    /**
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $profile
     * @return array{category_id:string,family:string,score:int,gap:int,path:string,top:array<string,int>,source:string}|null
     */
    private function resolveByRanking(
        User $user,
        SyscomProduct $product,
        array $classification,
        string $family,
        array $profile
    ): ?array {
        $scores = [];
        $paths = [];
        $seenQueries = [];

        foreach ((array) ($profile['queries'] ?? []) as $queryIndex => $query) {
            $query = Str::limit(trim((string) $query), 120, '');
            $queryKey = mb_strtolower($query);
            if ($query === '' || isset($seenQueries[$queryKey])) {
                continue;
            }
            $seenQueries[$queryKey] = true;

            try {
                $suggestions = $this->meli->suggestCategories($user, $query, 12);
            } catch (\Throwable $e) {
                Log::warning('SyscomMeliCategoryResolver: suggestCategories falló', [
                    'phase' => 4,
                    'family' => $family,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($suggestions as $rank => $suggestion) {
                if (! is_array($suggestion)) {
                    continue;
                }

                $id = strtoupper(trim((string) ($suggestion['id'] ?? $suggestion['category_id'] ?? '')));
                if (! preg_match('/^MLM\d+$/', $id)) {
                    continue;
                }

                $meta = $this->safeGetCategory($user, $id);
                if ($meta === [] || ! $this->isFinalListingCategory($meta)) {
                    continue;
                }

                $path = $this->categoryPath($meta);
                $pathScore = $this->scorePath($path, $meta, $profile);
                $rankScore = max(1, 12 - (int) $rank);
                $queryWeight = max(1, 7 - (int) $queryIndex);
                $score = $pathScore + $rankScore + $queryWeight;

                $scores[$id] = max($scores[$id] ?? PHP_INT_MIN, $score);
                $paths[$id] = $path;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores, SORT_NUMERIC);
        $ids = array_keys($scores);
        $bestId = (string) ($ids[0] ?? '');
        $bestScore = (int) ($scores[$bestId] ?? PHP_INT_MIN);
        $second = isset($ids[1]) ? (int) $scores[$ids[1]] : PHP_INT_MIN;
        $gap = $second === PHP_INT_MIN ? PHP_INT_MAX : $bestScore - $second;
        $minimumScore = (int) ($profile['min_score'] ?? config('syscom.meli_family_resolver_min_score', 35));
        $minimumGap = (int) ($profile['min_gap'] ?? config('syscom.meli_family_resolver_min_gap', 5));

        Log::info('SyscomMeliCategoryResolver: ranking por familia', [
            'phase' => 4,
            'family' => $family,
            'syscom_producto_id' => $product->syscom_producto_id,
            'titulo' => $product->titulo,
            'classification' => $classification,
            'best_id' => $bestId,
            'best_path' => $paths[$bestId] ?? '',
            'best_score' => $bestScore,
            'gap' => $gap,
            'top' => array_slice($scores, 0, 6, true),
        ]);

        if ($bestId === '' || $bestScore < $minimumScore || $gap < $minimumGap) {
            return null;
        }

        return [
            'category_id' => $bestId,
            'family' => $family,
            'score' => $bestScore,
            'gap' => $gap,
            'path' => $paths[$bestId] ?? '',
            'top' => array_slice($scores, 0, 6, true),
            'source' => 'family_ranking',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function profiles(SyscomProduct $product): array
    {
        $brand = trim((string) ($product->marca ?? ''));
        $model = trim((string) ($product->modelo ?? ''));
        $title = trim(strip_tags((string) ($product->titulo ?? '')));
        $specific = trim($brand.' '.$model);

        return [
            'dash_cam' => [
                'fixed' => strtoupper(trim((string) config('syscom.meli_dash_cam_category_id', ''))),
                'positive' => ['camaras vehiculares' => 60, 'camara vehicular' => 55, 'camaras para autos' => 55, 'dash cam' => 52, 'accesorios para vehiculos' => 20],
                'negative' => ['camaras de seguridad' => 95, 'videovigilancia' => 90, 'cctv' => 90, 'grabadores dvr' => 75, 'grabadores nvr' => 75],
                'queries' => ['cámara vehicular dash cam', 'cámara de tablero para automóvil', 'dashcam para vehículo', trim($specific.' dash cam'), $title],
                'min_score' => 38,
                'min_gap' => 5,
            ],
            'ups' => [
                'fixed' => strtoupper(trim((string) config('syscom.meli_ups_category_id', 'MLM1720'))),
                'positive' => ['ups' => 45, 'no breaks' => 55, 'estabilizadores y ups' => 45],
                'negative' => ['pdu' => 80, 'multicontactos' => 65, 'fuentes de poder' => 55],
                'queries' => ['ups no break', 'sistema de alimentación ininterrumpida', trim($specific.' ups no break'), $title],
                'fixed_min_score' => 20,
            ],
            'lock_key' => [
                'fixed' => strtoupper(trim((string) config('syscom.meli_lock_key_category_id', 'MLM420127'))),
                'positive' => ['seguridad para el hogar' => 18, 'herrajes de seguridad' => 28, 'cerraduras' => 42, 'manuales' => 22],
                'negative' => ['herramientas manuales' => 80, 'agro' => 80, 'accesorios para vehiculos' => 90, 'grifos' => 80, 'cajas para llaves' => 65],
                'queries' => ['repuesto de cerradura manual para gabinete', 'llave de reemplazo para cerradura de gabinete', trim($specific.' cerradura manual'), $title],
                'fixed_min_score' => 25,
            ],
            'cable_organizer' => [
                // Categoría final verificada en Mercado Libre:
                // Computación > Accesorios de Computación > Organización de Cables
                // > Organizadores de Cables.
                'fixed' => strtoupper(trim((string) config(
                    'syscom.meli_cable_organizer_category_id',
                    'MLM190951'
                ))),
                'positive' => [
                    'organizadores de cables' => 60,
                    'organizador de cables' => 55,
                    'canaletas para cables' => 38,
                    'sujetadores de cables' => 55,
                    'accesorios para cables' => 25,
                ],
                'negative' => [
                    'cables de red' => 55,
                    'redes de proteccion' => 70,
                    'redes de basquetbol' => 95,
                    'cables solares' => 65,
                ],
                'queries' => [
                    'organizador sujetador para cables',
                    'clip montaje para cable',
                    'sujetador de nylon para cable',
                    trim($specific.' sujetador cable'),
                    $title,
                ],
                'fixed_min_score' => 35,
                'allow_fixed_with_children' => true,
                'min_score' => 42,
                'min_gap' => 4,
            ],
            'video_intercom' => $this->profile(['videoporteros' => 55, 'porteros electricos' => 50, 'intercomunicadores' => 35], ['camaras de seguridad' => 55, 'vehiculos' => 80], ['videoportero', 'portero eléctrico con monitor', trim($specific.' videoportero'), $title]),
            'network_switch' => $this->profile(['switches' => 55, 'interruptores de red' => 55, 'switch de red' => 55], ['routers' => 55, 'hubs usb' => 75], ['switch de red ethernet', trim($specific.' switch ethernet'), $title]),
            'router' => $this->profile(['routers' => 60, 'enrutadores' => 60], ['switches' => 55, 'camaras de seguridad' => 70], ['router wifi', trim($specific.' router wifi'), $title]),
            'poe_injector' => $this->profile(['inyectores poe' => 65, 'inyectores de corriente' => 45], ['switches' => 55, 'paneles solares' => 70], ['inyector poe', trim($specific.' inyector poe'), $title]),
            'wireless_antenna' => $this->profile(['antenas wifi' => 58, 'antenas de red' => 52, 'antenas inalambricas' => 55], ['antenas para autos' => 75], ['antena wifi para red', trim($specific.' antena wifi'), $title]),
            'olt' => $this->profile(['terminales de linea optica' => 65, 'equipos de fibra optica' => 45], ['fuentes de poder' => 60], ['olt gpon fibra óptica', trim($specific.' olt gpon'), $title]),
            'dvr_nvr' => $this->profile(['grabadores dvr' => 60, 'grabadores nvr' => 60, 'videograbadores' => 55], ['camaras para autos' => 90, 'autopartes' => 90], ['grabador dvr nvr videovigilancia', trim($specific.' dvr nvr'), $title]),
            'surveillance_camera' => $this->profile(['camaras de seguridad' => 60, 'camaras de vigilancia' => 58], ['camaras para autos' => 90, 'webcams' => 65], ['cámara de seguridad cctv', trim($specific.' cámara seguridad'), $title]),
            'balun' => $this->profile(['balunes' => 65, 'convertidores de audio y video' => 38], ['software' => 80], ['balun de video cctv', trim($specific.' balun video'), $title]),
            'pdu' => $this->profile(['pdu' => 65, 'regletas electricas' => 45, 'multicontactos' => 38], ['ups' => 75, 'no breaks' => 75], ['pdu rack distribución energía', trim($specific.' pdu rack'), $title]),
            'power_supply' => $this->profile(['fuentes de alimentacion' => 60, 'fuentes de poder' => 60, 'fuentes conmutadas' => 60], ['ups' => 70, 'pdu' => 70], ['fuente de alimentación conmutada', trim($specific.' fuente poder'), $title]),
            'solar_mount' => $this->profile(['soportes para paneles solares' => 60, 'estructuras para paneles' => 60, 'montajes solares' => 55], ['techos corredizos' => 80], ['soporte montaje panel solar', trim($specific.' montaje solar'), $title]),
            'solar_meter' => $this->profile(['medidores de energia' => 58, 'medidores electricos' => 50], ['paneles solares' => 55], ['medidor energía solar bidireccional', trim($specific.' medidor energia'), $title]),
            'solar_cable' => $this->profile(['cables para paneles solares' => 60, 'cables solares' => 60, 'conectores solares' => 50], ['cables de red' => 65], ['cable solar fotovoltaico mc4', trim($specific.' cable solar'), $title]),
            'alarm' => $this->profile(['alarmas y sensores' => 58, 'sistemas de alarma' => 58], ['alarmas para autos' => 80], ['sensor alarma intrusión', trim($specific.' alarma sensor'), $title]),
            'electrical_connector' => $this->profile(['conectores electricos' => 60, 'componentes electronicos > conectores' => 48], ['conectores solares' => 55], ['conector eléctrico terminal', trim($specific.' conector electrico'), $title]),
            'tool_kit' => $this->profile(['kits de herramientas' => 60, 'juegos de herramientas' => 60], ['juguetes' => 75], ['kit juego de herramientas', trim($specific.' kit herramientas'), $title]),
            'flashlight' => $this->profile(['linternas' => 65], ['software' => 80], ['linterna portátil', trim($specific.' linterna'), $title]),
        ];
    }

    /** @return array<string,mixed> */
    private function profile(array $positive, array $negative, array $queries): array
    {
        return ['positive' => $positive, 'negative' => $negative, 'queries' => $queries, 'min_score' => 38, 'min_gap' => 5];
    }

    /** @param array<string,mixed> $profile */
    private function scorePath(string $path, array $meta, array $profile): int
    {
        $text = $this->normalize($path.' '.(string) ($meta['name'] ?? '').' '.(string) data_get($meta, 'settings.catalog_domain', ''));
        $score = 0;
        foreach ((array) ($profile['positive'] ?? []) as $needle => $weight) {
            if (str_contains($text, $this->normalize((string) $needle))) {
                $score += (int) $weight;
            }
        }
        foreach ((array) ($profile['negative'] ?? []) as $needle => $penalty) {
            if (str_contains($text, $this->normalize((string) $needle))) {
                $score -= (int) $penalty;
            }
        }
        return $score;
    }

    /** @param array<string,mixed> $meta */
    private function isFinalListingCategory(array $meta): bool
    {
        if (data_get($meta, 'settings.listing_allowed') === false) {
            return false;
        }
        return (is_array($meta['children_categories'] ?? null) ? $meta['children_categories'] : []) === [];
    }

    /** @return array<string,mixed> */
    private function safeGetCategory(User $user, string $id): array
    {
        try {
            $meta = $this->meli->getCategory($user, $id);
            return is_array($meta) ? $meta : [];
        } catch (\Throwable $e) {
            Log::warning('SyscomMeliCategoryResolver: getCategory falló', [
                'phase' => 4,
                'category_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** @param array<string,mixed> $meta */
    private function categoryPath(array $meta): string
    {
        $parts = [];
        foreach (is_array($meta['path_from_root'] ?? null) ? $meta['path_from_root'] : [] as $node) {
            if (is_array($node) && trim((string) ($node['name'] ?? '')) !== '') {
                $parts[] = trim((string) $node['name']);
            }
        }
        $name = trim((string) ($meta['name'] ?? ''));
        if ($name !== '' && ! in_array($name, $parts, true)) {
            $parts[] = $name;
        }
        return implode(' > ', $parts);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','‑'=>'-','–'=>'-','—'=>'-']);
        $text = (string) preg_replace('/[^a-z0-9_\- ]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
