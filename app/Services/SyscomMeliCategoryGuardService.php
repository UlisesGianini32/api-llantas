<?php

namespace App\Services;

use App\Models\SyscomProduct;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Guardián de categorías SYSCOM -> Mercado Libre — Fase 2.
 *
 * Mejoras frente a Fase 1:
 * - Clasificación por familias mediante puntajes ponderados.
 * - Prioridades y señales negativas para evitar falsos positivos.
 * - Familia específica para Dash Cam / cámara vehicular.
 * - Diferencia DVR/NVR de videovigilancia frente a grabadores vehiculares.
 * - Mantiene la misma interfaz pública para no modificar el servicio publicador.
 */
class SyscomMeliCategoryGuardService
{
    private const MIN_PRODUCT_SCORE = 7;
    private const MIN_CATEGORY_SCORE = 5;
    private const MIN_WINNER_GAP = 2;

    public function __construct(private MeliPublishService $meli) {}

    /**
     * @return array{
     *   category_id:string,
     *   category_path:string,
     *   product_family:?string,
     *   category_family:?string,
     *   confidence:int,
     *   manual:bool,
     *   warnings:list<string>,
     *   product_scores:array<string,int>,
     *   category_scores:array<string,int>
     * }
     */
    /**
     * Expone la clasificación de producto para que el resolvedor de categorías
     * pueda buscar exclusivamente dentro de la familia correcta.
     *
     * @return array{family:?string,scores:array<string,int>,signals:array<string,list<string>>,ambiguous:bool}
     */
    /**
     * El guard sólo omite heurísticas cuando el MLM coincide exactamente
     * con un mapping aprobado para la categoría SYSCOM primaria.
     */
    /**
     * El override de producto aprobado tiene prioridad sobre
     * el mapping global de la categoría SYSCOM.
     */
    private function hasApprovedProductOverride(
        SyscomProduct $product,
        string $categoryId
    ): bool {
        $productId = (int) ($product->id ?? 0);
        $categoryId = strtoupper(trim($categoryId));

        if (
            $productId <= 0
            || ! preg_match('/^MLM\d+$/', $categoryId)
        ) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table(
            'syscom_meli_product_category_overrides'
        )
            ->where('syscom_product_id', $productId)
            ->where('approved', true)
            ->whereRaw(
                'UPPER(TRIM(meli_category_id)) = ?',
                [$categoryId]
            )
            ->exists();
    }

    private function hasApprovedSyscomMapping(
        SyscomProduct $product,
        string $categoryId
    ): bool {
        $primaryCategoryId = (int) (
            $product->syscom_primary_category_id ?? 0
        );

        $categoryId = strtoupper(trim($categoryId));

        if (
            $primaryCategoryId <= 0
            || ! preg_match('/^MLM\d+$/', $categoryId)
        ) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table(
            'syscom_meli_category_maps'
        )
            ->where('syscom_category_id', $primaryCategoryId)
            ->where('approved', true)
            ->whereRaw(
                'UPPER(TRIM(meli_category_id)) = ?',
                [$categoryId]
            )
            ->exists();
    }

    public function classifyProduct(SyscomProduct $product): array
    {
        return $this->detectProductFamily($this->productText($product));
    }

    public function validate(
        User $user,
        SyscomProduct $product,
        string $categoryId,
        bool $manual = false
    ): array {
        $categoryId = strtoupper(trim($categoryId));
        $meta = $this->meli->getCategory($user, $categoryId);

        $categoryPath = $this->categoryPath($meta);

        /*
         * La categoría ya pasó previamente las validaciones estructurales
         * del publicador.
         *
         * Una excepción aprobada específicamente para el producto tiene
         * prioridad incluso sobre el mapping global de su categoría SYSCOM.
         */
        if ($this->hasApprovedProductOverride($product, $categoryId)) {
            $diagnostic = [
                'category_id' => $categoryId,
                'category_path' => $categoryPath,
                'product_family' => null,
                'category_family' => null,
                'confidence' => 100,
                'manual' => $manual,
                'warnings' => [],
                'product_scores' => [],
                'category_scores' => [],
                'source' => 'product_category_override',
            ];

            Log::info(
                'SyscomMeliCategoryGuard: override de producto aprobado',
                array_merge(
                    $diagnostic,
                    [
                        'syscom_producto_id' =>
                            $product->syscom_producto_id,
                        'modelo' =>
                            $product->modelo,
                        'titulo' =>
                            $product->titulo,
                    ]
                )
            );

            return $diagnostic;
        }

        /*
         * Si no existe override de producto, respetamos el mapping
         * aprobado de la categoría SYSCOM primaria.
         */
        if ($this->hasApprovedSyscomMapping($product, $categoryId)) {
            $diagnostic = [
                'category_id' => $categoryId,
                'category_path' => $categoryPath,
                'product_family' => null,
                'category_family' => null,
                'confidence' => 100,
                'manual' => $manual,
                'warnings' => [],
                'product_scores' => [],
                'category_scores' => [],
                'source' => 'syscom_category_map',
            ];

            Log::info(
                'SyscomMeliCategoryGuard: mapping SYSCOM -> ML aprobado',
                array_merge(
                    $diagnostic,
                    [
                        'syscom_producto_id' =>
                            $product->syscom_producto_id,
                        'titulo' => $product->titulo,
                    ]
                )
            );

            return $diagnostic;
        }

        $productText = $this->productText($product);

        $productDetection = $this->detectProductFamily($productText);
        $categoryDetection = $this->detectCategoryFamily($categoryPath, $meta);

        $productFamily = $productDetection['family'];
        $categoryFamily = $categoryDetection['family'];
        $warnings = [];

        $conflict = $this->conflictMessage(
            $productFamily,
            $categoryFamily,
            $productText,
            $categoryPath
        );

        if ($conflict !== null) {
            Log::warning('SyscomMeliCategoryGuard: categoría bloqueada por conflicto fuerte', [
                'phase' => 2,
                'category_id' => $categoryId,
                'category_path' => $categoryPath,
                'product_family' => $productFamily,
                'category_family' => $categoryFamily,
                'product_scores' => $productDetection['scores'],
                'category_scores' => $categoryDetection['scores'],
                'product_signals' => $productDetection['signals'],
                'category_signals' => $categoryDetection['signals'],
                'syscom_producto_id' => $product->syscom_producto_id,
                'titulo' => $product->titulo,
                'manual' => $manual,
                'reason' => $conflict,
            ]);

            throw new \RuntimeException(
                'Por seguridad no se publicó. '.$conflict.' '.
                'Categoría recibida: '.$categoryId.' ('.$categoryPath.'). '.
                ($manual
                    ? 'Aunque la categoría fue capturada manualmente, contradice claramente el tipo de producto.'
                    : 'Selecciona una categoría final distinta en «Categoría ML» o mejora título, modelo, descripción y categoría SYSCOM.')
            );
        }

        if ($productFamily === null) {
            $warnings[] = 'No se pudo determinar una familia de producto con confianza alta.';
        }
        if ($categoryFamily === null) {
            $warnings[] = 'La ruta de Mercado Libre no pertenece a una familia reconocida por el guardián.';
        }
        if ($productDetection['ambiguous']) {
            $warnings[] = 'La familia del producto fue ambigua; no se aplicó un bloqueo por coincidencia débil.';
        }
        if ($categoryDetection['ambiguous']) {
            $warnings[] = 'La familia de la categoría fue ambigua; no se aplicó un bloqueo por coincidencia débil.';
        }

        $confidence = $this->confidence($productDetection, $categoryDetection);

        $diagnostic = [
            'category_id' => $categoryId,
            'category_path' => $categoryPath,
            'product_family' => $productFamily,
            'category_family' => $categoryFamily,
            'confidence' => $confidence,
            'manual' => $manual,
            'warnings' => $warnings,
            'product_scores' => $productDetection['scores'],
            'category_scores' => $categoryDetection['scores'],
        ];

        Log::info('SyscomMeliCategoryGuard: categoría aprobada', array_merge($diagnostic, [
            'phase' => 2,
            'product_signals' => $productDetection['signals'],
            'category_signals' => $categoryDetection['signals'],
            'syscom_producto_id' => $product->syscom_producto_id,
            'titulo' => $product->titulo,
        ]));

        return $diagnostic;
    }

    /** @param array<string,mixed> $meta */
    private function categoryPath(array $meta): string
    {
        $parts = [];
        foreach (is_array($meta['path_from_root'] ?? null) ? $meta['path_from_root'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $name = trim((string) ($node['name'] ?? ''));
            if ($name !== '') {
                $parts[] = $name;
            }
        }

        $name = trim((string) ($meta['name'] ?? ''));
        if ($name !== '' && ! in_array($name, $parts, true)) {
            $parts[] = $name;
        }

        return $parts !== [] ? implode(' > ', $parts) : 'ruta desconocida';
    }

    private function productText(SyscomProduct $product): string
    {
        $parts = [
            (string) ($product->titulo ?? ''),
            (string) ($product->marca ?? ''),
            (string) ($product->modelo ?? ''),
            $this->plainText($product->descripcion),
            $this->jsonText($product->categorias),
            $this->jsonText($product->raw_list),
            $this->jsonText($product->raw_detail),
        ];

        return $this->normalize(implode(' ', $parts));
    }

    private function plainText(mixed $value): string
    {
        return is_array($value)
            ? $this->jsonText($value)
            : trim(strip_tags((string) $value));
    }

    private function jsonText(mixed $value): string
    {
        if (! is_array($value)) {
            return '';
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', '‑' => '-', '–' => '-', '—' => '-',
        ]);

        $text = (string) preg_replace('/[^a-z0-9+#.%\/\- ]+/u', ' ', $text);

        return ' '.trim((string) preg_replace('/\s+/u', ' ', $text)).' ';
    }

    /**
     * @return array{family:?string,scores:array<string,int>,signals:array<string,list<string>>,ambiguous:bool}
     */
    private function detectProductFamily(string $text): array
    {
        $rules = $this->productRules();
        $scores = [];
        $signals = [];

        foreach ($rules as $family => $rule) {
            $score = 0;
            $matched = [];

            foreach ($rule['positive'] as $needle => $weight) {
                if ($this->contains($text, $needle)) {
                    $score += $weight;
                    $matched[] = '+'.$needle.'('.$weight.')';
                }
            }

            foreach ($rule['negative'] as $needle => $weight) {
                if ($this->contains($text, $needle)) {
                    $score -= $weight;
                    $matched[] = '-'.$needle.'('.$weight.')';
                }
            }

            if (isset($rule['requires_any']) && ! $this->containsAny($text, $rule['requires_any'])) {
                $score = min($score, 0);
            }

            if ($score !== 0) {
                $scores[$family] = $score;
            }
            if ($matched !== []) {
                $signals[$family] = $matched;
            }
        }

        return $this->selectWinner($scores, $signals, self::MIN_PRODUCT_SCORE);
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{family:?string,scores:array<string,int>,signals:array<string,list<string>>,ambiguous:bool}
     */
    private function detectCategoryFamily(string $path, array $meta): array
    {
        $domain = (string) data_get($meta, 'settings.catalog_domain', '');
        $text = $this->normalize($path.' '.$domain);
        $scores = [];
        $signals = [];

        foreach ($this->categoryRules() as $family => $needles) {
            $score = 0;
            $matched = [];
            foreach ($needles as $needle => $weight) {
                if ($this->contains($text, $needle)) {
                    $score += $weight;
                    $matched[] = '+'.$needle.'('.$weight.')';
                }
            }

            if ($score > 0) {
                $scores[$family] = $score;
                $signals[$family] = $matched;
            }
        }

        return $this->selectWinner($scores, $signals, self::MIN_CATEGORY_SCORE);
    }

    /**
     * @param array<string,int> $scores
     * @param array<string,list<string>> $signals
     * @return array{family:?string,scores:array<string,int>,signals:array<string,list<string>>,ambiguous:bool}
     */
    private function selectWinner(array $scores, array $signals, int $minimum): array
    {
        arsort($scores);
        $families = array_keys($scores);
        $winner = $families[0] ?? null;
        $winnerScore = $winner !== null ? (int) $scores[$winner] : 0;
        $secondScore = isset($families[1]) ? (int) $scores[$families[1]] : 0;
        $ambiguous = $winner !== null && ($winnerScore - $secondScore) < self::MIN_WINNER_GAP;

        if ($winnerScore < $minimum || $ambiguous) {
            $winner = null;
        }

        return [
            'family' => $winner,
            'scores' => $scores,
            'signals' => $signals,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @return array<string,array{positive:array<string,int>,negative:array<string,int>,requires_any?:list<string>}>
     */
    private function productRules(): array
    {
        return [
            // Debe ir antes conceptualmente que DVR/NVR: las señales vehiculares son decisivas.
            'dash_cam' => [
                'positive' => [
                    'dash cam' => 14,
                    'dashcam' => 14,
                    'camara de tablero' => 14,
                    'camara vehicular' => 13,
                    'camara para vehiculo' => 13,
                    'camara movil para vehiculo' => 13,
                    'grabador vehicular' => 12,
                    'mobile dvr' => 11,
                    'mdvr' => 11,
                    'vehiculo' => 5,
                    'automovil' => 5,
                    'auto ' => 3,
                    'tablero' => 5,
                    'parabrisas' => 5,
                    'conductor' => 3,
                    'gps vehicular' => 4,
                    'dsm' => 3,
                    'adas' => 4,
                ],
                'negative' => [
                    'camara de seguridad fija' => 6,
                    'kit cctv' => 8,
                    'rack' => 3,
                    'canales poe' => 4,
                ],
                'requires_any' => ['vehiculo', 'automovil', 'tablero', 'dash cam', 'dashcam', 'parabrisas', 'mobile dvr', 'mdvr'],
            ],
            'video_intercom' => [
                'positive' => ['videoportero' => 12, 'video portero' => 12, 'portero electrico' => 10, 'monitor de portero' => 9, 'intercomunicador' => 7],
                'negative' => ['dash cam' => 8, 'vehiculo' => 4],
            ],
            'solar_mount' => [
                'positive' => ['riel solar' => 11, 'montaje solar' => 11, 'estructura solar' => 10, 'soporte fotovoltaico' => 10, 'abrazadera solar' => 9, 'mid clamp' => 10, 'end clamp' => 10],
                'negative' => [],
            ],
            'solar_meter' => [
                'positive' => ['exportacion cero' => 10, 'smart meter' => 10, 'dtsu666' => 14, 'medidor bidireccional' => 10, 'medidor de energia' => 6],
                'negative' => ['abrazadera' => 5, 'riel' => 5],
            ],
            'solar_cable' => [
                'positive' => ['cable solar' => 10, 'cable fotovoltaico' => 10, 'pv cable' => 10, 'conector mc4' => 11, ' mc4 ' => 7],
                'negative' => ['medidor' => 4, 'inversor' => 4],
            ],
            'poe_injector' => [
                'positive' => ['inyector poe' => 13, 'poe injector' => 13, 'adaptador poe' => 9, 'inserter poe' => 11],
                'negative' => ['switch poe' => 9, 'puertos poe' => 5],
            ],
            'network_switch' => [
                'positive' => ['switch poe' => 13, 'switch administrable' => 13, 'switch de red' => 13, 'network switch' => 13, 'conmutador ethernet' => 11, 'puertos poe' => 4, 'puertos gigabit' => 4],
                'negative' => ['inyector poe' => 12, 'dash cam' => 8],
            ],
            'olt' => [
                'positive' => [' gpon olt ' => 15, ' epon olt ' => 15, ' terminal de linea optica ' => 13, ' olt ' => 10],
                'negative' => ['volt' => 8],
            ],
            'wireless_antenna' => [
                'positive' => ['antena sectorial' => 11, 'antena omnidireccional' => 11, 'antena direccional' => 11, 'dish antenna' => 10, 'antena parabolica' => 10],
                'negative' => ['antena para auto' => 7],
            ],
            'router' => [
                'positive' => [
                    'router balanceador' => 20,
                    'balanceador inalambrico' => 14,
                    'balanceador inalámbrico' => 14,
                    'router wifi' => 13,
                    'router wi-fi' => 13,
                    'enrutador' => 12,
                    'ont wifi' => 12,
                    'gateway inalambrico' => 10,
                ],
                'negative' => ['switch poe' => 8, 'dash cam' => 9],
            ],
            'dvr_nvr' => [
                'positive' => [' dvr ' => 8, ' nvr ' => 10, 'videograbador' => 11, 'grabador de video' => 7, 'grabador ip' => 9, 'canales de video' => 4, 'canales poe' => 5],
                'negative' => [
                    'dash cam' => 16,
                    'dashcam' => 16,
                    'camara de tablero' => 14,
                    'camara vehicular' => 14,
                    'camara movil para vehiculo' => 14,
                    'vehiculo' => 8,
                    'automovil' => 8,
                    'tablero' => 7,
                    'parabrisas' => 7,
                    'mobile dvr' => 12,
                    'mdvr' => 12,
                    'bala ip' => 22,
                    'fisheye ip' => 22,
                    'lente motorizado' => 12,

                    // Evitar falsos DVR/NVR cuando la ficha pertenece
                    // claramente a un router/gateway.
                    'router balanceador' => 24,
                    'balanceador inalambrico' => 18,
                    'balanceador inalámbrico' => 18,
                    'router wifi' => 16,
                    'router wi-fi' => 16,
                    'gateway inalambrico' => 16,
                ],
            ],
            'surveillance_camera' => [
                'positive' => [
                    'bala ip' => 18,
                    'fisheye ip' => 18,
                    'camara ip' => 10,
                    'camaras ip' => 14,
                    'camara de seguridad' => 12,
                    'camaras de seguridad' => 12,
                    'camara cctv' => 12,
                    'lente motorizado' => 10,
                    'turbohd' => 11,
                    'colorvu' => 11,
                    'acusense' => 10,
                    'vigilancia' => 5,
                ],
                'negative' => [
                    'dash cam' => 15,
                    'camara de tablero' => 15,
                    'camara vehicular' => 15,
                    'vehiculo' => 8,
                    'automovil' => 8,
                ],
            ],
            'balun' => [
                'positive' => ['balun' => 12, 'transceptor pasivo' => 10, 'video balun' => 13],
                'negative' => [],
            ],
            'alarm' => [
                'positive' => ['panel de alarma' => 12, 'sensor pir' => 10, 'intrusion' => 8, 'expansor honeywell' => 10, 'sirena de alarma' => 9],
                'negative' => ['alarma vehicular' => 8],
            ],
            'ups' => [
                'positive' => [' no break ' => 13, ' nobreak ' => 13, ' ups ' => 11, 'uninterruptible power supply' => 13, 'respaldo de energia' => 7, 'va/' => 4, 'topologia linea interactiva' => 10],
                'negative' => ['pdu' => 8, 'fuente de poder' => 5],
            ],
            'power_supply' => [
                'positive' => ['fuente de poder' => 12, 'fuente conmutada' => 12, 'power supply' => 11, 'adaptador de corriente' => 9, 'eliminador' => 8],
                'negative' => ['ups' => 8, 'no break' => 8, 'pdu' => 7],
            ],
            'pdu' => [
                'positive' => [' pdu ' => 13, 'barra de distribucion' => 11, 'unidad de distribucion de energia' => 13, 'multicontacto rack' => 11],
                'negative' => ['ups' => 8, 'no break' => 8],
            ],
            'lock_key' => [
                'positive' => [
                    'llave de reemplazo para cerradura' => 18,
                    'llave para cerradura' => 14,
                    'repuesto cerradura gabinete' => 13,
                    'cerradura manual' => 10,
                    'gabinetes pst' => 8,
                    'compatible con gabinetes' => 7,
                ],
                'negative' => [
                    'llave inglesa' => 18,
                    'llave combinada' => 18,
                    'llave de corte' => 15,
                    'vehiculo' => 10,
                    'automotriz' => 10,
                    'griferia' => 12,
                ],
                'requires_any' => ['cerradura', 'gabinete', 'gabinetes pst'],
            ],
            'cable_organizer' => [
                'positive' => [
                    'organizador de cables' => 13,
                    'clip para cable' => 11,
                    'clip de nylon' => 10,
                    'sujetador para cable' => 11,
                    'cincho para cable' => 10,
                    'abrazadera para cable' => 9,

                    // Montajes adhesivos SYSCOM/Panduit para sujetar cableado.
                    'montaje sujetador adhesivo' => 13,
                    'montaje-sujetador adhesivo' => 13,
                    'sujetador adhesivo' => 11,
                    'base adhesiva para cincho' => 12,
                    'base adhesiva para cable' => 12,
                    'montaje adhesivo de 4 vias' => 12,
                    'montaje adhesivo de 4 vías' => 12,
                    'stronghold de 4 vias' => 10,
                    'stronghold de 4 vías' => 10,
                ],
                'negative' => [
                    'cable solar' => 5,
                    'cable de red cat' => 4,
                    'plug modular' => 12,
                    'conector rj45' => 12,
                ],
            ],
            'tool_kit' => [
                'positive' => ['kit de herramientas' => 12, 'juego de herramientas' => 12, 'bolsa de herramientas' => 8],
                'negative' => [],
            ],
            'flashlight' => [
                'positive' => ['linterna' => 12, 'flashlight' => 12],
                'negative' => [],
            ],
            'electrical_connector' => [
                'positive' => ['conector electrico' => 11, 'bornera' => 10, 'terminal electrica' => 10, 'bloque de conexion' => 10, 'push wire connector' => 12],
                'negative' => ['conector mc4' => 8],
            ],
            'tire' => [
                'positive' => ['llanta ' => 13, 'neumatico' => 13, 'rin ' => 8],
                'negative' => ['cable' => 3],
            ],
        ];
    }

    /** @return array<string,array<string,int>> */
    private function categoryRules(): array
    {
        return [
            'dash_cam' => [
                'camaras para autos' => 15,
                'camaras vehiculares' => 15,
                'dash cams' => 15,
                'dashcams' => 15,
                'seguridad vehicular' => 8,
                'accesorios para vehiculos > seguridad' => 8,
                'mlm-car-cameras' => 18,
                'mlm-dash-cams' => 18,
            ],
            'video_intercom' => ['porteros electricos' => 12, 'videoporteros' => 14, 'intercomunicadores' => 8],
            'solar_mount' => ['estructuras para paneles' => 13, 'soportes para paneles solares' => 13, 'montajes solares' => 12],
            'solar_meter' => ['medidores de energia' => 12, 'medidores electricos' => 10],
            'solar_cable' => ['cables para paneles solares' => 13, 'cables solares' => 13, 'conectores solares' => 11],
            'poe_injector' => ['inyectores poe' => 14, 'inyectores de corriente' => 9],
            'network_switch' => ['switches' => 13, 'interruptores de red' => 12, 'switch de red' => 13],
            'olt' => ['equipos de fibra optica' => 8, 'terminales de linea optica' => 13],
            'wireless_antenna' => ['antenas de red' => 11, 'antenas wifi' => 12, 'antenas inalambricas' => 12],
            'router' => ['routers' => 13, 'enrutadores' => 13],
            'dvr_nvr' => ['grabadores dvr' => 14, 'grabadores nvr' => 14, 'videograbadores' => 13, 'mlm-digital-video-recorders' => 16],
            'surveillance_camera' => ['camaras de seguridad' => 13, 'camaras de vigilancia' => 13, 'mlm-surveillance-cameras' => 16],
            'balun' => ['balunes' => 14, 'convertidores de audio y video' => 7],
            'alarm' => ['alarmas y sensores' => 12, 'sistemas de alarma' => 12],
            'ups' => ['ups' => 13, 'no breaks' => 14, 'estabilizadores y ups' => 11],
            'power_supply' => ['fuentes conmutadas' => 13, 'fuentes de alimentacion' => 13, 'fuentes de poder' => 13],
            'pdu' => ['multicontactos' => 9, 'regletas electricas' => 10, 'pdu' => 14],
            'lock_key' => [
                'herrajes de seguridad > cerraduras > manuales' => 18,
                'cerraduras > manuales' => 16,
                'cerraduras manuales' => 16,
            ],
            'cable_organizer' => ['organizadores de cables' => 15, 'mlm-cable-organizers' => 18],
            'tool_kit' => ['kits de herramientas' => 13, 'juegos de herramientas' => 13, 'herramientas combinadas' => 10],
            'flashlight' => ['linternas' => 14],
            'electrical_connector' => ['componentes electronicos > conectores' => 11, 'conectores electricos' => 13],
            'tire' => ['llantas' => 14, 'neumaticos' => 14],
        ];
    }

    private function conflictMessage(
        ?string $productFamily,
        ?string $categoryFamily,
        string $productText,
        string $categoryPath
    ): ?string {
        if ($productFamily !== null && $categoryFamily !== null && $productFamily !== $categoryFamily) {
            // Dash Cam puede caer temporalmente en una categoría genérica automotriz no reconocida,
            // pero nunca debe bloquearse como DVR/NVR o CCTV solo por contener "DVR".
            return sprintf(
                'El producto fue identificado como «%s», pero la categoría pertenece a «%s».',
                $this->familyLabel($productFamily),
                $this->familyLabel($categoryFamily)
            );
        }

        // Un sujetador, clip, abrazadera o base adhesiva para cable nunca
        // debe publicarse en la categoría final Plug. La ruta padre correcta
        // de Organizadores también contiene "Cables de Red y Accesorios",
        // por eso validamos específicamente la última categoría de la ruta.
        if (
            $productFamily === 'cable_organizer'
            && preg_match('/(?:^|>)\s*(?:plug|plugs)\s*$/iu', trim($categoryPath))
        ) {
            return sprintf(
                'El producto fue identificado como «%s», pero la categoría final es «Plug». Debe usarse Organizadores de Cables (MLM190951).',
                $this->familyLabel($productFamily)
            );
        }

        // Los montajes, bases, clips y sujetadores adhesivos para cableado
        // nunca deben publicarse como Plug o conector.
        if (
            $productFamily === 'cable_organizer'
            && preg_match('/(?:^|>)\s*(?:plug|plugs|conectores?|conectores de red)\s*$/iu', trim($categoryPath))
        ) {
            return sprintf(
                'El producto fue identificado como «%s», pero la categoría final es «%s». Debe usarse Organizadores de Cables (MLM190951).',
                $this->familyLabel($productFamily),
                trim((string) preg_replace('/^.*>\s*/u', '', $categoryPath))
            );
        }

        $path = $this->normalize($categoryPath);

        $strongContradictions = [
            'dash_cam' => ['camaras de seguridad', 'grabadores dvr', 'grabadores nvr', 'videograbadores cctv', 'seguridad para el hogar'],
            'network_switch' => ['routers', 'camaras de seguridad', 'hubs usb', 'kits de seguridad'],
            'router' => ['fuentes conmutadas', 'camaras de seguridad', 'switches'],
            'surveillance_camera' => ['grabadores dvr', 'grabadores nvr', 'autopartes', 'turbos', 'camaras para autos'],
            'dvr_nvr' => ['autopartes', 'turbos', 'llantas', 'neumaticos', 'camaras para autos'],
            'ups' => ['pdu', 'fuentes conmutadas', 'camaras de seguridad'],
            'power_supply' => ['camaras de seguridad', 'routers', 'llantas', 'ups', 'no breaks'],
            'pdu' => ['ups', 'no breaks', 'camaras de seguridad'],
            'lock_key' => ['herramientas manuales > fijacion > llaves', 'agro', 'accesorios para vehiculos', 'grifos', 'cajas para llaves'],
            'solar_mount' => ['camaras de seguridad', 'grabadores dvr', 'software', 'techos corredizos'],
            'solar_meter' => ['paneles solares', 'inversores solares'],
            'poe_injector' => ['paneles solares', 'estructuras solares', 'switches'],
            'balun' => ['software', 'kits de seguridad'],
            'video_intercom' => ['camaras de seguridad', 'grabadores dvr'],
            'tool_kit' => ['juguetes', 'caza', 'camaras de seguridad'],
            'flashlight' => ['software'],
            'tire' => ['electronica', 'camaras de seguridad', 'routers'],
        ];

        if ($productFamily !== null
            && isset($strongContradictions[$productFamily])
            && $this->containsAny($path, $strongContradictions[$productFamily])) {
            return sprintf(
                'El producto fue identificado como «%s» y la ruta de la categoría contiene una contradicción fuerte.',
                $this->familyLabel($productFamily)
            );
        }

        if ($this->containsAny($productText, ['turbohd', 'camara de seguridad', 'videograbador'])
            && ! $this->containsAny($productText, ['dash cam', 'camara vehicular', 'vehiculo', 'mobile dvr', 'mdvr'])
            && $this->containsAny($path, ['autopartes', 'turbos', 'refacciones para autos'])) {
            return 'El texto corresponde a videovigilancia, pero la categoría pertenece a vehículos o autopartes.';
        }

        return null;
    }

    /**
     * @param array{family:?string,scores:array<string,int>,signals:array<string,list<string>>,ambiguous:bool} $product
     * @param array{family:?string,scores:array<string,int>,signals:array<string,list<string>>,ambiguous:bool} $category
     */
    private function confidence(array $product, array $category): int
    {
        $productScore = $product['family'] !== null ? (int) ($product['scores'][$product['family']] ?? 0) : 0;
        $categoryScore = $category['family'] !== null ? (int) ($category['scores'][$category['family']] ?? 0) : 0;

        if ($product['family'] !== null && $product['family'] === $category['family']) {
            return min(100, 70 + min(15, $productScore) + min(15, $categoryScore));
        }

        $confidence = 35;
        if ($product['family'] !== null) {
            $confidence += min(25, $productScore);
        }
        if ($category['family'] !== null) {
            $confidence += min(20, $categoryScore);
        }

        return min(95, max(0, $confidence));
    }

    private function contains(string $haystack, string $needle): bool
    {
        $needle = $this->normalize($needle);

        if (trim($needle) === '') {
            return false;
        }

        if (str_contains($haystack, $needle)) {
            return true;
        }

        // Algunos títulos SYSCOM unen conceptos mediante guiones, por ejemplo:
        // "Montaje-Sujetador Para Cable". Para la clasificación, el guion
        // también debe funcionar como separador de palabras.
        $haystackWithoutHyphens = ' '.trim((string) preg_replace(
            '/\s+/u',
            ' ',
            str_replace('-', ' ', $haystack)
        )).' ';

        $needleWithoutHyphens = ' '.trim((string) preg_replace(
            '/\s+/u',
            ' ',
            str_replace('-', ' ', $needle)
        )).' ';

        return trim($needleWithoutHyphens) !== ''
            && str_contains($haystackWithoutHyphens, $needleWithoutHyphens);
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function familyLabel(string $family): string
    {
        return [
            'dash_cam' => 'Dash Cam/cámara vehicular',
            'video_intercom' => 'videoportero/intercomunicador',
            'solar_mount' => 'montaje solar',
            'solar_meter' => 'medidor de energía',
            'solar_cable' => 'cable o conector solar',
            'poe_injector' => 'inyector PoE',
            'network_switch' => 'switch de red',
            'olt' => 'OLT/fibra óptica',
            'wireless_antenna' => 'antena inalámbrica',
            'router' => 'router/ONT Wi-Fi',
            'dvr_nvr' => 'DVR/NVR de videovigilancia',
            'surveillance_camera' => 'cámara de seguridad',
            'balun' => 'balun/convertidor A/V',
            'alarm' => 'alarma o sensor',
            'ups' => 'UPS/no-break',
            'power_supply' => 'fuente de alimentación',
            'pdu' => 'PDU/regleta eléctrica',
            'lock_key' => 'llave o repuesto de cerradura',
            'cable_organizer' => 'organizador o sujetador de cables',
            'tool_kit' => 'kit de herramientas',
            'flashlight' => 'linterna',
            'electrical_connector' => 'conector eléctrico',
            'tire' => 'llanta/neumático',
        ][$family] ?? $family;
    }
}
