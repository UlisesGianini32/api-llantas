<?php

/**
 * Catálogo SYSCOM: solo la sucursal indicada (por defecto Hermosillo) para publicaciones y stock.
 */
return [

    'sucursal_nombre' => env('SYSCOM_SUCURSAL_NOMBRE', 'hermosillo'),

    /**
     * Base de costo para fórmulas (rule_set=syscom).
     * - lista, especial, descuento: un solo campo; especial usa lista/descuento si el especial es 0.
     * - min: menor de los tres con filtro: si un valor es 10x menor que el mayor y < 5’000, se descarta
     *   (evita 1, 10 o un campo raro en la API que baje el costo; re-sync tras fix de importes).
     * Por defecto: especial (mismo criterio que el precio de venta con IVA en ficha, ~14k).
     * Alternativas: lista, descuento, min (con filtro anti-outliers).
     */
    'costo_base' => env('SYSCOM_COSTO_BASE', 'especial'),

    /**
     * Si true, GET /productos/{id}?sucursal=... Algunas cuentas devuelven existencia vacía con esto;
     * en false (recomendado) se pide el detalle completo y el stock se resuelve en código.
     */
    'get_product_with_sucursal_query' => filter_var(
        env('SYSCOM_GET_PRODUCT_SUCURSAL_QUERY', false),
        FILTER_VALIDATE_BOOL
    ),

    /**
     * Prefijo de seller_sku y SKU interno en publicaciones / cola
     */
    'sku_prefix' => 'SYSCOM',

    /**
     * GET /productos: la API exige al menos uno de: busqueda, marca, categoria.
     * Término por defecto para listar (ej. letra o palabra clara; vacío = "a").
     * Para catálogo amplio, probá términos distintos o --busqueda en el comando.
     */
    'default_productos_busqueda' => env('SYSCOM_DEFAULT_BUSQUEDA', 'a'),

    /**
     * Alternativa: filtrar por id de categoría SYSCOM (si no usas busqueda)
     */
    'default_productos_categoria' => env('SYSCOM_DEFAULT_CATEGORIA', ''),

    /**
     * Alternativa: filtrar por marca
     */
    'default_productos_marca' => env('SYSCOM_DEFAULT_MARCA', ''),

    /**
     * Términos para --sweep. NO incluyas un solo dígito "0"-"9": la API lo toma como id de
     * categoría y devuelve 404. Usa letras, ñ, o términos de 2+ caracteres (ej. "12", "cable").
     */
    'busqueda_sweep_terms' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            (string) env(
                'SYSCOM_BUSQUEDA_SWEEP',
                'a,b,c,d,e,f,g,h,i,j,k,l,m,n,ñ,o,p,q,r,s,t,u,v,w,x,y,z'
            )
        )
    ), fn (string $t) => $t !== '')),

    /**
     * Si es true, el job del panel (Sincronizar catálogo) usa --sweep (a–z + términos extra).
     * La API no lista todo el catálogo en una sola llamada; sin sweep solo se importa busqueda "a".
     */
    'default_sync_sweep' => filter_var(env('SYSCOM_SYNC_SWEEP', true), FILTER_VALIDATE_BOOL),

    /**
     * Tras el sweep (o sync base), recorre marcas conocidas (filtro API `marca`).
     * Cubre líneas como EPCOM que no salen solo con letras sueltas.
     */
    'marca_sweep_enabled' => filter_var(env('SYSCOM_MARCA_SWEEP_ENABLED', true), FILTER_VALIDATE_BOOL),

    'marca_sweep_terms' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            (string) env(
                'SYSCOM_MARCA_SWEEP',
                'EPCOM POWERLINE,HIKVISION,Dahua,TP-LINK,Ubiquiti,Steren,Planet'
            )
        )
    ), fn (string $t) => $t !== '')),

    /**
     * Barrido por categorías SYSCOM (GET /productos?categoria=…&sucursal=Hermosillo&stock=1).
     * Los IDs raíz son las categorías nivel 1 de la API; se expanden subcategorías automáticamente.
     *
     * @see https://developers.syscom.mx/api/v1/categorias — ej. 30 = Energía (incluye Energía Solar).
     */
    'categoria_sweep_enabled' => filter_var(env('SYSCOM_CATEGORIA_SWEEP_ENABLED', true), FILTER_VALIDATE_BOOL),

    /** IDs raíz SYSCOM (nivel 1). Vacío = todas las categorías base de /categorias. */
    'categoria_sweep_root_ids' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            (string) env(
                'SYSCOM_CATEGORIA_SWEEP_ROOTS',
                '22,25,26,27,30,32,37,38,65811'
            )
        )
    ), fn (string $t) => $t !== '')),

    /** IDs extra (subcategorías concretas) sin recorrer árbol. */
    'categoria_sweep_extra_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SYSCOM_CATEGORIA_SWEEP_EXTRA', ''))
    ), fn (string $t) => $t !== '')),

    /** Tope de categorías a sincronizar (evita jobs eternos). */
    'categoria_sweep_max_ids' => max(10, (int) env('SYSCOM_CATEGORIA_SWEEP_MAX', 400)),

    /** Pausa entre categorías en el job (segundos). */
    'categoria_sweep_pause_s' => max(0, (int) env('SYSCOM_CATEGORIA_SWEEP_PAUSE_S', 1)),

    /** Minutos de cache del árbol de categorías (subcategorías). */
    'categoria_tree_cache_minutes' => max(5, (int) env('SYSCOM_CATEGORIA_TREE_CACHE_MINUTES', 1440)),

    /**
     * Solo persistir productos con stock > 0 en la sucursal configurada (Hermosillo).
     * La API ya filtra stock=1 + sucursal; esto evita filas locales sin existencia real.
     */
    'import_only_hermosillo_stock' => filter_var(env('SYSCOM_IMPORT_ONLY_HERMOSILLO_STOCK', true), FILTER_VALIDATE_BOOL),

    /**
     * Importación bajo demanda desde el buscador del panel (POST import-search).
     */
    'on_demand_import_max_pages' => max(1, (int) env('SYSCOM_ON_DEMAND_IMPORT_MAX_PAGES', 50)),

    /**
     * Durante syscom:sync-products: si el listado no trae USD, pide detalle automáticamente
     * y no borra precios ya guardados. Evita "— revisar costo" tras cada sincronización.
     */
    'sync_hydrate_missing_prices' => filter_var(env('SYSCOM_SYNC_HYDRATE_PRICES', true), FILTER_VALIDATE_BOOL),

    /** Pausa en microsegundos entre llamadas a la API (sweep + detalle) para no recibir 429. */
    'api_delay_us' => (int) env('SYSCOM_API_DELAY_US', 350_000),

    'oauth_max_attempts' => (int) env('SYSCOM_OAUTH_MAX_ATTEMPTS', 6),

    'request_max_attempts' => (int) env('SYSCOM_REQUEST_MAX_ATTEMPTS', 5),

    /**
     * Scraper del portal SYSCOM (www.syscom.mx) para sacar el desglose de existencia
     * por sucursal. La API pública (developers.syscom.mx) no expone ese desglose en
     * todas las cuentas (devuelve `existencia.detalle: []` aunque haya stock en HMO).
     *
     * Endpoint: GET https://www.syscom.mx/api/productos/{id}/existencias
     * Auth: normalmente no hace falta (el endpoint es público). Cookies opcionales si Cloudflare
     * bloquea el IP del servidor (cf_clearance + session desde DevTools → Network → existencias).
     *
     * Para activarlo:
     *   1) `SYSCOM_PORTAL_SCRAPE_ENABLED=true`
     *   2) Probá: `php artisan syscom:test-portal-scrape 228524 --branch=hermosillo`
     *   3) Si falla con 403/bloqueo, copiá Cookie del cURL o Application → Cookies → syscom.mx
     *      y pegá en `SYSCOM_PORTAL_COOKIES="..."`.
     */
    'portal_scrape_enabled' => filter_var(env('SYSCOM_PORTAL_SCRAPE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'portal_base_url' => rtrim((string) env('SYSCOM_PORTAL_BASE_URL', 'https://www.syscom.mx'), '/'),
    'portal_cookies' => (string) env('SYSCOM_PORTAL_COOKIES', ''),
    'portal_user_agent' => (string) env(
        'SYSCOM_PORTAL_USER_AGENT',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'
    ),
    'portal_timeout_s' => max(5, (int) env('SYSCOM_PORTAL_TIMEOUT_S', 20)),
    'portal_cache_minutes' => max(0, (int) env('SYSCOM_PORTAL_CACHE_MINUTES', 5)),

    /** Segundos de pausa entre términos en modo --sweep. */
    'sweep_pause_between_terms_s' => (int) env('SYSCOM_SWEEP_PAUSE_S', 2),

    /**
     * SYSCOM API devuelve los precios en USD. Para convertir a MXN se aplica el tipo de cambio
     * de SYSCOM (/tipocambio).
     *
     * tc_kind: cuál tipo de cambio usar.
     *   - preferencial (recomendado, suele ser el que le aplica el sitio web)
     *   - normal
     *   - un_dia / una_semana / dos_semanas / tres_semanas / un_mes (futuros, opcionales)
     *
     * tc_fallback: si la API falla y no hay TC cacheado, usar este valor.
     *
     * tc_cache_minutes: minutos de cache del TC (se refresca pasado ese tiempo).
     */
    'tc_kind' => env('SYSCOM_TC_KIND', 'preferencial'),
    'tc_fallback' => (float) env('SYSCOM_TC_FALLBACK', 17.5),
    'tc_cache_minutes' => (int) env('SYSCOM_TC_CACHE_MINUTES', 60),

    /**
     * IVA aplicado al convertir el costo USD → MXN antes de pasar a la fórmula.
     * 16 = 16% (default Mx). Usar 0 si querés alimentar la fórmula con costo sin IVA.
     */
    'iva_pct' => (float) env('SYSCOM_IVA_PCT', 16),

    /**
     * Precio de lista público en Mercado Libre para productos SYSCOM (solo lo que arma priceFor()).
     *
     * - meli_public_price_floor_mxn: después de la fórmula, el precio nunca será menor que este valor.
     *   Ej.: 700 para no dejar públicos muy bajos donde comisión + envío gratis comen casi todo el cobro.
     *   0 = desactivado (comportamiento anterior).
     * - meli_public_price_min_above_cost_mxn: el precio nunca será menor que costo_mx + este monto (MXN).
     *   Opcional si además necesitás cubrir SIEMPRE un hueco estimado sobre el costo. 0 = desactivado.
     * - meli_min_estimated_net_profit_mxn: sube el precio de lista para que (Recibes ~ estimado) >= costo + este monto.
     *   Requiere SYSCOM_MELI_ESTIMATE_* (comisión, impuestos, envío) consistentes con el simulador ML. 0 = desactivado.
     */
    'meli_public_price_floor_mxn' => max(0.0, (float) env('SYSCOM_MELI_PUBLIC_PRICE_FLOOR_MXN', 0)),
    'meli_public_price_min_above_cost_mxn' => max(0.0, (float) env('SYSCOM_MELI_PUBLIC_MIN_ABOVE_COST_MXN', 0)),
    'meli_min_estimated_net_profit_mxn' => max(0.0, (float) env('SYSCOM_MELI_MIN_ESTIMATED_NET_PROFIT_MXN', 100)),

    /**
     * Si domain_discovery no devuelve sugerencias (título raro, API vacía), publicar igual usando
     * esta categoría ML México (category_id, ej. MLM123456). Vacío = no hay fallback.
     */
    'meli_fallback_category_id' => trim((string) env('SYSCOM_MELI_FALLBACK_CATEGORY_ID', '')),

    /**
     * Si está definido (MLM…), los DVR/NVR de videovigilancia usan esta categoría en lugar de domain_discovery.
     * Evita que ML clasifique grabadores como "Bocinas" por "Audio" en el título.
     */
    'meli_dvr_category_id' => trim((string) env('SYSCOM_MELI_DVR_CATEGORY_ID', '')),

    /**
     * Cámaras de videovigilancia (Eyeball, Turret, Bullet, TurboHD, etc.; no DVR ni kits).
     * Por defecto MLM437575 = Hogar → Seguridad → Sistemas de Monitoreo → Cámaras de Seguridad.
     * domain_discovery confunde «TURBOHD» con turbos de auto (MLM164668) si no se fuerza esta categoría.
     */
    'meli_camera_category_id' => trim((string) env('SYSCOM_MELI_CAMERA_CATEGORY_ID', 'MLM437575')),

    /**
     * Videoporteros / monitores y accesorios de portero eléctrico (Hikvision DS-KH, etc.). No son cámaras CCTV.
     * domain_discovery y la marca Hikvision suelen mandarlos a Cámaras de Seguridad por error.
     * Por defecto MLM437573 = Hogar → Seguridad → Sistemas de Monitoreo → Porteros Eléctricos.
     */
    'meli_videoportero_category_id' => trim((string) env('SYSCOM_MELI_VIDEOPORTERO_CATEGORY_ID', 'MLM437573')),

    /**
     * Switches de red (no routers): category_id MLM fijo. Evita que un "Switch …" caiga en Routers o Hubs USB.
     * Por defecto MLM1708 = Computación → Conectividad y Redes → Interruptores de Red.
     */
    'meli_switch_category_id' => trim((string) env('SYSCOM_MELI_SWITCH_CATEGORY_ID', 'MLM1708')),

    /**
     * Inyectores / adaptadores PoE de pared (Epcom ADPOE, etc.). No son switches ni montajes solares.
     * Por defecto MLM190973 = Computación → Conectividad y Redes → Inyectores Poe.
     */
    'meli_poe_injector_category_id' => trim((string) env('SYSCOM_MELI_POE_INJECTOR_CATEGORY_ID', 'MLM190973')),

    /**
     * Accesorios de alarmas e intrusión (módulos expansores Honeywell 4219, etc.).
     * domain_discovery suele mandarlos a Termostatos Digitales por la marca Honeywell Home / Resideo.
     * Por defecto MLM168470 = Hogar → Seguridad → Sistemas de Monitoreo → Alarmas y Sensores.
     */
    'meli_alarm_category_id' => trim((string) env('SYSCOM_MELI_ALARM_CATEGORY_ID', 'MLM168470')),

    /**
     * Fuentes de poder / alimentación conmutada (Epcom PLK, fuentes CCTV, etc.). No son cámaras ni montajes solares.
     * Por defecto MLM420366 = Electrónica → Componentes Electrónicos → Fuentes Conmutadas.
     */
    'meli_power_supply_category_id' => trim((string) env('SYSCOM_MELI_POWER_SUPPLY_CATEGORY_ID', 'MLM420366')),

    /**
     * PDU / barras de distribución de energía para rack (CyberPower PDU15, etc.). No son UPS/no-break.
     * domain_discovery suele mandarlos a No Breaks por la marca CyberPower.
     * Por defecto MLM171884 = Construcción → Componentes Eléctricos → Cables y Accesorios → Multicontactos.
     */
    'meli_pdu_category_id' => trim((string) env('SYSCOM_MELI_PDU_CATEGORY_ID', 'MLM171884')),

    /**
     * Kits de videovigilancia (cámaras + grabador). Evita publicar un "Kit …" solo en Grabadores DVR.
     * Por defecto MLM417835 = Hogar → Seguridad → Sistemas de Monitoreo → Kits de Seguridad.
     */
    'meli_kit_videovigilancia_category_id' => trim((string) env('SYSCOM_MELI_KIT_VIDEO_CATEGORY_ID', 'MLM417835')),

    /**
     * Juegos/kits de herramientas manuales (maletín con llaves, desarmadores, pinzas, etc.).
     * domain_discovery suele mandarlos a Caza > Repuestos por «Precision» + «144 piezas».
     * Por defecto MLM189825 = Herramientas → Herramientas Manuales → Kits de Herramientas → Kit Combinadas.
     */
    'meli_tool_kit_category_id' => trim((string) env('SYSCOM_MELI_TOOL_KIT_CATEGORY_ID', 'MLM189825')),

    /**
     * Linternas LED recargables o a pilas (Maglite, etc.).
     * Por defecto MLM47781 = Deportes y Fitness → Camping, Caza y Pesca → Linternas y Faroles → Linternas.
     */
    'meli_flashlight_category_id' => trim((string) env('SYSCOM_MELI_FLASHLIGHT_CATEGORY_ID', 'MLM47781')),

    /**
     * Medidores inteligentes / exportación cero para fotovoltaica (Hoymiles DTSU666, DTU-666, etc.).
     * NO son paneles, inversores ni rieles. ML suele mandarlos a Paneles Solares y los finaliza.
     * Por defecto MLM189958 = Construcción → Componentes Eléctricos → Tableros y Medidores → Medidores de Energía.
     */
    'meli_solar_meter_category_id' => trim((string) env('SYSCOM_MELI_SOLAR_METER_CATEGORY_ID', 'MLM189958')),

    /**
     * Rieles, estructuras y accesorios de montaje para paneles/módulos fotovoltaicos (no son paneles solares).
     * Por defecto MLM439043 = Construcción → Componentes Eléctricos → Energía Solar → Otros.
     * domain_discovery suele sugerir MLM180875 (Paneles Solares) cuando el título dice "módulos fotovoltaicos".
     */
    'meli_solar_mount_category_id' => trim((string) env('SYSCOM_MELI_SOLAR_MOUNT_CATEGORY_ID', 'MLM439043')),

    /**
     * Cables eléctricos para controladores solares / paneles (Epcom CBL-8AWG, etc.). No cargadores USB/tablet.
     * Por defecto MLM455358 = Construcción → Componentes Eléctricos → Cables y Accesorios → Cables para Paneles Solares.
     */
    'meli_solar_cable_category_id' => trim((string) env('SYSCOM_MELI_SOLAR_CABLE_CATEGORY_ID', 'MLM455358')),

    /**
     * Baluns / transceptores de audio o video para CCTV (hardware, no software).
     * Por defecto MLM433590 = Electrónica → Accesorios A/V → Convertidores de Audio y Video.
     * domain_discovery puede mandar «Software > Redes y Servidores» si el título dice «audio» + «extensor».
     */
    'meli_balun_category_id' => trim((string) env('SYSCOM_MELI_BALUN_CATEGORY_ID', 'MLM433590')),

    /**
     * Conectores eléctricos / bloques push para CCTV y alimentación (Epcom Powerline PCON, etc.).
     * domain_discovery suele mandarlos a Celulares › Radiofrecuencia › Cables y Conectores.
     * Por defecto MLM44571 = Electrónica → Componentes Electrónicos → Conectores.
     */
    'meli_connector_category_id' => trim((string) env('SYSCOM_MELI_CONNECTOR_CATEGORY_ID', 'MLM44571')),

    /**
     * Routers / ONT con Wi‑Fi (Huawei FG736, etc.). No fuentes de poder ni switches.
     * domain_discovery a veces los manda a Fuentes Conmutadas por «12V» en la ficha.
     * Por defecto MLM5015 = Computación → Conectividad y Redes → Routers.
     */
    'meli_router_category_id' => trim((string) env('SYSCOM_MELI_ROUTER_CATEGORY_ID', 'MLM5015')),

    /**
     * OLT / terminales de línea óptica GPON-EPON (central FTTH, no routers ni ONUs).
     * Por defecto MLM1711 = Computación → Conectividad y Redes → Otros.
     * domain_discovery suele mandar OLT a Routers por «GPON» + «ruteo» en la ficha.
     */
    'meli_olt_category_id' => trim((string) env('SYSCOM_MELI_OLT_CATEGORY_ID', 'MLM1711')),

    /**
     * Antenas inalámbricas / Wi‑Fi / enlaces (Mikrotik MTAD, MANT, Ubiquiti dish, etc.). No routers ni AP completos.
     * Por defecto MLM7642 = Computación → Conectividad y Redes → Antenas.
     * domain_discovery suele mandar antenas Mikrotik a Routers por la marca.
     */
    'meli_antenna_category_id' => trim((string) env('SYSCOM_MELI_ANTENNA_CATEGORY_ID', 'MLM7642')),

    /**
     * Estimador de "Recibes" en panel SYSCOM (alineado al simulador ML):
     *   precio − (precio×fee_sale_pct/100) − (precio×tax_retention_pct/100) − shipping − financiamiento
     * Es aproximado; los montos reales dependen categoría, listing Clásica/Premium, paquete, ruta, RFC, etc.
     *
     * --- Comisión Mercado Libre (solo la plataforma, no impuestos) ---
     * Depende del tipo de publicación y categoría ML. Referencias orientativas (revisá la tuya en Gestión ML o
     * https://www.mercadolibre.com.mx/ayuda/Costos-de-vender-un-producto_870 ): Clásica suele estar ~8%-16%
     * según categoría; Premium suele ser mayor. Ej.: Construcción/Herramientas aparecen seguido ~13.5%-15%
     * en Clásica (tablas de ayuda/blog; pueden cambiar).
     * Cargo fijo EXTRA en listing Clásica si precio lista < $299: ~$25 (<$99), ~$30 ($99-$149), ~$37 ($149-$299);
     * Premium no lleva ese fijo por tramo en la doc pública habitual. Este estimador NO suma ese fijo solo:
     * podés “meterlo” aumentando FINANCING o SHIPPING mentalmente o subiendo fee % en productos muy baratos.
     *
     * --- Envío (lo que vos absorbés con “envío gratis”) ---
     * No hay un solo número: peso vs volumétrico, Medios de envíos ML, reputación y distancia. ML publica ayuda en
     * https://www.mercadolibre.com.mx/ayuda/costos-envios-gratis_3287 — copiá de una publicación real el “Pagás $…”
     * de envío y usalo como SYSCOM_MELI_ESTIMATE_SHIPPING_MXN hasta que varíen tarifas.
     *
     * --- Retenciones fiscales (SAT vía Mercado Libre) ---
     * Con RFC cargado ML retiene ante el SAT parte de tus impuestos (reglas de plataformas tecnológicas; reformas 2026).
     * Referencias frecuentes con RFC para 2026: ISR ~2.5% sobre ingreso y retención de IVA alrededor del 50% del IVA
     * de la operación (suele explicarse como ~8% del precio cuando el IVA estándar va al 16% — verifica con ML/SAT/contador).
     * Sin RFC aplican tasas MUCHO más altas (doc ML: ¿Quiénes están alcanzados por retenciones?).
     * tax_retention_pct: lo que muestra ML como línea \"Impuestos\" en el simulador, como % sobre el precio
     * (ej. si Impuestos = $737.67 y Precio = $8768.80 → ~8.41%).
     *
     * --- Financiamiento / otros ---
     * “Por financiamiento hasta $X”: usá ese tope como SYSCOM_MELI_ESTIMATE_FINANCING_MXN si ML lo muestra en tu SKU.
     */
    'meli_recibes_estimate' => [
        'fee_sale_pct' => max(0.0, (float) env('SYSCOM_MELI_ESTIMATE_FEE_SALE_PCT', 0)),
        'tax_retention_pct' => max(0.0, (float) env('SYSCOM_MELI_ESTIMATE_TAX_RETENTION_PCT', 0)),
        'shipping_absorb_mxn' => max(0.0, (float) env('SYSCOM_MELI_ESTIMATE_SHIPPING_MXN', 0)),
        'financing_max_mxn' => max(0.0, (float) env('SYSCOM_MELI_ESTIMATE_FINANCING_MXN', 0)),
    ],

    /**
     * Normalizador de imágenes SYSCOM → ML.
     *
     * ML rechaza fotos que no cumplan tamaño mínimo, posición y proporción.
     * Para evitar bloqueos como "Detectamos que tu foto: No cumple con el tamaño
     * mínimo, posición y proporción de producto", el sistema descarga cada imagen
     * de SYSCOM, la centra sobre un lienzo blanco cuadrado y la reescala para que
     * el producto ocupe ~92% del cuadro.
     *
     *  - enabled: true para procesar imágenes; false para mandar las URLs de SYSCOM tal cual.
     *  - use_meli_upload: true → subir el JPG directo a ML (POST /pictures/items/upload) y
     *    referenciarlo por `id`. Recomendado: ML aloja la foto en su CDN, no depende del
     *    storage público de mrpoolhmo.com y elimina los rechazos por descarga fallida.
     *    false → comportamiento legacy: guardar el JPG en `disk` y mandar `source`.
     *  - disk / folder: solo se usan cuando use_meli_upload es false (modo legacy).
     *  - final_size: lado del cuadrado final (ML recomienda 1200, mínimo aceptable 500).
     *  - product_ratio: proporción del producto en el cuadro (0.92 = 92%, suele alcanzar para
     *    pasar la regla de "proporción"). Bajalo si ML sigue marcando la imagen.
     *  - jpeg_quality: 60-95.
     */
    'image_normalizer' => [
        'enabled' => filter_var(env('SYSCOM_IMAGE_NORMALIZER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'use_meli_upload' => filter_var(env('SYSCOM_IMAGE_NORMALIZER_USE_MELI_UPLOAD', true), FILTER_VALIDATE_BOOL),
        'disk' => env('SYSCOM_IMAGE_NORMALIZER_DISK', 'public'),
        'folder' => env('SYSCOM_IMAGE_NORMALIZER_FOLDER', 'syscom_meli'),
        'final_size' => (int) env('SYSCOM_IMAGE_NORMALIZER_FINAL_SIZE', 1200),
        'product_ratio' => (float) env('SYSCOM_IMAGE_NORMALIZER_PRODUCT_RATIO', 0.92),
        'jpeg_quality' => (int) env('SYSCOM_IMAGE_NORMALIZER_JPEG_QUALITY', 92),
        /**
         * Si ancho/alto supera esta proporción, se recorta un cuadrado central antes de encajar
         * en el lienzo. Evita que fotos tipo banner queden con un lado ~10 px y ML rechace (mín.
         * ~50 px en el lado corto según validación de la API).
         */
        'max_aspect_before_crop' => (float) env('SYSCOM_IMAGE_MAX_ASPECT_BEFORE_CROP', 2.5),
        /**
         * ML suele rechazar la portada SYSCOM ("logos y/o textos"). Es un banner de marketing;
         * las fotos de galería suelen ser solo el producto. true = la primera imagen del ítem
         * es la primera de galería; img_portada queda al final (o se omite si parece banner).
         */
        'prefer_gallery_over_portada' => filter_var(
            env('SYSCOM_IMAGE_PREFER_GALLERY_OVER_PORTADA', true),
            FILTER_VALIDATE_BOOL
        ),
        /** Si la URL de portada parece banner/promo, no subirla (solo galería). */
        'omit_marketing_portada' => filter_var(
            env('SYSCOM_IMAGE_OMIT_MARKETING_PORTADA', true),
            FILTER_VALIDATE_BOOL
        ),
    ],

    /**
     * Empaque vendedor (ML) cuando la categoría exige medidas y no salen de la ficha SYSCOM.
     * Solo se aplica si título/modelo parecen llanta; si no, siguen los genéricos 25×25×20 cm / 2 kg.
     */
    'fallback_package_tire' => [
        'height_cm' => (float) env('SYSCOM_PACKAGE_TIRE_H_CM', 28),
        'width_cm' => (float) env('SYSCOM_PACKAGE_TIRE_W_CM', 72),
        'length_cm' => (float) env('SYSCOM_PACKAGE_TIRE_L_CM', 72),
        'weight_g' => (int) env('SYSCOM_PACKAGE_TIRE_WEIGHT_G', 12000),
    ],

    /**
     * Crear automáticamente pedido en SYSCOM cuando una orden ML quede pagada.
     *
     * Entrega: siempre `tipo_entrega` = sucursal (retiro en mostrador / sucursal SYSCOM, no envío a cliente).
     * El PDF de SYSCOM puede seguir mostrando textos logísticos internos; lo que define el retiro es
     * `tipo_entrega` + `direccion.sucursal` (p. ej. hermosillo). No hace falta “enviar” mercancía a otra ciudad
     * si operás en la misma ciudad: igual vas a la sucursal con el folio.
     *
     * Pago en POST /carrito/generar (ver developers.syscom.mx → carrito/pago):
     * - `metodo_pago` = ID interno de forma.pue|ppd (ej. "5"), NO el código SAT.
     * - `tipo_pago`   = pue|ppd → en PDF “Método de pago” (una sola exhibición / parcialidades).
     * - Código SAT 04 = tarjeta de crédito → en PDF “Forma de pago” (vía resumen.codigo_pago).
     * Si `SYSCOM_ORDER_METODO_PAGO_ID` está vacío, se consulta GET /carrito/pago y se elige por prefer + SAT.
     *
     * Fletera: por defecto no se envía `fletera` en pedidos sucursal (retiro). Activá solo si SYSCOM te pide
     * explícitamente envío con fletera en sucursal: SYSCOM_ORDER_SEND_FLETERA_WITH_SUCURSAL=true + SYSCOM_ORDER_FLETERA_ID.
     *
     * PDF «CONDICIONADO A PAGO»: es normal en pedidos API con tarjeta de crédito — el folio existe pero SYSCOM
     * retiene el surtido hasta confirmar pago (portal SYSCOM, transferencia o línea de crédito según tu cuenta).
     * Listar métodos: php artisan syscom:order-pago-methods
     */
    'orders_from_meli' => [
        'enabled' => filter_var(env('SYSCOM_ORDERS_FROM_MELI_ENABLED', true), FILTER_VALIDATE_BOOL),
        'metodo_pago_id' => env('SYSCOM_ORDER_METODO_PAGO_ID', ''),
        /**
         * Si metodo_pago_id está vacío: filtro sobre GET /carrito/pago.
         * - Una palabra: credito, transferencia, sucursal
         * - Varias (todas obligatorias): sucursal+tarjeta+credito → Tarjeta de Crédito en sucursal (codigo_sat 04)
         */
        'metodo_pago_prefer' => env('SYSCOM_ORDER_METODO_PAGO_PREFER', 'tarjeta+credito'),
        /** Código SAT forma de pago (04 = tarjeta crédito). Filtra filas de /carrito/pago; no se envía como metodo_pago. */
        'forma_pago_sat' => env('SYSCOM_ORDER_FORMA_PAGO_SAT', '04'),
        'uso_cfdi_id' => env('SYSCOM_ORDER_USO_CFDI_ID', ''),
        'fletera_id' => env('SYSCOM_ORDER_FLETERA_ID', ''),
        'send_fletera_with_sucursal' => filter_var(env('SYSCOM_ORDER_SEND_FLETERA_WITH_SUCURSAL', false), FILTER_VALIDATE_BOOL),
        /** Valor de `direccion.atencion_a` en el payload (por defecto sucursal = retiro en sucursal). */
        'atencion_a' => env('SYSCOM_ORDER_ATENCION_A', 'sucursal'),
        /**
         * Código de sucursal de surtido para el pedido. Si se deja vacío, el sistema resuelve
         * automáticamente el código numérico de `sucursal_nombre` (Hermosillo) vía GET /carrito/sucursales,
         * igual que el catálogo. Así el pedido siempre apunta a la misma sucursal local.
         */
        'sucursal_codigo' => env('SYSCOM_ORDER_SUCURSAL_CODIGO', ''),
        /**
         * Resolver el código numérico de la sucursal (Hermosillo) cuando `sucursal_codigo` está vacío,
         * en lugar de mandar el nombre como texto. Evita que SYSCOM use su sucursal por defecto.
         */
        'resolve_branch_code' => filter_var(env('SYSCOM_ORDER_RESOLVE_BRANCH_CODE', true), FILTER_VALIDATE_BOOL),
        /**
         * `forzar` en POST /carrito/generar. true = SYSCOM crea el pedido aunque la sucursal indicada
         * no tenga stock, surtiéndolo desde OTRA sucursal (p. ej. Culiacán). false (recomendado) = solo
         * surte desde la sucursal local; si no hay stock ahí, el pedido falla y NO se compra de otro lado.
         */
        'forzar' => filter_var(env('SYSCOM_ORDER_FORZAR', false), FILTER_VALIDATE_BOOL),
        'tipo_pago' => env('SYSCOM_ORDER_TIPO_PAGO', 'pue'),
        'testmode' => filter_var(env('SYSCOM_ORDER_TESTMODE', false), FILTER_VALIDATE_BOOL),
        'directo_cliente' => filter_var(env('SYSCOM_ORDER_DIRECTO_CLIENTE', false), FILTER_VALIDATE_BOOL),
        /**
         * Un solo proceso a la vez por orden ML al llamar a SYSCOM (evita doble POST si webhook y sync
         * coinciden). Requiere driver de cache con locks (file, redis, etc.). false = sin lock.
         */
        'use_sync_lock' => filter_var(env('SYSCOM_ORDER_USE_SYNC_LOCK', true), FILTER_VALIDATE_BOOL),
        /**
         * Cancelar pedido SYSCOM cuando la orden ML pasa a cancelada/invalid.
         * La guía pública de SYSCOM no documenta el endpoint; confirma la ruta con soporte SYSCOM
         * y fíjala en SYSCOM_ORDER_CANCEL_PATH (ej. /carrito/cancelar).
         *
         * Si SYSCOM_ORDER_CANCEL_PATH está vacío, se prueban cancel_path_candidates (solo ante 404).
         */
        'cancel_enabled' => filter_var(env('SYSCOM_ORDER_CANCEL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'cancel_method' => strtoupper(trim((string) env('SYSCOM_ORDER_CANCEL_METHOD', 'POST'))),
        'cancel_path' => trim((string) env('SYSCOM_ORDER_CANCEL_PATH', '')),
        'cancel_path_candidates' => array_values(array_filter(array_map(
            'trim',
            explode(
                ',',
                (string) env(
                    'SYSCOM_ORDER_CANCEL_PATH_CANDIDATES',
                    '/carrito/cancelar,/pedidos/cancelar,/carrito/cancelar-pedido'
                )
            )
        ), fn (string $p) => $p !== '')),
        'use_cancel_lock' => filter_var(env('SYSCOM_ORDER_CANCEL_USE_LOCK', true), FILTER_VALIDATE_BOOL),
    ],

    /**
     * Tras “Sincronizar catálogo SYSCOM”: cuántos productos sin precio USD consultar en detalle.
     * El listado /productos casi nunca trae precios; sin este paso verás “— revisar costo”.
     */
    'backfill_prices_limit' => max(50, (int) env('SYSCOM_BACKFILL_PRICES_LIMIT', 500)),
    /** Lotes consecutivos tras sync si no se usa --until-empty. */
    'backfill_prices_max_batches' => max(1, (int) env('SYSCOM_BACKFILL_PRICES_MAX_BATCHES', 5)),
    /** Con --until-empty: máximo de lotes (500 × 25 = hasta 12500 detalles por sync). */
    'backfill_prices_until_empty_max_batches' => max(1, (int) env('SYSCOM_BACKFILL_UNTIL_EMPTY_MAX_BATCHES', 25)),
    'backfill_sleep_ms' => max(0, (int) env('SYSCOM_BACKFILL_SLEEP_MS', 300)),
];
