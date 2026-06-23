<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyCategoryResolverService
{
    /**
     * Categorías confirmadas por tus capturas.
     */
    private const CATEGORY_HAIR_TREATMENTS_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-14';
    private const CATEGORY_HAIR_TREATMENTS_NAME = 'Health & Beauty > Personal Care > Hair Care > Hair Treatments';

    private const CATEGORY_HAIR_CARE_KITS_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-1';
    private const CATEGORY_HAIR_CARE_KITS_NAME = 'Health & Beauty > Personal Care > Hair Care > Hair Care Kits';

    private const CATEGORY_HAIR_COLOR_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-2';
    private const CATEGORY_HAIR_COLOR_NAME = 'Health & Beauty > Personal Care > Hair Care > Hair Color';

    private const CATEGORY_HAIR_COLORING_ACCESSORIES_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-4';
    private const CATEGORY_HAIR_COLORING_ACCESSORIES_NAME = 'Health & Beauty > Personal Care > Hair Care > Hair Coloring Accessories';

    private const CATEGORY_HAIR_STYLING_PRODUCTS_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-10';
    private const CATEGORY_HAIR_STYLING_PRODUCTS_NAME = 'Health & Beauty > Personal Care > Hair Care > Hair Styling Products';

    private const CATEGORY_SHAMPOO_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-13-3';
    private const CATEGORY_SHAMPOO_NAME = 'Health & Beauty > Personal Care > Hair Care > Shampoo & Conditioner > Shampoo';

    private const CATEGORY_CONDITIONER_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-13-1';
    private const CATEGORY_CONDITIONER_NAME = 'Health & Beauty > Personal Care > Hair Care > Shampoo & Conditioner > Conditioners';

    /** ML: Uñas esculpidas – Pinzas y clips para uñas → Shopify Nail Tools */
    private const CATEGORY_NAIL_TOOLS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-5-2';
    private const CATEGORY_NAIL_TOOLS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Cosmetic Tools > Nail Tools';

    /** category_id de Mercado Libre para “Pinzas y Clips para Uñas” */
    private const MELI_CATEGORY_PINZAS_CLIPS_UÑAS = 'MLM191995';

    /** ML: Tratamientos de Belleza – Fajas → Shopify Waist Cinchers (shapewear) */
    private const MELI_CATEGORY_FAJAS = 'MLM7806';
    private const CATEGORY_WAIST_CINCHERS_ID = 'gid://shopify/TaxonomyCategory/aa-1-6-10-5';
    private const CATEGORY_WAIST_CINCHERS_NAME = 'Apparel & Accessories > Clothing > Lingerie > Shapewear > Waist Cinchers';

    /** Shopify leaf usado en capturas para suplementos / multivitamínicos */
    private const CATEGORY_VITAMINS_SUPPLEMENTS_ID = 'gid://shopify/TaxonomyCategory/hb-1-9-6';
    private const CATEGORY_VITAMINS_SUPPLEMENTS_NAME = 'Health & Beauty > Health Care > Fitness & Nutrition > Vitamins & Supplements';

    /** Fijadores / setting sprays de maquillaje facial (captura Moira correcta) */
    private const CATEGORY_MAKEUP_FINISHING_SPRAYS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-6';
    private const CATEGORY_MAKEUP_FINISHING_SPRAYS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Makeup Finishing Sprays';

    /** ML Rostro – Iluminadores y Rubores → Face Makeup (taxonomía Shopify estándar) */
    private const CATEGORY_BLUSHES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-4-1-1';
    private const CATEGORY_BLUSHES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Face Makeup > Blushes & Bronzers > Blushes';

    private const CATEGORY_BRONZERS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-4-1-2';
    private const CATEGORY_BRONZERS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Face Makeup > Blushes & Bronzers > Bronzers';

    private const CATEGORY_HIGHLIGHTERS_LUMINIZERS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-4-5';
    private const CATEGORY_HIGHLIGHTERS_LUMINIZERS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Face Makeup > Highlighters & Luminizers';

    private const CATEGORY_FACE_PALETTES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-4-6';
    private const CATEGORY_FACE_PALETTES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Face Makeup > Face Palettes';

    /** ML Rostro – Correctores / concealer líquido o en barra */
    private const CATEGORY_CONCEALERS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-4-4-3';
    private const CATEGORY_CONCEALERS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Face Makeup > Foundations & Concealers > Concealers';

    /** ML Rostro – Bases de Maquillaje (foundations; a veces bronceadores listados ahí) */
    private const CATEGORY_FOUNDATIONS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-4-4-4';
    private const CATEGORY_FOUNDATIONS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Face Makeup > Foundations & Concealers > Foundations';

    /** Protección solar / bloqueadores */
    private const CATEGORY_SUNSCREEN_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-9-15';
    private const CATEGORY_SUNSCREEN_NAME = 'Health & Beauty > Personal Care > Skin Care > Sunscreen';

    /** Afeitado: gel / espuma / crema para afeitar */
    private const CATEGORY_SHAVING_CREAMS_ID = 'gid://shopify/TaxonomyCategory/hb-3-14-11';
    private const CATEGORY_SHAVING_CREAMS_NAME = 'Health & Beauty > Personal Care > Shaving & Grooming > Shaving Creams';

    /** Desodorantes / antitranspirantes */
    private const CATEGORY_ANTI_PERSPIRANT_ID = 'gid://shopify/TaxonomyCategory/hb-3-5-1';
    private const CATEGORY_ANTI_PERSPIRANT_NAME = 'Health & Beauty > Personal Care > Deodorants & Anti-Perspirant > Anti-Perspirant';

    private const CATEGORY_DEODORANTS_ID = 'gid://shopify/TaxonomyCategory/hb-3-5-2';
    private const CATEGORY_DEODORANTS_NAME = 'Health & Beauty > Personal Care > Deodorants & Anti-Perspirant > Deodorants';

    /** Lavado corporal, incluido "hair and body wash" after-sun */
    private const CATEGORY_BODY_WASH_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-1-5';
    private const CATEGORY_BODY_WASH_NAME = 'Health & Beauty > Personal Care > Bath & Body > Body Wash';

    /** Tratamientos para pestañas y cejas */
    private const CATEGORY_LASH_BROW_GROWTH_TREATMENTS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-3-6';
    private const CATEGORY_LASH_BROW_GROWTH_TREATMENTS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Eye Makeup > Lash & Brow Growth Treatments';

    private const CATEGORY_EYE_MAKEUP_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-3';
    private const CATEGORY_EYE_MAKEUP_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Eye Makeup';

    private const CATEGORY_FALSE_EYELASHES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-3-5';
    private const CATEGORY_FALSE_EYELASHES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Eye Makeup > False Eyelashes';

    /** Adhesivos para pestañas */
    private const CATEGORY_FALSE_EYELASH_ADHESIVE_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-5-1-8-1';
    private const CATEGORY_FALSE_EYELASH_ADHESIVE_NAME = 'Health & Beauty > Personal Care > Cosmetics > Cosmetic Tools > False Eyelash Accessories > False Eyelash Adhesive';

    private const CATEGORY_EYELASH_EXTENSION_ADHESIVES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-5-1-8-1-1';
    private const CATEGORY_EYELASH_EXTENSION_ADHESIVES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Cosmetic Tools > False Eyelash Accessories > False Eyelash Adhesive > Eyelash Extension Adhesives';

    private const CATEGORY_LASH_LIFT_ADHESIVES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-5-1-8-1-2';
    private const CATEGORY_LASH_LIFT_ADHESIVES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Cosmetic Tools > False Eyelash Accessories > False Eyelash Adhesive > Lash Lift Adhesives';

    private const CATEGORY_STRIP_EYELASH_ADHESIVE_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-5-1-8-1-3';
    private const CATEGORY_STRIP_EYELASH_ADHESIVE_NAME = 'Health & Beauty > Personal Care > Cosmetics > Cosmetic Tools > False Eyelash Accessories > False Eyelash Adhesive > Strip Eyelash Adhesive';

    /** Sombras para ojos */
    private const CATEGORY_EYE_SHADOWS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-3-2';
    private const CATEGORY_EYE_SHADOWS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Eye Makeup > Eye Shadows';

    private const CATEGORY_EYE_SHADOW_PALETTES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-3-2-1';
    private const CATEGORY_EYE_SHADOW_PALETTES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Eye Makeup > Eye Shadows > Eye Shadow Palettes';

    /** Cejas */
    private const CATEGORY_EYEBROW_ENHANCERS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-3-3';
    private const CATEGORY_EYEBROW_ENHANCERS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Eye Makeup > Eyebrow Enhancers';

    /** Maletas / maletines de maquillaje */
    private const CATEGORY_REFILLABLE_MAKEUP_CASES_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-5-1-11';
    private const CATEGORY_REFILLABLE_MAKEUP_CASES_NAME = 'Health & Beauty > Personal Care > Cosmetics > Cosmetic Tools > Refillable Makeup Palettes & Cases';

    /** Skin care: toners / astringents */
    private const CATEGORY_ASTRINGENTS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-9-17-1';
    private const CATEGORY_ASTRINGENTS_NAME = 'Health & Beauty > Personal Care > Skin Care > Toners & Astringents > Astringents';

    private const CATEGORY_TONERS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-9-17-2';
    private const CATEGORY_TONERS_NAME = 'Health & Beauty > Personal Care > Skin Care > Toners & Astringents > Toners';

    /** Labios */
    private const CATEGORY_LIP_BALMS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-9-9-1';
    private const CATEGORY_LIP_BALMS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Skin Care > Lip Balms & Treatments > Lip Balms';

    private const CATEGORY_MEDICATED_LIP_TREATMENTS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-9-9-2';
    private const CATEGORY_MEDICATED_LIP_TREATMENTS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Skin Care > Lip Balms & Treatments > Medicated Lip Treatments';

    private const CATEGORY_LIP_GLOSS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-5-2';
    private const CATEGORY_LIP_GLOSS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Lip Makeup > Lip Gloss';

    private const CATEGORY_LIPSTICKS_ID = 'gid://shopify/TaxonomyCategory/hb-3-2-6-5-6';
    private const CATEGORY_LIPSTICKS_NAME = 'Health & Beauty > Personal Care > Cosmetics > Makeup > Lip Makeup > Lipsticks';

    /** Joyería - pulseras */
    private const CATEGORY_BRACELETS_ID = 'gid://shopify/TaxonomyCategory/aa-6-3';
    private const CATEGORY_BRACELETS_NAME = 'Apparel & Accessories > Jewelry > Bracelets';

    /** Joyería - dijes */
    private const CATEGORY_CHARMS_PENDANTS_ID = 'gid://shopify/TaxonomyCategory/aa-6-5';
    private const CATEGORY_CHARMS_PENDANTS_NAME = 'Apparel & Accessories > Jewelry > Charms & Pendants';

    /** Extensiones de cabello y herramientas */
    private const CATEGORY_HAIR_EXTENSIONS_ID = 'gid://shopify/TaxonomyCategory/aa-2-14-3';
    private const CATEGORY_HAIR_EXTENSIONS_NAME = 'Apparel & Accessories > Clothing Accessories > Hair Accessories > Hair Extensions';

    private const CATEGORY_HAIR_EXTENSION_TOOLS_ID = 'gid://shopify/TaxonomyCategory/hb-3-10-11-4';
    private const CATEGORY_HAIR_EXTENSION_TOOLS_NAME = 'Health & Beauty > Personal Care > Hair Care > Hair Accessories > Hair Extension Tools & Accessories';

    /** ML México: “Pinzas - Pinzas de Extensión” (herramientas para extensiones de cabello / tape-in). */
    private const MELI_CATEGORY_PINZAS_EXTENSION_CABELLO = 'MLM188305';

    /** Aros de luz / iluminación de estudio */
    private const CATEGORY_STUDIO_LIGHTS_FLASHES_ID = 'gid://shopify/TaxonomyCategory/co-4-2-6';
    private const CATEGORY_STUDIO_LIGHTS_FLASHES_NAME = 'Cameras & Optics > Photography > Lighting & Studio > Studio Lights & Flashes';

    /**
     * ML: Muebles para Estética – Sillas para Estética → Shopify “Salon Chairs” (peluquería / maquillaje).
     * IDs vistos en listados (MLMX); si ML agrega otro hijo, también filtramos por texto de categoría.
     */
    private const MELI_CATEGORY_IDS_MUEBLES_ESTETICA_SILLAS = [
        'MLM183167',
        'MLM189363',
        'MLM188983',
    ];

    private const CATEGORY_SALON_CHAIRS_BARBER_ID = 'gid://shopify/TaxonomyCategory/bi-10-3-2';
    private const CATEGORY_SALON_CHAIRS_BARBER_NAME = 'Business & Industrial > Hairdressing & Cosmetology > Salon Chairs > Barber Chairs';

    private const CATEGORY_SALON_CHAIRS_STYLING_ID = 'gid://shopify/TaxonomyCategory/bi-10-3-4';
    private const CATEGORY_SALON_CHAIRS_STYLING_NAME = 'Business & Industrial > Hairdressing & Cosmetology > Salon Chairs > Styling Chairs';

    private const CATEGORY_SALON_CHAIRS_ALL_PURPOSE_ID = 'gid://shopify/TaxonomyCategory/bi-10-3-1';
    private const CATEGORY_SALON_CHAIRS_ALL_PURPOSE_NAME = 'Business & Industrial > Hairdressing & Cosmetology > Salon Chairs > All-Purpose Chairs';

    private const CATEGORY_SALON_CHAIRS_SHAMPOO_ID = 'gid://shopify/TaxonomyCategory/bi-10-3-3';
    private const CATEGORY_SALON_CHAIRS_SHAMPOO_NAME = 'Business & Industrial > Hairdressing & Cosmetology > Salon Chairs > Shampoo Chairs';

    /** ML México: Muebles para Estética – Carros Auxiliares (carritos con charolas, auxiliares de salón). */
    private const MELI_CATEGORY_ID_CARROS_AUXILIARES = 'MLM189365';

    /** Shopify: carritos / auxiliares con ruedas (evita HB “Hair Color” por palabras ruidosas en el listado). */
    private const CATEGORY_FURNITURE_CARTS_ID = 'gid://shopify/TaxonomyCategory/fr-5-1';
    private const CATEGORY_FURNITURE_CARTS_NAME = 'Furniture > Carts & Islands > Carts';

    /**
     * ML: Muebles para Estética – Lavabos (lavacabeza / tina / lavabo de salón) → mismo leaf que estación de lavado.
     * Evita “espumas y lacas”, “maquillaje” o “coloración” por palabras del título.
     */

    /**
     * Tope de caracteres en el contexto heurístico (`fullContext`: nombre + marca).
     */
    private const FULL_CONTEXT_MAX_CHARS = 98304;

    public function __construct(
        protected ShopifyTokenService $tokenService
    ) {
    }

    /** Límite de llamadas GraphQL taxonomy por producto (se resetea en cada resolveForProduct). */
    protected int $taxonomySearchCallsThisResolution = 0;

    /**
     * Si se define (p. ej. desde `shopify:resolve-categories`), se anexan líneas con marca de tiempo
     * para ver en qué producto / qué llamada HTTP se queda colgado el proceso.
     */
    protected ?string $categoryResolveProgressPath = null;

    public function setCategoryResolveProgressPath(?string $path): void
    {
        $this->categoryResolveProgressPath = ($path !== null && trim($path) !== '') ? trim($path) : null;
    }

    protected function noteCategoryResolveProgress(string $line): void
    {
        if ($this->categoryResolveProgressPath === null || $this->categoryResolveProgressPath === '') {
            return;
        }

        $msg = '['.date('Y-m-d H:i:s').'] '.$line."\n";
        @file_put_contents($this->categoryResolveProgressPath, $msg, FILE_APPEND | LOCK_EX);
    }

    public function resolveForProduct(Product $product): ?array
    {
        $this->taxonomySearchCallsThisResolution = 0;

        $pid = (string) ($product->id ?? '');
        $pml = (string) ($product->ml ?? '');

        $this->noteCategoryResolveProgress("phase=direct_enter id={$pid} ml={$pml}");
        $t0 = microtime(true);
        $direct = $this->resolveDirectCategory($product);
        $this->noteCategoryResolveProgress(sprintf(
            'phase=direct_exit id=%s ms=%d hit=%s',
            $pid,
            (int) round((microtime(true) - $t0) * 1000),
            $direct ? 'yes' : 'no'
        ));
        if ($direct) {
            return $direct;
        }

        // Antes de Shopify GraphQL: mismas reglas que el fallback final (pinzas extensión, fajas, etc.).
        // Evita varias HTTP lentas cuando ya hay match seguro y reduce “trabado” en queue:work.
        $this->noteCategoryResolveProgress("phase=early_fallback_enter id={$pid} ml={$pml}");
        $t1 = microtime(true);
        $earlyFallback = $this->resolveSafeFallbackCategory($product);
        $this->noteCategoryResolveProgress(sprintf(
            'phase=early_fallback_exit id=%s ms=%d hit=%s',
            $pid,
            (int) round((microtime(true) - $t1) * 1000),
            $earlyFallback ? 'yes' : 'no'
        ));
        if ($earlyFallback) {
            return $earlyFallback;
        }

        $this->noteCategoryResolveProgress("phase=canonical_intent_enter id={$pid} ml={$pml}");
        $t2 = microtime(true);
        $intent = $this->resolveCanonicalIntent($product);
        $intentTerm = isset($intent['term']) ? mb_substr((string) $intent['term'], 0, 80) : '';
        $this->noteCategoryResolveProgress(sprintf(
            'phase=canonical_intent_exit id=%s ms=%d has_intent=%s term=%s',
            $pid,
            (int) round((microtime(true) - $t2) * 1000),
            $intent ? 'yes' : 'no',
            $intentTerm !== '' ? $intentTerm : '-'
        ));

        if ($intent) {
            $validated = $this->searchTaxonomyCategory(
                $intent['term'],
                $product,
                $intent
            );

            if ($validated && !empty($validated['id']) && !empty($validated['name'])) {
                $validated['source'] = 'validated_local_rule';
                return $validated;
            }

            $fallbackTerms = $intent['fallback_terms'] ?? [];
            foreach ($fallbackTerms as $fallbackTerm) {
                $validated = $this->searchTaxonomyCategory(
                    (string) $fallbackTerm,
                    $product,
                    $intent
                );

                if ($validated && !empty($validated['id']) && !empty($validated['name'])) {
                    $validated['source'] = 'validated_local_rule';
                    return $validated;
                }
            }
        }

        // Evita quedarse mucho tiempo en un solo producto cuando Shopify taxonomy
        // responde lento: limitamos intentos remotos por contexto.
        $this->noteCategoryResolveProgress("phase=build_terms_enter id={$pid} ml={$pml}");
        $t3 = microtime(true);
        $terms = array_slice($this->buildSearchTerms($product), 0, 8);
        $this->noteCategoryResolveProgress(sprintf(
            'phase=build_terms_exit id=%s ms=%d n=%d',
            $pid,
            (int) round((microtime(true) - $t3) * 1000),
            count($terms)
        ));

        foreach ($terms as $term) {
            $result = $this->searchTaxonomyCategory($term, $product, null);

            if ($result && !empty($result['id']) && !empty($result['name'])) {
                $result['source'] = 'taxonomy_api';
                return $result;
            }
        }

        $fallback = $this->resolveSafeFallbackCategory($product);
        if ($fallback) {
            return $fallback;
        }

        return null;
    }

    /**
     * Categoría ML de pinzas/clips para uñas esculpidas (misma familia que el producto ya mapeado a Nail Tools).
     */
    protected function isNailSculptedPinzasClipsCategory(Product $product): bool
    {
        $cid = trim((string) ($product->category_id ?? ''));
        if ($cid === self::MELI_CATEGORY_PINZAS_CLIPS_UÑAS) {
            return true;
        }

        $cat = $this->normalizedCategoryName($product);
        if ($cat === '') {
            return false;
        }

        $mentionsNails = str_contains($cat, 'uñas')
            || str_contains($cat, 'uña')
            || str_contains($cat, 'unas');

        if (!$mentionsNails) {
            return false;
        }

        return str_contains($cat, 'pinzas y clips')
            || str_contains($cat, 'pinzas y clip')
            || str_contains($cat, 'clips para uñas')
            || str_contains($cat, 'clips para unas')
            || str_contains($cat, 'pinzas para uñas')
            || str_contains($cat, 'pinzas para unas');
    }

    /**
     * Fajas / faja reductora (ML); no deben caer en reglas de "tratamiento" capilar.
     */
    protected function isMlFajasCategory(Product $product): bool
    {
        $cid = trim((string) ($product->category_id ?? ''));
        if ($cid === self::MELI_CATEGORY_FAJAS) {
            return true;
        }

        $cat = $this->normalizedCategoryName($product);

        return str_contains($cat, 'faja')
            && (str_contains($cat, 'tratamientos de belleza') || str_contains($cat, 'belleza'));
    }

    /**
     * ML: "Suplementos y Shakers > Suplementos Deportivos" (varios category_id).
     * No deben pasar por reglas directas de tinte/tratamiento capilar ni por búsquedas ruidosas.
     */
    protected function isMlSportsSupplementsCategory(Product $product): bool
    {
        $cat = $this->normalizedCategoryName($product);
        if ($cat === '') {
            return false;
        }

        return str_contains($cat, 'suplementos deportivos')
            || str_contains($cat, 'suplementos alimenticios')
            || str_contains($cat, 'vitaminas y suplementos')
            || str_contains($cat, 'vitamins & supplements')
            || (str_contains($cat, 'suplementos') && str_contains($cat, 'shakers'))
            || (str_contains($cat, 'salud') && str_contains($cat, 'suplement'));
    }

    protected function isShavingCreamContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if (
            str_contains($haystack, 'espumas de afeitar')
            || str_contains($haystack, 'espuma de afeitar')
            || str_contains($haystack, 'gel de afeitar')
            || str_contains($haystack, 'gel para afeitar')
            || str_contains($haystack, 'crema de afeitar')
            || str_contains($haystack, 'crema para afeitar')
            || str_contains($haystack, 'shave gel')
            || str_contains($haystack, 'shaving gel')
            || str_contains($haystack, 'shaving cream')
            || str_contains($haystack, 'shave cream')
        ) {
            return true;
        }

        return (
            (str_contains($haystack, 'afeitar') || str_contains($haystack, 'shave') || str_contains($haystack, 'shaving'))
            && $this->containsAny($haystack, ['gel', 'espuma', 'foam', 'cream', 'crema'])
        );
    }

    protected function isHairColoringAccessoriesContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if (
            str_contains($haystack, 'desechables')
            && str_contains($haystack, 'papel aluminio')
        ) {
            return true;
        }

        return $this->containsAny($haystack, [
            'papel aluminio',
            'foil para cabello',
            'foil para pelo',
            'hair foil',
            'mechas con papel aluminio',
            'papel aluminio para mechas',
        ]);
    }

    /**
     * Señales de producto capilar fuertes (sin depender de {@see isHairContext}
     * para evitar ciclos con reglas de pestañas/cejas).
     */
    protected function mentionsStrongHairProductSignals(string $haystack): bool
    {
        $haystack = mb_strtolower(trim($haystack));

        if ($haystack === '') {
            return false;
        }

        if ($this->containsAny($haystack, [
            'cabello',
            'capilar',
            'pelo',
            'hair care',
            'hair treatment',
            'hair mask',
            'shampoo',
            'champú',
            'champu',
            'mascarilla',
            'acondicionador',
            'conditioner',
            'leave-in',
            'leave in',
            'ampolla',
            'ampollas',
            'ampolleta',
            'ampolletas',
            'ampoule',
            'ampoules',
            'reparación capilar',
            'reparacion capilar',
            'tratamiento capilar',
            'nutritive',
            'rizado',
            'cuidado del color',
        ])) {
            return true;
        }

        if (preg_match('/\b\d+\s*(pzs|pz|piezas|unidades)\b/u', $haystack) === 1) {
            return str_contains($haystack, 'ampol')
                || str_contains($haystack, 'ampoule')
                || str_contains($haystack, 'cabello')
                || str_contains($haystack, 'capilar');
        }

        return false;
    }

    protected function hasHairTreatmentKeywords(string $text, string $categoryName = ''): bool
    {
        return $this->containsAny(mb_strtolower(trim($text . ' ' . $categoryName)), [
            'mascarilla',
            'hair mask',
            'mask',
            'serum capilar',
            'hair serum',
            'serum',
            'tratamiento',
            'treatment',
            'ampollas',
            'ampoules',
            'ampolletas',
            'balm',
            'balsam',
            'bond filler',
            'filler',
            'moisture kick',
            'nutri-enrich',
            'nourishing mask',
            'deep nourishing',
            'inner restore',
            'biphase',
            'bi phase',
            'bi-phase',
            'repair rescue',
            'rehydrate',
            'hydrating mask',
            'color lock mask',
            'restore',
            'intensive treatment',
            'tratamiento intensivo',
            'keratin fix',
        ]);
    }

    protected function isHairAmpouleTreatmentContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if (!$this->isHairContext($text, $categoryName)) {
            return false;
        }

        if ($this->containsAny($haystack, [
            'ampollas',
            'ampolletas',
            'ampolleta',
            'ampolla',
            'ampoule',
            'ampoules',
            'vial capilar',
            'caja de ampolletas',
            'caja de ampollas',
            'c/3 ampolletas',
            'con 3 ampolletas',
        ])) {
            return true;
        }

        return preg_match('/\b\d+\s*(pzs|pz|piezas)\b/u', $haystack) === 1
            && (str_contains($haystack, 'ampol') || str_contains($haystack, 'ampoule'));
    }

    protected function isHairStylingProductContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if ($this->isMlEsteticaLavabosOrPortableSinkContext($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEsteticaCarrosAuxiliaresCategoryPath($haystack)) {
            return false;
        }

        if (!$this->isHairContext($text, $categoryName)) {
            return false;
        }

        // Antes de excluir por "tratamiento" / aceite (p. ej. descripción con argán): peinado explícito, no mask/serum.
        $priorityHairStylingPhrases = [
            'activador de rizos',
            'activadora de rizos',
            'activador rizos',
            'activadores de rizos',
            'curl activator',
            'activating curls',
            'definidor de rizos',
            'definidores de rizos',
            // Cera fibrosa / Osis Thrill: no coincide con "cera capilar" ni con reglas de fibra capilar sueltas.
            'cera fibrosa',
            'cera texturizante',
            'cera moldeadora',
            'cera para peinar',
            'texturizing wax',
            'fibrous wax',
            'osis thrill',
            'osis+ thrill',
        ];
        if ($this->containsAny($haystack, $priorityHairStylingPhrases)) {
            return true;
        }

        if (
            $this->isHairCareKitContext($text, $categoryName, mb_substr($text, 0, 480))
            || $this->isShampooContext($text, $categoryName)
            || $this->isConditionerContext($text, $categoryName)
            || $this->isHairTreatmentContext($text, $categoryName)
            || $this->isHairOilContext($text, $categoryName)
            || $this->isHairColorContext($text, $categoryName)
            || $this->isHairBleachContext($text, $categoryName)
            || $this->isHairColorRemoverContext($text, $categoryName)
        ) {
            return false;
        }

        return $this->containsAny($haystack, [
            'finishing spray',
            'style finishing spray',
            'spray fijador',
            'hair spray',
            'spray para cabello',
            'spray capilar',
            'laca para cabello',
            'laca capilar',
            'texturizing spray',
            'powder wax',
            'powder wax spray',
            'hair powder',
            'styling powder',
            'volumizing powder',
            'polvo para cabello',
            'polvo de volumen',
            'polvo para dar volumen',
            'cera capilar',
            'hair wax',
            'pomade',
            'gel para cabello',
            'hair gel',
            'mousse',
            'espuma capilar',
            'styling cream',
            'crema para peinar',
            'texturizing cream',
            'styling paste',
            'hair paste',
            'clay',
            'fijador capilar',
            'fijacion',
            'fijación',
            'volumizer',
            'thickening cream',
            'fibra capilar',
            'fibras capilares',
            'hair fiber',
            'hair fibres',
            'hair fibers',
            'fibre cream',
            'fiber cream',
            'engrosador capilar',
        ]);
    }

    protected function isHairFiberContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if (!$this->isHairContext($text, $categoryName)) {
            return false;
        }

        return $this->containsAny($haystack, [
            'fibra capilar',
            'fibras capilares',
            'hair fiber',
            'hair fibres',
            'hair fibers',
            'fibre cream',
            'fiber cream',
            'thickening fiber',
            'engrosador capilar',
            'fibras para cabello',
        ]);
    }

    /**
     * Lacas / sprays de peinado a partir del nombre+marca+SKU (compacto), sin depender de la descripción ML.
     * Evita que categorías anchas tipo MLM171894 mezclen lacas con kits o mascarillas.
     */
    protected function isHairLacaHairsprayCompactSignal(string $compact): bool
    {
        $s = mb_strtolower(trim($compact));
        if ($s === '') {
            return false;
        }

        if (preg_match('/\b(kit|combo|pack|2\s*pzs|2pzs|3\s*pzs|4\s*pzs)\b/u', $s) === 1) {
            return false;
        }

        if (preg_match('/\b(laca|lacas|hairspray|hair\s*spray|spray\s+fijador|spray\s+capilar|spray\s+de\s+peinado|spray\s+acabado|lac\s+de|laca\s+de|laca\s+para|spray\s+texturiz|texturizing\s+spray|dry\s+texturizing)\b/u', $s) === 1) {
            return true;
        }

        if (str_contains($s, 'session label') && str_contains($s, 'flexible')) {
            return true;
        }

        if (str_contains($s, 'worked up') && str_contains($s, 'laca')) {
            return true;
        }

        return $this->containsAny($s, [
            'espuma capilar',
            'espuma para cabello',
            'hair mousse',
            'sea salt spray',
            'salt spray',
            'spray de volumen',
            'spray volumen',
            'powder spray',
            'spray de brillo',
            'shine spray',
        ]);
    }

    protected function isHairCareKitContext(string $text, string $categoryName = '', ?string $listingText = null): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));
        // Evita que descripciones largas mencionen "shampoo + mask + ..." de marketing
        // y disparen kits en unidades sueltas (p.ej. una sola mascarilla).
        $kitSource = $listingText ?? mb_substr($text, 0, 480);
        $textOnly = $this->stripCategoryNameFromContext($kitSource, $categoryName);
        $textOnly = (string) preg_replace('/\b(mlm|mlc|mla|mlb|mco|mpe)\d{6,}\b/u', ' ', $textOnly);
        $textOnly = trim((string) preg_replace('/\s+/', ' ', $textOnly));

        $compactForLac = trim((string) ($listingText ?? ''));
        if ($compactForLac !== '' && $this->isHairLacaHairsprayCompactSignal($compactForLac)) {
            return false;
        }

        if ($compactForLac === '' && $this->isHairLacaHairsprayCompactSignal(mb_substr($textOnly, 0, 260))) {
            return false;
        }

        if (str_contains($textOnly, 'kitalfaparf')
            || preg_match('/\b(kit|set|combo|pack)\b/u', $textOnly) === 1) {
            return true;
        }

        if (preg_match('/\bshampoo\s*\+\s*mascarilla\b/u', $textOnly) === 1) {
            return true;
        }

        if (preg_match('/\b(shampoo|champ[uú]|champú)\b.*\b(mascarilla|mask)\b/u', $textOnly) === 1
            && (str_contains($textOnly, '+') || str_contains($textOnly, ' y ') || str_contains($textOnly, ','))) {
            return true;
        }

        if ($this->containsAny($haystack, [
            'set completo',
            'kit completo',
            'maintenance in casa',
            '4 pasos',
            'kit 4 pasos',
            'pack completo',
            'kit de mantenimiento',
        ])) {
            return true;
        }

        $multiStepTerms = 0;
        // No contar "acondicionador"/"conditioner": un solo acondicionador suele mencionar
        // otros formatos en la descripción y dispara falsos kits.
        foreach (['shampoo', 'mask', 'mascarilla', 'leave-in', 'leave in', 'fluido', 'fluid', 'aceite', 'crema'] as $term) {
            if (str_contains($textOnly, $term)) {
                $multiStepTerms++;
            }
        }

        $hasBundleJoiners = str_contains($textOnly, ',')
            || str_contains($textOnly, ' y ')
            || str_contains($textOnly, '+')
            || str_contains($textOnly, ' / ')
            || str_contains($textOnly, ' & ');

        if ($multiStepTerms >= 3 && $hasBundleJoiners
            && preg_match('/\b(kit|set|combo|pack)\b/u', $textOnly) === 1) {
            return true;
        }

        return $multiStepTerms >= 2 && $this->containsAny($textOnly, ['kit', 'set', 'combo']);
    }

    protected function isLeaveInConditionerContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if (!$this->isHairContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHairCareKitContext($text, $categoryName, mb_substr($text, 0, 480))) {
            return false;
        }

        return $this->containsAny($haystack, [
            'leave-in',
            'leave in',
            'detangling fluid',
            'spray conditioner',
            'acondicionador spray',
            'moisture kick',
            'detangler',
        ]);
    }

    protected function isSingleHairTreatmentProductContext(string $text, string $categoryName = ''): bool
    {
        if (!$this->isHairContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHairCareKitContext($text, $categoryName, mb_substr($this->stripCategoryNameFromContext($text, $categoryName), 0, 480)) || $this->isLeaveInConditionerContext($text, $categoryName)) {
            return false;
        }

        $head = mb_strtolower(trim(mb_substr($this->stripCategoryNameFromContext($text, $categoryName), 0, 260)));
        if ($this->isHairLacaHairsprayCompactSignal($head)) {
            return false;
        }

        $textOnly = $this->stripCategoryNameFromContext($text, $categoryName);

        return $this->containsAny($textOnly, [
            'mascarilla',
            'hair mask',
            'mask',
            'tratamiento',
            'treatment',
            'serum capilar',
            'hair serum',
            'serum',
            'balm',
            'balsam',
            'bond filler',
            'moisture mask',
            'nourishing mask',
            'deep nourishing mask',
            'inner restore',
            'rehydrate',
            'repair rescue',
        ]);
    }

    protected function isDeodorantContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        return $this->containsAny($haystack, [
            'desodorante',
            'deodorant',
            'antitranspirante',
            'antiperspirant',
            'deodorants',
            'desodorantes',
        ]);
    }

    protected function resolveDeodorantShopifyCategory(string $text, string $categoryName = ''): array
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if ($this->containsAny($haystack, [
            'antitranspirante',
            'antiperspirant',
            'anti-perspirant',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_ANTI_PERSPIRANT_ID,
                self::CATEGORY_ANTI_PERSPIRANT_NAME,
                'direct_deodorant_rule'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_DEODORANTS_ID,
            self::CATEGORY_DEODORANTS_NAME,
            'direct_deodorant_rule'
        );
    }

    /**
     * Productos de maquillaje facial tipo primer/fijador/setting spray.
     */
    protected function isMakeupSettingSprayContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if ($haystack === '') {
            return false;
        }

        // Rubores / bronceadores no son setting spray aunque digan "spray" en otro sentido.
        if ($this->isMlRostroIluminadoresRuboresCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlRostroCorrectoresCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlRostroBasesMaquillajeCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        // No usar solo "rostro" / "face" / "makeup": la categoría ML "Rostro - Correctores" activaba finishing spray por error.
        $mentionsMakeup = $this->containsAny($haystack, [
            'primer',
            'fijador',
            'setting spray',
            'face mist',
            'fijador de maquillaje',
            'spray fijador',
            'setting powder',
            'setting power',
        ]);

        if (!$mentionsMakeup) {
            return false;
        }

        // Evita capturar tintes capilares reales que también digan "spray".
        return !$this->containsAny($haystack, [
            'capilar',
            'cabello',
            'hair color',
            'tinte',
            'tintura',
            'decolorante',
            'blondor',
            'revelador',
            'peróxido',
            'peroxido',
        ]);
    }

    /**
     * Categoría ML típica: "Rostro - Iluminadores y Rubores" (varios category_id en DB).
     */
    protected function isMlRostroIluminadoresRuboresCategory(Product $product): bool
    {
        $cid = strtoupper(trim((string) ($product->category_id ?? '')));
        if (in_array($cid, ['MLM172360', 'MLM172350', 'MLM12360', 'MLM12359'], true)) {
            return true;
        }

        return $this->isMlRostroIluminadoresRuboresCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlRostroIluminadoresRuboresCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));

        if ($cat === '') {
            return false;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        if ($this->isRingLightContext($hay, $cat)) {
            return false;
        }

        if (
            str_contains($cat, 'iluminadores')
            && str_contains($cat, 'rubores')
        ) {
            return true;
        }

        if (
            str_contains($cat, 'rostro')
            && (str_contains($cat, 'rubor') || str_contains($cat, 'iluminador'))
        ) {
            return true;
        }

        return str_contains($hay, 'iluminadores y rubores')
            || str_contains($hay, 'iluminadores y rubor');
    }

    protected function isRingLightContext(string $text, string $categoryName = ''): bool
    {
        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        if ($this->containsAny($haystack, [
            'aro de luz',
            'ring light',
            'tripie',
            'tripié',
            'tripod',
            'selfie light',
        ])) {
            return true;
        }

        return (
            (str_contains($haystack, 'estudio') || str_contains($haystack, 'iluminación') || str_contains($haystack, 'iluminacion'))
            && $this->containsAny($haystack, ['aro', 'luz', 'light', 'tripie', 'tripié', 'tripod'])
        );
    }

    /**
     * Ruta ML “Muebles para Estética > Sillas para Estética” (con o sin acentos / > vs -).
     */
    protected function isMlEsteticaSillasCategoryPath(string $hay): bool
    {
        $h = mb_strtolower(trim($hay));
        if ($h === '') {
            return false;
        }

        $h = str_replace(['>', '|', '–', '—', '-'], ' ', $h);
        $h = trim((string) preg_replace('/\s+/', ' ', $h));

        $hasMuebles = str_contains($h, 'muebles para estética') || str_contains($h, 'muebles para estetica');
        $hasSillas = str_contains($h, 'sillas para estética') || str_contains($h, 'sillas para estetica');

        return $hasMuebles && $hasSillas;
    }

    /**
     * Ruta ML “Muebles para Estética” + carros / carritos auxiliares (no confundir con “Carros de Colección” u otros).
     */
    protected function isMlEsteticaCarrosAuxiliaresCategoryPath(string $hay): bool
    {
        $h = mb_strtolower(trim($hay));
        if ($h === '') {
            return false;
        }

        $h = str_replace(['>', '|', '–', '—', '-'], ' ', $h);
        $h = trim((string) preg_replace('/\s+/', ' ', $h));

        $hasMuebles = str_contains($h, 'muebles para estética') || str_contains($h, 'muebles para estetica');
        $hasCarros = str_contains($h, 'carros auxiliares') || str_contains($h, 'carrito auxiliar') || str_contains($h, 'carritos auxiliares');

        return $hasMuebles && $hasCarros;
    }

    /**
     * Ruta ML “Muebles para Estética” + lavabos / lavacabeza (tina portátil, lavabo abatible, etc.).
     */
    protected function isMlEsteticaLavabosCategoryPath(string $hay): bool
    {
        $h = mb_strtolower(trim($hay));
        if ($h === '') {
            return false;
        }

        $h = str_replace(['>', '|', '–', '—', '-'], ' ', $h);
        $h = trim((string) preg_replace('/\s+/', ' ', $h));

        $hasMuebles = str_contains($h, 'muebles para estética') || str_contains($h, 'muebles para estetica');
        $hasLavabos = str_contains($h, 'lavabos')
            || str_contains($h, 'lavacabeza')
            || str_contains($h, 'lavacabezas')
            || str_contains($h, 'lava cabeza')
            || str_contains($h, 'tina lavabo')
            || (str_contains($h, 'lavabo') && str_contains($h, 'lavacabeza'));

        return $hasMuebles && $hasLavabos;
    }

    /**
     * Lavabo / lavacabeza de salón aunque en BD no venga la ruta completa “Muebles para Estética – Lavabos”.
     * Evita que isHairContext / peinado / champú interpreten el texto como cosmético.
     */
    protected function isMlEsteticaLavabosOrPortableSinkContext(string $text, string $categoryName = ''): bool
    {
        $hay = mb_strtolower(trim($text . ' ' . $categoryName));
        if ($hay === '') {
            return false;
        }

        if ($this->isMlEsteticaLavabosCategoryPath($hay)) {
            return true;
        }

        $hasSink = str_contains($hay, 'lavabo')
            || str_contains($hay, 'tina lavabo')
            || str_contains($hay, 'lavacabeza')
            || str_contains($hay, 'lavacabezas')
            || str_contains($hay, 'lava cabeza');

        if (! $hasSink) {
            return false;
        }

        return str_contains($hay, 'salón')
            || str_contains($hay, 'salon')
            || str_contains($hay, 'estética')
            || str_contains($hay, 'estetica')
            || str_contains($hay, 'estilista')
            || str_contains($hay, 'barbería')
            || str_contains($hay, 'barberia')
            || str_contains($hay, 'peluquería')
            || str_contains($hay, 'peluqueria')
            || str_contains($hay, 'muebles para estética')
            || str_contains($hay, 'muebles para estetica')
            || str_contains($hay, 'portátil')
            || str_contains($hay, 'portatil')
            || str_contains($hay, 'para cama');
    }

    protected function isMlMueblesEsteticaLavabosCategory(Product $product): bool
    {
        $cat = $this->truncatedCategoryName($product);
        if ($this->isMlEsteticaLavabosCategoryPath($cat)) {
            return true;
        }

        $ctx = $this->fullContext($product);
        if ($this->isMlEsteticaLavabosCategoryPath($ctx)) {
            return true;
        }

        return $this->isMlEsteticaLavabosOrPortableSinkContext($ctx, $cat);
    }

    /**
     * @return array{id:string,name:string,term:?string,source:string}
     */
    protected function resolveMlEsteticaLavabosShopifyCategory(Product $product, bool $asSafeFallback = false): array
    {
        $pfx = $asSafeFallback ? 'safe_fallback_ml_estetica_lavabos' : 'direct_ml_estetica_lavabos';

        return $this->makeResolvedCategory(
            self::CATEGORY_SALON_CHAIRS_SHAMPOO_ID,
            self::CATEGORY_SALON_CHAIRS_SHAMPOO_NAME,
            $pfx.'_shampoo_station'
        );
    }

    protected function isMlMueblesEsteticaSillasCategory(Product $product): bool
    {
        if ($this->isMlMueblesEsteticaLavabosCategory($product)) {
            return false;
        }

        $cid = strtoupper(trim((string) ($product->category_id ?? '')));
        if ($cid !== '' && in_array($cid, self::MELI_CATEGORY_IDS_MUEBLES_ESTETICA_SILLAS, true)) {
            return true;
        }

        if ($this->isMlEsteticaSillasCategoryPath($this->truncatedCategoryName($product))) {
            return true;
        }

        // A veces `category_name` viene vacío en BD pero la ruta ML está en el título/marca.
        return $this->isMlEsteticaSillasCategoryPath($this->fullContext($product));
    }

    /**
     * @return array{id:string,name:string,term:?string,source:string}
     */
    protected function resolveMlEsteticaSillasShopifyCategory(Product $product, bool $asSafeFallback = false): array
    {
        $text = $this->fullContext($product);
        $pfx = $asSafeFallback ? 'safe_fallback_ml_estetica_sillas' : 'direct_ml_estetica_sillas';

        if ($this->containsAny($text, ['cabecera', 'cabeceras', 'headrest'])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SALON_CHAIRS_ALL_PURPOSE_ID,
                self::CATEGORY_SALON_CHAIRS_ALL_PURPOSE_NAME,
                $pfx.'_accessory'
            );
        }

        if ($this->containsAny($text, ['lavacabezas', 'lavacabeza', 'shampoo chair', 'silla champú', 'silla champu'])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SALON_CHAIRS_SHAMPOO_ID,
                self::CATEGORY_SALON_CHAIRS_SHAMPOO_NAME,
                $pfx.'_shampoo'
            );
        }

        $barberSignals = [
            'barbería',
            'barberia',
            'barbero',
            'barbershop',
            'peluquería',
            'peluqueria',
            'sillon de barber',
            'sillón de barber',
            'sillon barber',
            'sillón barber',
            'sillon de barberia',
            'sillón de barbería',
            'barberia hidraulico',
            'barbería hidráulico',
            'sillon barberia',
            'sillón barbería',
        ];

        if ($this->containsAny($text, $barberSignals)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SALON_CHAIRS_BARBER_ID,
                self::CATEGORY_SALON_CHAIRS_BARBER_NAME,
                $pfx.'_barber'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_SALON_CHAIRS_STYLING_ID,
            self::CATEGORY_SALON_CHAIRS_STYLING_NAME,
            $pfx.'_styling'
        );
    }

    protected function isMlMueblesEsteticaCarrosAuxiliaresCategory(Product $product): bool
    {
        if ($this->isMlMueblesEsteticaLavabosCategory($product)) {
            return false;
        }

        $cid = strtoupper(trim((string) ($product->category_id ?? '')));
        if ($cid === self::MELI_CATEGORY_ID_CARROS_AUXILIARES) {
            return true;
        }

        if ($this->isMlEsteticaCarrosAuxiliaresCategoryPath($this->truncatedCategoryName($product))) {
            return true;
        }

        return $this->isMlEsteticaCarrosAuxiliaresCategoryPath($this->fullContext($product));
    }

    /**
     * @return array{id:string,name:string,term:?string,source:string}
     */
    protected function resolveMlEsteticaCarrosAuxiliaresShopifyCategory(Product $product, bool $asSafeFallback = false): array
    {
        $pfx = $asSafeFallback ? 'safe_fallback_ml_estetica_carros_aux' : 'direct_ml_estetica_carros_aux';

        return $this->makeResolvedCategory(
            self::CATEGORY_FURNITURE_CARTS_ID,
            self::CATEGORY_FURNITURE_CARTS_NAME,
            $pfx.'_furniture_cart'
        );
    }

    protected function isMlRostroCorrectoresCategory(Product $product): bool
    {
        $cid = strtoupper(trim((string) ($product->category_id ?? '')));
        if (in_array($cid, ['MLM172335', 'MLM17235', 'MLM12335'], true)) {
            return true;
        }

        return $this->isMlRostroCorrectoresCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlRostroCorrectoresCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'correctores') || str_contains($cat, 'corrector')) {
            return str_contains($cat, 'rostro');
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return str_contains($hay, 'rostro') && str_contains($hay, 'correctores');
    }

    protected function isMlRostroBasesMaquillajeCategory(Product $product): bool
    {
        return $this->isMlRostroBasesMaquillajeCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlRostroBasesMaquillajeCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'bases de maquillaje')) {
            return true;
        }

        return str_contains($cat, 'rostro')
            && str_contains($cat, 'bases')
            && str_contains($cat, 'maquillaje');
    }

    protected function resolveRostroBasesMaquillajeShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'bronceador',
            'bronzer',
            'cream bronzer',
            'bronceadores',
            'stay golden',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BRONZERS_ID,
                self::CATEGORY_BRONZERS_NAME,
                'direct_ml_rostro_bases_bronzer'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_FOUNDATIONS_ID,
            self::CATEGORY_FOUNDATIONS_NAME,
            'direct_ml_rostro_bases_foundation'
        );
    }

    protected function isMlSunProtectionCategory(Product $product): bool
    {
        $cat = $this->normalizedCategoryName($product);
        if ($cat === '') {
            return false;
        }

        return (str_contains($cat, 'protección solar') || str_contains($cat, 'proteccion solar'))
            && ($this->containsAny($cat, ['protector', 'protectores', 'bloqueador', 'bloqueadores']));
    }

    protected function isSunscreenContext(string $text, string $categoryName = ''): bool
    {
        $hay = mb_strtolower(trim($text . ' ' . $categoryName));
        if ($hay === '') {
            return false;
        }

        if ($this->containsAny($hay, [
            'base de maquillaje',
            'corrector',
            'concealer',
            'rubor',
            'blush',
            'bronzer',
            'bronceador',
        ])) {
            return false;
        }

        return $this->containsAny($hay, [
            'protector solar',
            'bloqueador solar',
            'sun screen',
            'sunscreen',
            'sunblock',
            'uv protection',
            'spf 30',
            'spf 50',
            'fps 30',
            'fps 50',
        ]);
    }

    protected function isAfterSunBodyWashContext(string $text, string $categoryName = ''): bool
    {
        $hay = mb_strtolower(trim($text . ' ' . $categoryName));
        if ($hay === '') {
            return false;
        }

        $mentionsAfterSun = $this->containsAny($hay, [
            'after sun',
            'after-sun',
            'aftersun',
        ]);

        if (!$mentionsAfterSun) {
            return false;
        }

        return $this->containsAny($hay, [
            'body wash',
            'hair and body wash',
            'hair & body wash',
            'shower bath',
            'gel de baño',
            'gel de bano',
        ]);
    }

    protected function isMlLashBrowTreatmentCategory(Product $product): bool
    {
        return $this->isMlLashBrowTreatmentCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlLashBrowTreatmentCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (
            str_contains($cat, 'pestañas')
            && str_contains($cat, 'cejas')
            && (str_contains($cat, 'tratamientos') || str_contains($cat, 'tratamiento'))
        ) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        if ($this->mentionsStrongHairProductSignals($hay)) {
            return false;
        }

        $mentionsLashOrBrow = $this->containsAny($hay, [
            'pestañas y cejas',
            'cejas y pestañas',
        ]) || preg_match('/\b(eyelash|eyebrow)s?\b/u', $hay) === 1
            || preg_match('/(?<![a-z])brows?\b/u', $hay) === 1
            || preg_match('/(?<![a-z])lashes\b/u', $hay) === 1
            || preg_match('/(?<![a-z])lash\b/u', $hay) === 1;

        if (!$mentionsLashOrBrow) {
            return false;
        }

        return $this->containsAny($hay, [
            'suero',
            'serum',
            'tratamiento',
            'treatment',
            'growth',
            'crecimiento',
            'alargador',
        ]);
    }

    protected function isMlLashAdhesiveCategory(Product $product): bool
    {
        return $this->isMlLashAdhesiveCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlLashAdhesiveCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (
            str_contains($cat, 'pestañas')
            && (str_contains($cat, 'pegamento') || str_contains($cat, 'adhesivo'))
        ) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            'pegamento para pestañas',
            'adhesivo para pestañas',
            'eyelash adhesive',
            'lash glue',
        ]);
    }

    protected function resolveLashAdhesiveShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'en tira',
            'pestaña en tira',
            'pestañas en tira',
            'strip eyelash',
            'strip lash',
            'transparente',
            'oscuro',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_STRIP_EYELASH_ADHESIVE_ID,
                self::CATEGORY_STRIP_EYELASH_ADHESIVE_NAME,
                'direct_lash_adhesive_strip'
            );
        }

        if ($this->containsAny($t, [
            'rulos',
            'lash lift',
            'lifting',
            'perm',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LASH_LIFT_ADHESIVES_ID,
                self::CATEGORY_LASH_LIFT_ADHESIVES_NAME,
                'direct_lash_adhesive_lift'
            );
        }

        if ($this->containsAny($t, [
            'extensión',
            'extension',
            'keratina',
            'cluster',
            'individual',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_EYELASH_EXTENSION_ADHESIVES_ID,
                self::CATEGORY_EYELASH_EXTENSION_ADHESIVES_NAME,
                'direct_lash_adhesive_extension'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_FALSE_EYELASH_ADHESIVE_ID,
            self::CATEGORY_FALSE_EYELASH_ADHESIVE_NAME,
            'direct_lash_adhesive_generic'
        );
    }

    protected function isMlEyeShadowsCategory(Product $product): bool
    {
        return $this->isMlEyeShadowsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlEyeShadowsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'sombras para ojos') || str_contains($cat, 'sombra para ojos')) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            'sombras para ojos',
            'sombra para ojos',
            'eye shadow',
            'eyeshadow',
            'shadow pot',
        ]);
    }

    protected function isMlEyelashExtensionsCategory(Product $product): bool
    {
        return $this->isMlEyelashExtensionsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlEyelashExtensionsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'extensiones de pestañas') || str_contains($cat, 'extensiones de pestañas')) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            'extensiones de pestañas',
            'extensiones de pestañas',
            'eyelash extensions',
            'lash extensions',
        ]);
    }

    protected function isMlHairExtensionsCategory(Product $product): bool
    {
        $cid = strtoupper(trim((string) ($product->category_id ?? '')));
        if ($cid === self::MELI_CATEGORY_PINZAS_EXTENSION_CABELLO) {
            return true;
        }

        return $this->isMlHairExtensionsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlHairExtensionsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        $hay = mb_strtolower(trim($text . ' ' . $cat));

        // Herramientas ML “Pinzas de Extensión” (cabello): el nombre no trae “extensiones de cabello”
        // y antes no activaba isHairExtensionToolContext → solo GraphQL (a veces sin match útil).
        // Buscar en texto + categoría: en producción a veces `category_name` está vacío o no coincide el id ML.
        if (
            str_contains($hay, 'pinzas de extensión')
            || str_contains($hay, 'pinzas de extension')
            || str_contains($hay, 'pinza de extensión')
            || str_contains($hay, 'pinza de extension')
            || str_contains($hay, 'pinzas - pinzas de extensión')
            || str_contains($hay, 'pinzas - pinzas de extension')
        ) {
            return ! $this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName);
        }

        if (
            str_contains($cat, 'extensiones y pelucas')
            && (str_contains($cat, 'extensiones') || str_contains($cat, 'pelucas'))
        ) {
            return true;
        }

        if ($cat === '') {
            return $this->containsAny($hay, [
                'extensiones de cabello',
                'extensiones para cabello',
                'hair extensions',
                'extensiones y pelucas',
                'pelucas',
                'wig',
                'wigs',
                'tape-in',
                'tape in',
                'tape pro',
            ]) && ! $this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName);
        }

        return $this->containsAny($hay, [
            'extensiones de cabello',
            'extensiones para cabello',
            'hair extensions',
            'extensiones y pelucas',
            'pelucas',
            'wig',
            'wigs',
        ]) && ! $this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName);
    }

    protected function isHairExtensionToolContext(string $text, string $categoryName = '', ?Product $product = null): bool
    {
        if ($product !== null) {
            $cid = strtoupper(trim((string) ($product->category_id ?? '')));
            if ($cid === self::MELI_CATEGORY_PINZAS_EXTENSION_CABELLO) {
                return true;
            }
        }

        $haystack = mb_strtolower(trim($text . ' ' . $categoryName));

        // Pinza / tape-in: señal fuerte sin depender de category_id ni del nombre exacto de categoría ML.
        if (
            $this->containsAny($haystack, ['tape-in', 'tape in', 'tape pro', 'tape-in press'])
            && $this->containsAny($haystack, ['pinza', 'pinzas', 'plier', 'pliers', 'aplicar extensiones', 'extensiones de tape'])
            && ! $this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName)
        ) {
            return true;
        }

        if (! $this->isMlHairExtensionsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        return $this->containsAny($haystack, [
            'pinza',
            'pinzas',
            'aplicacion',
            'aplicación',
            'herramienta',
            'tool',
            'kit de aplicacion',
            'kit de aplicación',
            'micro ring',
            'loop needle',
            'hook needle',
            'plier',
            'pliers',
        ]);
    }

    protected function isMlMakeupCasesCategory(Product $product): bool
    {
        return $this->isMlMakeupCasesCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlMakeupCasesCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (
            str_contains($cat, 'maquillaje')
            && (str_contains($cat, 'maletines') || str_contains($cat, 'maletin') || str_contains($cat, 'maleta'))
        ) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            'maleta de maquillaje',
            'maletin de maquillaje',
            'maletín de maquillaje',
            'makeup case',
            'makeup train case',
            'beauty case',
            'cosmetiquera con ruedas',
        ]);
    }

    protected function isMlFacialCleansingOtherCategory(Product $product): bool
    {
        return $this->isMlFacialCleansingOtherCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlFacialCleansingOtherCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'limpiezas faciales') && str_contains($cat, 'otros')) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return str_contains($hay, 'limpiezas faciales') && str_contains($hay, 'otros');
    }

    protected function isMlLipsticksCategory(Product $product): bool
    {
        return $this->isMlLipsticksCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlLipsticksCategoryFromStrings(string $text, string $categoryName): bool
    {
        if ($this->isMlLipBalmsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'labios') && str_contains($cat, 'labiales')) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return str_contains($hay, 'labios') && str_contains($hay, 'labial');
    }

    protected function isMlLipBalmsCategory(Product $product): bool
    {
        return $this->isMlLipBalmsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlLipBalmsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        $hay = mb_strtolower(trim($text . ' ' . $cat));

        if ($this->isLipPlumperContext($text, $categoryName)) {
            return false;
        }

        if ($this->containsAny($hay, [
            'bálsamo labial',
            'balsamo labial',
            'lip balm',
            'butter bliss',
            'lip butter',
        ])) {
            return true;
        }

        return (
            str_contains($cat, 'labios')
            && (
                str_contains($cat, 'bálsamos')
                || str_contains($cat, 'balsamos')
                || str_contains($cat, 'bálsamo')
                || str_contains($cat, 'balsamo')
                || str_contains($cat, 'geles')
                || str_contains($cat, 'geles')
            )
        );
    }

    protected function isLipPlumperContext(string $text, string $categoryName = ''): bool
    {
        $hay = mb_strtolower(trim($text . ' ' . $categoryName));

        return $this->containsAny($hay, [
            'engrosador de labios',
            'engrosadores de labios',
            'lip plumper',
            'plumper',
            'plumping gloss',
            'volumizador labial',
            'voluminizador labial',
        ]);
    }

    protected function isMlBraceletsCategory(Product $product): bool
    {
        return $this->isMlBraceletsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlBraceletsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (str_contains($cat, 'joyería') && str_contains($cat, 'pulseras')) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            'pulsera',
            'pulseras',
            'brazalete',
            'bracelet',
            'bracelets',
        ]);
    }

    protected function isMlCharmsCategory(Product $product): bool
    {
        return $this->isMlCharmsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlCharmsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (
            str_contains($cat, 'joyería')
            && (str_contains($cat, 'dijes') || str_contains($cat, 'dije'))
        ) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            ' dije ',
            ' dijes ',
            'charm',
            'charms',
            'colgante',
            'pendant',
        ]);
    }

    protected function resolveLipsticksShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'gloss',
            'glow getter',
            'cuddle glow',
            'lip oil',
            'lip plumper',
            'plumper',
            'plumping gloss',
            'engrosador de labios',
            'engrosadores de labios',
            'brillo labial',
            'brillante',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LIP_GLOSS_ID,
                self::CATEGORY_LIP_GLOSS_NAME,
                'direct_lip_gloss'
            );
        }

        if ($this->containsAny($t, [
            'labial líquido matte',
            'labial liquido matte',
            'liquid matte',
            'mate',
            'matte',
            'larga duración',
            'larga duracion',
            'waterproof',
            'resistente al agua',
            'long lasting',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LIPSTICKS_ID,
                self::CATEGORY_LIPSTICKS_NAME,
                'direct_lipstick'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_LIPSTICKS_ID,
            self::CATEGORY_LIPSTICKS_NAME,
            'direct_lipstick_default'
        );
    }

    protected function resolveLipBalmsShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'medicated',
            'medicado',
            'tratamiento medicado',
            'cold sore',
            'herpes labial',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_MEDICATED_LIP_TREATMENTS_ID,
                self::CATEGORY_MEDICATED_LIP_TREATMENTS_NAME,
                'direct_lip_treatment'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_LIP_BALMS_ID,
            self::CATEGORY_LIP_BALMS_NAME,
            'direct_lip_balm'
        );
    }

    protected function resolveFacialCleansingOtherShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'witch hazel',
            'astringent',
            'astringente',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_ASTRINGENTS_ID,
                self::CATEGORY_ASTRINGENTS_NAME,
                'direct_facial_astringent'
            );
        }

        if ($this->containsAny($t, [
            'facial spray',
            'refresh spray',
            'refresher spray',
            'spray refrescante',
            'refrescador facial',
            'rose water',
            'agua de rosas',
            'face mist',
            'facial mist',
            'refrescante facial',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_TONERS_ID,
                self::CATEGORY_TONERS_NAME,
                'direct_facial_toner'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_TONERS_ID,
            self::CATEGORY_TONERS_NAME,
            'direct_facial_toner_default'
        );
    }

    protected function resolveEyelashExtensionsShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'pegamento',
            'adhesivo',
            'adhesive',
            'glue',
        ])) {
            return $this->resolveLashAdhesiveShopifyCategory($text);
        }

        if ($this->containsAny($t, [
            'rizado',
            'perm',
            'permanente',
            'lash lift',
            'lifting',
            'laminado',
            'lamination',
            'planchado de cejas',
            'rulos',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LASH_LIFT_ADHESIVES_ID,
                self::CATEGORY_LASH_LIFT_ADHESIVES_NAME,
                'direct_eyelash_extensions_lift'
            );
        }

        if ($this->containsAny($t, [
            'postizas',
            'postiza',
            'false eyelash',
            'false eyelashes',
            'mink',
            'silk',
            '3d lashes',
            '5 pares',
            'par de pestañas',
            'pares de pestañas',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_FALSE_EYELASHES_ID,
                self::CATEGORY_FALSE_EYELASHES_NAME,
                'direct_eyelash_extensions_false_eyelashes'
            );
        }

        if ($this->containsAny($t, [
            'pigmento',
            'tinte',
            'tintura',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_EYE_MAKEUP_ID,
                self::CATEGORY_EYE_MAKEUP_NAME,
                'direct_eyelash_extensions_pigment'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_FALSE_EYELASHES_ID,
            self::CATEGORY_FALSE_EYELASHES_NAME,
            'direct_eyelash_extensions_default'
        );
    }

    protected function isMlEyeLashBrowOtherProductsCategory(Product $product): bool
    {
        return $this->isMlEyeLashBrowOtherProductsCategoryFromStrings(
            $this->fullContext($product),
            $this->truncatedCategoryName($product)
        );
    }

    protected function isMlEyeLashBrowOtherProductsCategoryFromStrings(string $text, string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        if ($cat === '') {
            return false;
        }

        if (
            str_contains($cat, 'ojos')
            && str_contains($cat, 'pestañas')
            && str_contains($cat, 'cejas')
            && str_contains($cat, 'otros productos')
        ) {
            return true;
        }

        $hay = mb_strtolower(trim($text . ' ' . $cat));

        return $this->containsAny($hay, [
            'ojos, pestañas y cejas',
            'ojos pestañas y cejas',
        ]) && str_contains($hay, 'otros productos');
    }

    protected function resolveEyeLashBrowOtherProductsShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, [
            'estimulador',
            'crecimiento',
            'growth',
            'dabalash',
            'relash',
            'ophalica',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_ID,
                self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_NAME,
                'direct_eye_lash_brow_growth'
            );
        }

        if ($this->containsAny($t, [
            'pegamento',
            'adhesivo',
            'adhesive',
            'glue',
        ])) {
            return $this->resolveLashAdhesiveShopifyCategory($text);
        }

        if (
            $this->containsAny($t, [
                'rizado',
                'perm',
                'permanente',
                'lash lift',
                'lifting',
                'laminado',
                'lamination',
                'planchado de cejas',
                'rulos',
            ])
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LASH_LIFT_ADHESIVES_ID,
                self::CATEGORY_LASH_LIFT_ADHESIVES_NAME,
                'direct_eye_lash_lift'
            );
        }

        if (
            $this->containsAny($t, [
                'pigmento',
                'tinte',
                'tintura',
            ])
            && $this->containsAny($t, ['ceja', 'cejas', 'brow', 'eyebrow'])
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_EYEBROW_ENHANCERS_ID,
                self::CATEGORY_EYEBROW_ENHANCERS_NAME,
                'direct_eye_brow_pigment'
            );
        }

        if ($this->containsAny($t, ['pigmento', 'tinte', 'tintura'])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_EYEBROW_ENHANCERS_ID,
                self::CATEGORY_EYEBROW_ENHANCERS_NAME,
                'direct_eye_brow_pigment_default'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_ID,
            self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_NAME,
            'direct_eye_lash_brow_other_default'
        );
    }

    protected function resolveEyeShadowsShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if (
            $this->containsAny($t, ['paleta de rostro', 'face palette'])
            || (
                $this->containsAny($t, ['paleta', 'palette'])
                && $this->containsAny($t, [
                    'rostro',
                    'face',
                    'blush',
                    'rubor',
                    'bronze',
                    'bronce',
                    'sculpt',
                    'contour',
                    'highlighter',
                    'iluminador',
                ])
            )
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_FACE_PALETTES_ID,
                self::CATEGORY_FACE_PALETTES_NAME,
                'direct_eye_category_face_palette'
            );
        }

        if ($this->containsAny($t, ['paleta', 'palette'])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_EYE_SHADOW_PALETTES_ID,
                self::CATEGORY_EYE_SHADOW_PALETTES_NAME,
                'direct_eye_shadow_palette'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_EYE_SHADOWS_ID,
            self::CATEGORY_EYE_SHADOWS_NAME,
            'direct_eye_shadows'
        );
    }

    /**
     * Resuelve rubor / bronceador / paleta / iluminador dentro de la familia ML de rostro.
     */
    protected function resolveRostroIluminadoresRuboresShopifyCategory(string $text): array
    {
        $t = mb_strtolower(trim($text));

        if ($this->containsAny($t, ['bronceador', 'bronzer', 'bronceadores'])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BRONZERS_ID,
                self::CATEGORY_BRONZERS_NAME,
                'direct_ml_rostro_bronzer'
            );
        }

        // Paletas de rubor antes que reglas genéricas; evita confundir con iluminadores.
        if (
            $this->containsAny($t, ['paleta'])
            && $this->containsAny($t, ['rubor', 'blush', 'blusher', 'rostro'])
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_FACE_PALETTES_ID,
                self::CATEGORY_FACE_PALETTES_NAME,
                'direct_ml_rostro_face_palette'
            );
        }

        if (
            $this->containsAny($t, [
                'rubor',
                'blush',
                'blusher',
                'ombre blush',
                'cream blush',
                'rubores',
            ])
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BLUSHES_ID,
                self::CATEGORY_BLUSHES_NAME,
                'direct_ml_rostro_blush'
            );
        }

        // No usar el prefijo "iluminad": coincide con "Iluminadores" del nombre de categoría ML
        // "Iluminadores y Rubores" y mandaba todos los rubores a Resaltadores.
        if ($this->containsAny($t, ['iluminador', 'highlighter', 'luminiz', 'strobing'])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HIGHLIGHTERS_LUMINIZERS_ID,
                self::CATEGORY_HIGHLIGHTERS_LUMINIZERS_NAME,
                'direct_ml_rostro_highlighter'
            );
        }

        return $this->makeResolvedCategory(
            self::CATEGORY_BLUSHES_ID,
            self::CATEGORY_BLUSHES_NAME,
            'direct_ml_rostro_blush_default'
        );
    }

    /**
     * ML usa "Tratamientos para Manos y Pies…" (contiene "tratamiento") y eso no debe
     * activar reglas de cabello. Incluye cremas/lociones corporales y suplementos en gomitas.
     */
    protected function isHandFootBodySkinOrSupplementContext(string $text, string $categoryName = ''): bool
    {
        $cat = mb_strtolower(trim($categoryName));
        $combined = mb_strtolower(trim($text)) . ' ' . $cat;

        if ($this->isDietarySupplementProductByText($combined)) {
            return true;
        }

        if (
            str_contains($cat, 'manos y pies')
            || str_contains($cat, 'cremas para manos')
            || str_contains($cat, 'crema para manos')
            || str_contains($cat, 'cuidado de manos')
        ) {
            return true;
        }

        return $this->containsAny($combined, [
            'crema de manos',
            'crema para manos',
            'hand cream',
            'working hands',
            'loción corporal',
            'locion corporal',
            'body lotion',
            'loción para piel',
            'locion para piel',
            'gomitas',
            'gummies',
            'gummy',
            'vitamina',
            'vitamin',
            'suplemento',
            'dietary supplement',
            'multivitamin',
            'hair skin nails',
        ]);
    }

    /**
     * ML: "Tratamientos para Manos y Pies – Cremas para Manos y Pies" (ids reales en DB).
     */
    protected function isMlManosPiesCremasCategory(Product $product): bool
    {
        $cid = trim((string) ($product->category_id ?? ''));
        if (in_array($cid, ['MLM192034', 'MLM19034'], true)) {
            return true;
        }

        $cat = $this->normalizedCategoryName($product);

        return str_contains($cat, 'cremas para manos')
            && (str_contains($cat, 'manos y pies') || (str_contains($cat, 'manos') && str_contains($cat, 'pies')));
    }

    /**
     * Biotina / "hair, skin & nails" en cápsulas suele publicarse en ML bajo categorías de cabello;
     * priorizar formato oral (mcg, pz, unidades, cápsulas) antes que palabras de marketing "cabello".
     */
    protected function isOralHairSkinNailsSupplementByText(string $t): bool
    {
        if ($t === '') {
            return false;
        }

        $hasDoseOrCount = preg_match('/\b\d[\d.,]*\s*(mcg|mg|iu|ui)\b/iu', $t) === 1
            || preg_match('/\b\d+\s*pz\b|\b\d+pz\b/iu', $t) === 1
            || preg_match('/\b\d+\s*unidades\b/iu', $t) === 1;

        $hasOralForm = $this->containsAny($t, [
            'softgel',
            'softgels',
            'cápsulas',
            'capsulas',
            'cápsula',
            'capsula',
            'tabletas',
            'tablets',
            'vegcaps',
            'gomitas',
            'gummies',
            'gummy',
        ]);

        if (!$hasDoseOrCount && !$hasOralForm) {
            return false;
        }

        $hasNutrient = $this->containsAny($t, [
            'biotin',
            'biotina',
            'keratin',
            'keratina',
            'queratina',
            'collagen',
            'colágeno',
            'vitamina',
            'vitamin',
            'suplemento',
            'supplement',
            'multivitamin',
            'omega',
            'spring valley',
            "nature's bounty",
            'natures bounty',
        ]);

        if (!$hasNutrient) {
            return false;
        }

        if ($this->containsAny($t, [
            'shampoo',
            'champú',
            'champu',
            'acondicionador',
            'conditioner',
            'mascarilla',
            'hair mask',
            'leave in',
            'leave-in',
        ])) {
            return false;
        }

        return true;
    }

    protected function isDietarySupplementProductByText(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        if ($this->isOralHairSkinNailsSupplementByText($t)) {
            return true;
        }

        if ($this->containsAny($t, [
            'hair care',
            'hair shampoo',
            'hair conditioner',
            'hair mask',
            'hair treatment',
            'hair treatments',
            'shampoo',
            'champú',
            'champu',
            'acondicionador',
            'conditioner',
            'mascarilla',
            'leave in',
            'leave-in',
            'shampoos y acondicionadores',
            'davines',
            'wella',
            'alfaparf',
            'loreal professionnel',
            'l’oréal professionnel',
            'metal detox',
            'semi di lino',
            'invigo',
            'nutri enrich',
        ])) {
            return false;
        }

        if ($this->containsAny($t, [
            'rubor',
            'blush',
            'blusher',
            'bronceador',
            'bronzer',
            'iluminador',
            'maquillaje',
            'cosmetic',
            'cream blush',
            'ombre blush',
            'corrector',
            'concealer',
            'foundation',
            'foundations',
            'base de maquillaje',
            'bases de maquillaje',
            'base cremosa',
            'complete wear',
            'cream bronzer',
            'acabado mate',
        ])) {
            return false;
        }

        return $this->containsAny($text, [
            'gomitas',
            'gummies',
            'gummy',
            'vitamina',
            'vitamin',
            'suplemento',
            'supplement',
            'multivitamin',
            'hair skin nails',
            "nature's bounty",
            'natures bounty',
            'cápsulas',
            'capsulas',
            'cápsula',
            'capsula',
            'softgel',
            'softgels',
            'tabletas',
            'tablets',
            'vegcaps',
            'mcg',
            'coq10',
            'coq 10',
            'dhea',
            'lactaid',
            'melatonina',
            'melatonin',
            'ashwagandha',
            'l-carnitina',
            'carnitina',
            'sleep aid',
            'nutricional',
            'nutritional',
            'omega',
            'fish oil',
            'aceite de pescado',
            'aceite de oregano',
            'orégano',
            'oregano',
            'coenzima q10',
            'coenzyme q10',
            'coq-10',
            'biotina',
            'biotin',
            'ácido fólico',
            'acido folico',
            'curcuma',
            'cúrcuma',
            'probiotic',
            'probiótico',
            'probiotico',
        ]);
    }

    protected function resolveDirectCategory(Product $product): ?array
    {
        $text = $this->fullContext($product);
        $listingText = $this->compactListingText($product);
        $categoryName = $this->normalizedCategoryName($product);

        if ($this->isNailSculptedPinzasClipsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_NAIL_TOOLS_ID,
                self::CATEGORY_NAIL_TOOLS_NAME,
                'direct_ml_nail_tools'
            );
        }

        if ($this->isMlFajasCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_WAIST_CINCHERS_ID,
                self::CATEGORY_WAIST_CINCHERS_NAME,
                'direct_ml_fajas_shapewear'
            );
        }

        if ($this->isHairColoringAccessoriesContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_COLORING_ACCESSORIES_ID,
                self::CATEGORY_HAIR_COLORING_ACCESSORIES_NAME,
                'direct_hair_coloring_accessories_rule'
            );
        }

        if ($this->isMlMueblesEsteticaLavabosCategory($product)) {
            return $this->resolveMlEsteticaLavabosShopifyCategory($product);
        }

        if ($this->isMlMueblesEsteticaCarrosAuxiliaresCategory($product)) {
            return $this->resolveMlEsteticaCarrosAuxiliaresShopifyCategory($product);
        }

        if ($this->isMlMueblesEsteticaSillasCategory($product)) {
            return $this->resolveMlEsteticaSillasShopifyCategory($product);
        }

        if ($this->isRingLightContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_STUDIO_LIGHTS_FLASHES_ID,
                self::CATEGORY_STUDIO_LIGHTS_FLASHES_NAME,
                'direct_ring_light_rule'
            );
        }

        if ($this->isHairExtensionToolContext($text, $categoryName, $product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_EXTENSION_TOOLS_ID,
                self::CATEGORY_HAIR_EXTENSION_TOOLS_NAME,
                'direct_hair_extension_tool_rule'
            );
        }

        if ($this->isMlHairExtensionsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_EXTENSIONS_ID,
                self::CATEGORY_HAIR_EXTENSIONS_NAME,
                'direct_hair_extensions_rule'
            );
        }

        if ($this->isShavingCreamContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SHAVING_CREAMS_ID,
                self::CATEGORY_SHAVING_CREAMS_NAME,
                'direct_shaving_cream_rule'
            );
        }

        if ($this->isDeodorantContext($text, $categoryName)) {
            return $this->resolveDeodorantShopifyCategory($text, $categoryName);
        }

        if ($this->isMlRostroIluminadoresRuboresCategory($product)) {
            return $this->resolveRostroIluminadoresRuboresShopifyCategory($text);
        }

        if ($this->isMlRostroCorrectoresCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CONCEALERS_ID,
                self::CATEGORY_CONCEALERS_NAME,
                'direct_ml_rostro_concealer'
            );
        }

        if ($this->isMlRostroBasesMaquillajeCategory($product)) {
            return $this->resolveRostroBasesMaquillajeShopifyCategory($text);
        }

        if ($this->isMlLashBrowTreatmentCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_ID,
                self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_NAME,
                'direct_ml_lash_brow_treatment'
            );
        }

        if ($this->isMlLashAdhesiveCategory($product)) {
            return $this->resolveLashAdhesiveShopifyCategory($text);
        }

        if ($this->isMlCharmsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CHARMS_PENDANTS_ID,
                self::CATEGORY_CHARMS_PENDANTS_NAME,
                'direct_charms_rule'
            );
        }

        if ($this->isMlBraceletsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BRACELETS_ID,
                self::CATEGORY_BRACELETS_NAME,
                'direct_bracelet_rule'
            );
        }

        if ($this->isLipPlumperContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LIP_GLOSS_ID,
                self::CATEGORY_LIP_GLOSS_NAME,
                'direct_lip_plumper'
            );
        }

        if ($this->isMlLipBalmsCategory($product)) {
            return $this->resolveLipBalmsShopifyCategory($text);
        }

        if ($this->isMlLipsticksCategory($product)) {
            return $this->resolveLipsticksShopifyCategory($text);
        }

        if ($this->isMlFacialCleansingOtherCategory($product)) {
            return $this->resolveFacialCleansingOtherShopifyCategory($text);
        }

        if ($this->isMlMakeupCasesCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_REFILLABLE_MAKEUP_CASES_ID,
                self::CATEGORY_REFILLABLE_MAKEUP_CASES_NAME,
                'direct_makeup_case'
            );
        }

        if ($this->isMlEyelashExtensionsCategory($product)) {
            return $this->resolveEyelashExtensionsShopifyCategory($text);
        }

        if ($this->isMlEyeLashBrowOtherProductsCategory($product)) {
            return $this->resolveEyeLashBrowOtherProductsShopifyCategory($text);
        }

        if ($this->isMlEyeShadowsCategory($product)) {
            return $this->resolveEyeShadowsShopifyCategory($text);
        }

        if ($this->isAfterSunBodyWashContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BODY_WASH_ID,
                self::CATEGORY_BODY_WASH_NAME,
                'direct_after_sun_body_wash'
            );
        }

        if ($this->isMlSunProtectionCategory($product) || $this->isSunscreenContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SUNSCREEN_ID,
                self::CATEGORY_SUNSCREEN_NAME,
                'direct_ml_sunscreen'
            );
        }

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_MAKEUP_FINISHING_SPRAYS_ID,
                self::CATEGORY_MAKEUP_FINISHING_SPRAYS_NAME,
                'direct_makeup_finishing_spray'
            );
        }

        // Cera fibrosa / Osis Thrill: la descripción ML suele mencionar mask/serum y dispara
        // isSingleHairTreatmentProductContext antes que el peinado; forzamos styling aquí.
        $hayHairSku = mb_strtolower(trim($text . ' ' . $categoryName));
        if ($this->isHairContext($text, $categoryName) && $this->containsAny($hayHairSku, [
            'cera fibrosa',
            'osis+ thrill',
            'osis thrill',
        ])) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'direct_cera_fibrosa_osis_styling'
            );
        }

        if ($this->isHairFiberContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'direct_hair_fiber_rule'
            );
        }

        if ($this->isHairContext($text, $categoryName) && $this->isHairLacaHairsprayCompactSignal($listingText)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'direct_hair_laca_compact_rule'
            );
        }

        if ($this->isHairCareKitContext($text, $categoryName, $listingText)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_CARE_KITS_ID,
                self::CATEGORY_HAIR_CARE_KITS_NAME,
                'direct_hair_care_kit_rule'
            );
        }

        if ($this->isLeaveInConditionerContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CONDITIONER_ID,
                self::CATEGORY_CONDITIONER_NAME,
                'direct_leave_in_conditioner_rule'
            );
        }

        if ($this->isHairAmpouleTreatmentContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'direct_hair_ampoule_treatment'
            );
        }

        if ($this->isSingleHairTreatmentProductContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'direct_single_hair_treatment_rule'
            );
        }

        if ($this->isShampooContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SHAMPOO_ID,
                self::CATEGORY_SHAMPOO_NAME,
                'direct_local_rule'
            );
        }

        if ($this->isConditionerContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CONDITIONER_ID,
                self::CATEGORY_CONDITIONER_NAME,
                'direct_conditioner_rule'
            );
        }

        if ($this->isHairStylingProductContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'direct_hair_styling_rule'
            );
        }

        if ($this->isHairOilContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'direct_hair_oil_rule'
            );
        }

        if (
            $this->isHairBleachContext($text, $categoryName) ||
            $this->isHairColorRemoverContext($text, $categoryName) ||
            $this->isHairColorContext($text, $categoryName)
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_COLOR_ID,
                self::CATEGORY_HAIR_COLOR_NAME,
                'direct_local_rule'
            );
        }

        if (
            $this->isMlSportsSupplementsCategory($product) ||
            $this->isDietarySupplementProductByText($text)
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_VITAMINS_SUPPLEMENTS_ID,
                self::CATEGORY_VITAMINS_SUPPLEMENTS_NAME,
                'direct_supplement_rule'
            );
        }

        if ($this->isHairTreatmentContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'direct_local_rule'
            );
        }

        /**
         * Para conditioner no meto ID fijo porque en tus capturas
         * todavía no tenemos confirmado el gid exacto del leaf correcto.
         * Aquí preferimos búsqueda validada antes que inventar una categoría.
         */
        return null;
    }

    protected function resolveSafeFallbackCategory(Product $product): ?array
    {
        $text = $this->fullContext($product);
        $listingText = $this->compactListingText($product);
        $categoryName = $this->normalizedCategoryName($product);

        if ($this->isNailSculptedPinzasClipsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_NAIL_TOOLS_ID,
                self::CATEGORY_NAIL_TOOLS_NAME,
                'safe_fallback_ml_nail_tools'
            );
        }

        if ($this->isMlFajasCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_WAIST_CINCHERS_ID,
                self::CATEGORY_WAIST_CINCHERS_NAME,
                'safe_fallback_ml_fajas_shapewear'
            );
        }

        if ($this->isHairColoringAccessoriesContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_COLORING_ACCESSORIES_ID,
                self::CATEGORY_HAIR_COLORING_ACCESSORIES_NAME,
                'safe_fallback_hair_coloring_accessories_rule'
            );
        }

        if ($this->isMlMueblesEsteticaLavabosCategory($product)) {
            return $this->resolveMlEsteticaLavabosShopifyCategory($product, true);
        }

        if ($this->isMlMueblesEsteticaCarrosAuxiliaresCategory($product)) {
            return $this->resolveMlEsteticaCarrosAuxiliaresShopifyCategory($product, true);
        }

        if ($this->isMlMueblesEsteticaSillasCategory($product)) {
            return $this->resolveMlEsteticaSillasShopifyCategory($product, true);
        }

        if ($this->isRingLightContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_STUDIO_LIGHTS_FLASHES_ID,
                self::CATEGORY_STUDIO_LIGHTS_FLASHES_NAME,
                'safe_fallback_ring_light_rule'
            );
        }

        if ($this->isHairExtensionToolContext($text, $categoryName, $product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_EXTENSION_TOOLS_ID,
                self::CATEGORY_HAIR_EXTENSION_TOOLS_NAME,
                'safe_fallback_hair_extension_tool_rule'
            );
        }

        if ($this->isMlHairExtensionsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_EXTENSIONS_ID,
                self::CATEGORY_HAIR_EXTENSIONS_NAME,
                'safe_fallback_hair_extensions_rule'
            );
        }

        if ($this->isShavingCreamContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SHAVING_CREAMS_ID,
                self::CATEGORY_SHAVING_CREAMS_NAME,
                'safe_fallback_shaving_cream_rule'
            );
        }

        if ($this->isDeodorantContext($text, $categoryName)) {
            $resolved = $this->resolveDeodorantShopifyCategory($text, $categoryName);
            $resolved['source'] = 'safe_fallback_deodorant_rule';

            return $resolved;
        }

        if ($this->isMlRostroIluminadoresRuboresCategory($product)) {
            return $this->resolveRostroIluminadoresRuboresShopifyCategory($text);
        }

        if ($this->isMlRostroCorrectoresCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CONCEALERS_ID,
                self::CATEGORY_CONCEALERS_NAME,
                'safe_fallback_ml_rostro_concealer'
            );
        }

        if ($this->isMlRostroBasesMaquillajeCategory($product)) {
            return $this->resolveRostroBasesMaquillajeShopifyCategory($text);
        }

        if ($this->isMlLashBrowTreatmentCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_ID,
                self::CATEGORY_LASH_BROW_GROWTH_TREATMENTS_NAME,
                'safe_fallback_ml_lash_brow_treatment'
            );
        }

        if ($this->isMlLashAdhesiveCategory($product)) {
            return $this->resolveLashAdhesiveShopifyCategory($text);
        }

        if ($this->isMlCharmsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CHARMS_PENDANTS_ID,
                self::CATEGORY_CHARMS_PENDANTS_NAME,
                'safe_fallback_charms_rule'
            );
        }

        if ($this->isMlBraceletsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BRACELETS_ID,
                self::CATEGORY_BRACELETS_NAME,
                'safe_fallback_bracelet_rule'
            );
        }

        if ($this->isLipPlumperContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_LIP_GLOSS_ID,
                self::CATEGORY_LIP_GLOSS_NAME,
                'safe_fallback_lip_plumper'
            );
        }

        if ($this->isMlLipBalmsCategory($product)) {
            return $this->resolveLipBalmsShopifyCategory($text);
        }

        if ($this->isMlLipsticksCategory($product)) {
            return $this->resolveLipsticksShopifyCategory($text);
        }

        if ($this->isMlFacialCleansingOtherCategory($product)) {
            return $this->resolveFacialCleansingOtherShopifyCategory($text);
        }

        if ($this->isMlMakeupCasesCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_REFILLABLE_MAKEUP_CASES_ID,
                self::CATEGORY_REFILLABLE_MAKEUP_CASES_NAME,
                'safe_fallback_makeup_case'
            );
        }

        if ($this->isMlEyelashExtensionsCategory($product)) {
            return $this->resolveEyelashExtensionsShopifyCategory($text);
        }

        if ($this->isMlEyeLashBrowOtherProductsCategory($product)) {
            return $this->resolveEyeLashBrowOtherProductsShopifyCategory($text);
        }

        if ($this->isMlEyeShadowsCategory($product)) {
            return $this->resolveEyeShadowsShopifyCategory($text);
        }

        if ($this->isAfterSunBodyWashContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_BODY_WASH_ID,
                self::CATEGORY_BODY_WASH_NAME,
                'safe_fallback_after_sun_body_wash'
            );
        }

        if ($this->isMlSunProtectionCategory($product) || $this->isSunscreenContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SUNSCREEN_ID,
                self::CATEGORY_SUNSCREEN_NAME,
                'safe_fallback_ml_sunscreen'
            );
        }

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_MAKEUP_FINISHING_SPRAYS_ID,
                self::CATEGORY_MAKEUP_FINISHING_SPRAYS_NAME,
                'safe_fallback_makeup_finishing_spray'
            );
        }

        if ($this->isHairFiberContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'safe_fallback_hair_fiber_rule'
            );
        }

        if ($this->isHairContext($text, $categoryName) && $this->isHairLacaHairsprayCompactSignal($listingText)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'safe_fallback_hair_laca_compact_rule'
            );
        }

        if ($this->isHairCareKitContext($text, $categoryName, $listingText)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_CARE_KITS_ID,
                self::CATEGORY_HAIR_CARE_KITS_NAME,
                'safe_fallback_hair_care_kit_rule'
            );
        }

        if ($this->isLeaveInConditionerContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CONDITIONER_ID,
                self::CATEGORY_CONDITIONER_NAME,
                'safe_fallback_leave_in_conditioner_rule'
            );
        }

        if ($this->isHairAmpouleTreatmentContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'safe_fallback_hair_ampoule_treatment'
            );
        }

        if ($this->isSingleHairTreatmentProductContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'safe_fallback_single_hair_treatment_rule'
            );
        }

        if ($this->isShampooContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_SHAMPOO_ID,
                self::CATEGORY_SHAMPOO_NAME,
                'safe_fallback_rule'
            );
        }

        if ($this->isConditionerContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_CONDITIONER_ID,
                self::CATEGORY_CONDITIONER_NAME,
                'safe_fallback_conditioner_rule'
            );
        }

        if ($this->isHairStylingProductContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_STYLING_PRODUCTS_ID,
                self::CATEGORY_HAIR_STYLING_PRODUCTS_NAME,
                'safe_fallback_hair_styling_rule'
            );
        }

        if ($this->isHairOilContext($text, $categoryName)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'safe_fallback_hair_oil_rule'
            );
        }

        if (
            $this->isHairBleachContext($text, $categoryName) ||
            $this->isHairColorRemoverContext($text, $categoryName) ||
            $this->isHairColorContext($text, $categoryName)
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_COLOR_ID,
                self::CATEGORY_HAIR_COLOR_NAME,
                'safe_fallback_rule'
            );
        }

        if ($this->isMlSportsSupplementsCategory($product)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_VITAMINS_SUPPLEMENTS_ID,
                self::CATEGORY_VITAMINS_SUPPLEMENTS_NAME,
                'safe_fallback_ml_sports_supplements'
            );
        }

        if ($this->isDietarySupplementProductByText($text)) {
            return $this->makeResolvedCategory(
                self::CATEGORY_VITAMINS_SUPPLEMENTS_ID,
                self::CATEGORY_VITAMINS_SUPPLEMENTS_NAME,
                'safe_fallback_supplement_text'
            );
        }

        if (
            $this->isHairOilContext($text, $categoryName) ||
            $this->isHairTreatmentContext($text, $categoryName)
        ) {
            return $this->makeResolvedCategory(
                self::CATEGORY_HAIR_TREATMENTS_ID,
                self::CATEGORY_HAIR_TREATMENTS_NAME,
                'safe_fallback_rule'
            );
        }

        return null;
    }

    protected function makeResolvedCategory(string $id, string $name, string $source): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'term' => null,
            'source' => $source,
        ];
    }

    protected function resolveCanonicalIntent(Product $product): ?array
    {
        $text = $this->fullContext($product);
        $categoryName = $this->normalizedCategoryName($product);

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            return [
                'term' => 'makeup setting spray',
                'fallback_terms' => [
                    'face makeup setting spray',
                    'makeup primer',
                    'face primer',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'cosmetics'],
                'allowed_terms' => [
                    'makeup',
                    'face makeup',
                    'primer',
                    'setting spray',
                    'cosmetics',
                ],
                'blocked_terms' => [
                    'hair care',
                    'hair color',
                    'hair treatments',
                    'bleach',
                    'shaving',
                    'grooming',
                    'facial hair',
                    'conditioner',
                    'shampoo',
                    'sporting goods',
                    'inline skates',
                    'vision care',
                    'eyeglasses',
                ],
            ];
        }

        if ($this->isMlSportsSupplementsCategory($product)) {
            return [
                'term' => 'vitamins supplements',
                'fallback_terms' => [
                    'dietary supplement',
                    'vitamins & supplements',
                    'nutritional supplements',
                ],
                'expected_branch' => ['health & beauty', 'health care', 'fitness & nutrition'],
                'allowed_terms' => [
                    'vitamin',
                    'supplement',
                    'supplements',
                    'nutrition',
                    'dietary',
                ],
                'blocked_terms' => [
                    'makeup',
                    'hair care',
                    'hair color',
                    'hair treatments',
                    'cosmetic tools',
                    'makeup brushes',
                    'sporting goods',
                    'inline skates',
                    'roller skat',
                    'skates',
                    'vision care',
                    'eyeglasses',
                ],
            ];
        }

        if ($this->isDietarySupplementProductByText($text)) {
            return [
                'term' => 'vitamins supplements',
                'fallback_terms' => [
                    'dietary supplement',
                    'vitamins & supplements',
                    'nutritional supplements',
                ],
                'expected_branch' => ['health & beauty', 'health care', 'fitness & nutrition'],
                'allowed_terms' => [
                    'vitamin',
                    'supplement',
                    'supplements',
                    'nutrition',
                    'dietary',
                ],
                'blocked_terms' => [
                    'makeup',
                    'hair care',
                    'hair color',
                    'hair treatments',
                    'cosmetic tools',
                    'makeup brushes',
                    'sporting goods',
                    'inline skates',
                    'roller skat',
                    'skates',
                    'vision care',
                    'eyeglasses',
                ],
            ];
        }

        if ($this->isConditionerContext($text, $categoryName)) {
            return [
                'term' => 'hair conditioner',
                'fallback_terms' => [
                    'conditioners',
                    'conditioner',
                    'hair care conditioner',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'conditioner',
                    'conditioners',
                    'hair care',
                    'shampoo & conditioner',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face powder',
                    'face makeup',
                    'vision care',
                    'eyeglasses',
                    'lotions',
                    'moisturizers',
                    'skin care',
                ],
            ];
        }

        if ($this->isHairBleachContext($text, $categoryName)) {
            return [
                'term' => 'hair bleach',
                'fallback_terms' => [
                    'hair lightener',
                    'hair color',
                    'hair care',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'hair care',
                    'hair color',
                    'bleach',
                    'lightener',
                    'developer',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face makeup',
                    'face powder',
                    'cosmetics > makeup',
                    'vision care',
                    'eyeglasses',
                    'painting consumables',
                    'hardware',
                    'primers',
                    'medical tape',
                    'bandages',
                ],
            ];
        }

        if ($this->isHairColorRemoverContext($text, $categoryName)) {
            return [
                'term' => 'hair color',
                'fallback_terms' => [
                    'hair care',
                    'hair treatments',
                    'hair lightener',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'hair care',
                    'hair color',
                    'hair treatments',
                    'lightener',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face makeup',
                    'face powder',
                    'cosmetics > makeup',
                    'vision care',
                    'eyeglasses',
                    'painting consumables',
                    'hardware',
                    'primers',
                    'medical tape',
                    'bandages',
                ],
            ];
        }

        if ($this->isShampooContext($text, $categoryName)) {
            return [
                'term' => 'shampoo',
                'fallback_terms' => [
                    'hair shampoo',
                    'hair care',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'shampoo',
                    'hair care',
                    'shampoo & conditioner',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face powder',
                    'face makeup',
                    'vision care',
                    'eyeglasses',
                    'lotions',
                    'moisturizers',
                    'skin care',
                ],
            ];
        }

        if ($this->isHairColorContext($text, $categoryName)) {
            return [
                'term' => 'hair color',
                'fallback_terms' => ['hair care'],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'color',
                    'hair color',
                    'hair care',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face powder',
                    'face makeup',
                    'vision care',
                    'eyeglasses',
                ],
            ];
        }

        if ($this->isHairOilContext($text, $categoryName)) {
            return [
                'term' => 'hair oil',
                'fallback_terms' => [
                    'hair oils',
                    'hair treatments',
                    'hair care',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'hair oil',
                    'hair oils',
                    'hair treatments',
                    'hair care',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face powder',
                    'face makeup',
                    'vision care',
                    'eyeglasses',
                    'painting consumables',
                    'hardware',
                    'primers',
                    'lotions',
                    'moisturizers',
                    'skin care',
                ],
            ];
        }

        if ($this->isHairTreatmentContext($text, $categoryName)) {
            return [
                'term' => 'hair treatments',
                'fallback_terms' => [
                    'hair treatment',
                    'hair mask',
                    'hair serum',
                    'hair care',
                ],
                'expected_branch' => ['health & beauty', 'personal care', 'hair care'],
                'allowed_terms' => [
                    'hair',
                    'hair treatments',
                    'hair treatment',
                    'hair care',
                    'hair mask',
                    'hair serum',
                    'conditioners',
                    'shampoo',
                ],
                'blocked_terms' => [
                    'makeup',
                    'face powder',
                    'face makeup',
                    'vision care',
                    'eyeglasses',
                    'painting consumables',
                    'hardware',
                    'primers',
                    'lotions',
                    'moisturizers',
                    'skin care',
                ],
            ];
        }

        return null;
    }

    protected function searchTaxonomyCategory(string $term, Product $product, ?array $intent = null): ?array
    {
        if ($this->taxonomySearchCallsThisResolution >= 20) {
            Log::warning('[SHOPIFY] Tope de búsquedas taxonomy GraphQL por producto', [
                'product_id' => $product->id,
                'ml' => $product->ml,
                'term_preview' => mb_substr($term, 0, 120),
            ]);

            return null;
        }

        $this->taxonomySearchCallsThisResolution++;

        $this->noteCategoryResolveProgress(sprintf(
            'taxonomy HTTP #%d product_id=%s ml=%s term=%s',
            $this->taxonomySearchCallsThisResolution,
            (string) ($product->id ?? ''),
            (string) ($product->ml ?? ''),
            mb_substr($term, 0, 100)
        ));

        $shop = trim((string) config('services.shopify.store_domain'));
        $token = $this->tokenService->getAccessToken();
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        if ($shop === '' || $token === '') {
            throw new \RuntimeException('Falta configuración de Shopify.');
        }

        $query = <<<'GRAPHQL'
query SearchTaxonomyCategories($search: String!) {
  taxonomy {
    categories(first: 25, search: $search) {
      edges {
        node {
          id
          fullName
        }
      }
    }
  }
}
GRAPHQL;

        $connectTimeout = (int) config('services.shopify.taxonomy_connect_timeout', 8);
        $requestTimeout = (int) config('services.shopify.taxonomy_timeout', 20);
        $retryTimes = (int) config('services.shopify.taxonomy_retry_times', 2);
        $retryDelayMs = (int) config('services.shopify.taxonomy_retry_delay_ms', 400);

        $t0 = microtime(true);
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->connectTimeout($connectTimeout)
                ->timeout($requestTimeout)
                ->retry($retryTimes, $retryDelayMs)
                ->post("https://{$shop}/admin/api/{$apiVersion}/graphql.json", [
                    'query' => $query,
                    'variables' => [
                        'search' => $term,
                    ],
                ]);
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            Log::error('[SHOPIFY] GraphQL taxonomy excepción (red, timeout o SSL)', [
                'product_id' => $product->id,
                'ml' => $product->ml ?? null,
                'ms' => $ms,
                'term_preview' => mb_substr($term, 0, 120),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        Log::info('[SHOPIFY] GraphQL taxonomy HTTP', [
            'product_id' => $product->id,
            'ml' => $product->ml ?? null,
            'ms' => $ms,
            'http_status' => $response->status(),
            'term_preview' => mb_substr($term, 0, 100),
        ]);

        if (!$response->successful()) {
            Log::error('Shopify GraphQL HTTP error', [
                'term' => $term,
                'status' => $response->status(),
                'body' => $response->body(),
                'product_id' => $product->id ?? null,
                'product_name' => $product->name ?? null,
            ]);

            throw new \RuntimeException('Error Shopify GraphQL: ' . $response->body());
        }

        $json = $response->json();

        if (!empty($json['errors'])) {
            Log::error('Shopify GraphQL returned errors', [
                'term' => $term,
                'errors' => $json['errors'],
                'product_id' => $product->id ?? null,
                'product_name' => $product->name ?? null,
            ]);

            throw new \RuntimeException('Error Shopify GraphQL: ' . json_encode($json['errors']));
        }

        $edges = data_get($json, 'data.taxonomy.categories.edges', []);

        Log::info('Shopify taxonomy search response', [
            'term' => $term,
            'edges_count' => is_countable($edges) ? count($edges) : 0,
            'first_edges' => collect($edges)->take(5)->values()->all(),
            'product_id' => $product->id ?? null,
            'product_name' => $product->name ?? null,
        ]);

        if (empty($edges)) {
            return null;
        }

        $best = $this->pickBestCategoryFromEdges($edges, $term, $product, $intent);

        Log::info('Shopify taxonomy best match', [
            'term' => $term,
            'best' => $best,
            'product_id' => $product->id ?? null,
            'product_name' => $product->name ?? null,
        ]);

        if (!$best) {
            return null;
        }

        return [
            'id' => $best['id'] ?? null,
            'name' => $best['name'] ?? null,
            'term' => $term,
        ];
    }

    protected function pickBestCategoryFromEdges(array $edges, string $term, Product $product, ?array $intent = null): ?array
    {
        $term = mb_strtolower(trim($term));
        $context = $this->fullContext($product);
        $categoryName = $this->normalizedCategoryName($product);

        $expectedBranch = collect($intent['expected_branch'] ?? [])
            ->map(fn ($v) => mb_strtolower(trim((string) $v)))
            ->filter()
            ->values()
            ->all();

        $allowedTerms = collect($intent['allowed_terms'] ?? [])
            ->map(fn ($v) => mb_strtolower(trim((string) $v)))
            ->filter()
            ->values()
            ->all();

        $blockedTerms = collect($intent['blocked_terms'] ?? [])
            ->map(fn ($v) => mb_strtolower(trim((string) $v)))
            ->filter()
            ->values()
            ->all();

        $candidates = collect($edges)
            ->map(function ($edge) {
                return [
                    'id' => data_get($edge, 'node.id'),
                    'name' => data_get($edge, 'node.fullName'),
                ];
            })
            ->filter(fn ($row) => !empty($row['id']) && !empty($row['name']))
            ->values();

        if ($this->isMlMueblesEsteticaLavabosCategory($product)) {
            $candidates = $candidates->filter(function ($row) {
                $name = mb_strtolower((string) ($row['name'] ?? ''));

                if (str_contains($name, 'health & beauty')) {
                    return false;
                }

                return true;
            })->values();
        }

        if ($this->isMlMueblesEsteticaCarrosAuxiliaresCategory($product)) {
            $candidates = $candidates->filter(function ($row) {
                $name = mb_strtolower((string) ($row['name'] ?? ''));

                return ! $this->containsAny($name, [
                    'hair color',
                    'hair care >',
                    'charms & pendants',
                    'jewelry',
                    'makeup',
                    'lip makeup',
                    'eye makeup',
                ]);
            })->values();
        }

        // ML “Tratamientos para el cabello” agrupa muchas familias; al buscar taxonomía Shopify descartamos ramas claramente incorrectas.
        if (strtoupper(trim((string) ($product->category_id ?? ''))) === 'MLM171894') {
            $candidates = $candidates->filter(function ($row) {
                $name = mb_strtolower((string) ($row['name'] ?? ''));

                return ! $this->containsAny($name, [
                    'charms & pendants',
                    'jewelry',
                    'earrings',
                    'bracelets',
                    'lip makeup',
                    'eye makeup',
                    'foundations',
                    'concealers',
                    'vitamins & supplements',
                ]);
            })->values();
        }

        if ($this->isMlMueblesEsteticaSillasCategory($product)) {
            $candidates = $candidates->filter(function ($row) {
                $name = mb_strtolower((string) ($row['name'] ?? ''));

                if (str_contains($name, 'health & beauty') && str_contains($name, 'cosmetics')) {
                    return false;
                }

                if (str_contains($name, 'hair care') && str_contains($name, 'hair color')) {
                    return false;
                }

                return !$this->containsAny($name, [
                    'makeup',
                    'lip makeup',
                    'eye makeup',
                    'foundations',
                    'concealers',
                ]);
            })->values();
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        $isHair = $this->isHairContext($context, $categoryName);
        $isHairOil = $this->isHairOilContext($context, $categoryName);
        $isHairTreatment = $this->isHairTreatmentContext($context, $categoryName);
        $isHairBleach = $this->isHairBleachContext($context, $categoryName);
        $isHairColorRemover = $this->isHairColorRemoverContext($context, $categoryName);
        $isHairColor = $this->isHairColorContext($context, $categoryName);
        $isConditioner = $this->isConditionerContext($context, $categoryName);
        $isShampoo = $this->isShampooContext($context, $categoryName);
        $isHairStylingProduct = $this->isHairStylingProductContext($context, $categoryName);

        $scored = $candidates->map(function ($row) use (
            $term,
            $expectedBranch,
            $allowedTerms,
            $blockedTerms,
            $isHair,
            $isHairOil,
            $isHairTreatment,
            $isHairBleach,
            $isHairColorRemover,
            $isHairColor,
            $isConditioner,
            $isShampoo,
            $isHairStylingProduct
        ) {
            $name = mb_strtolower((string) $row['name']);

            foreach ($blockedTerms as $blocked) {
                if ($blocked !== '' && str_contains($name, $blocked)) {
                    return null;
                }
            }

            if (!empty($expectedBranch) && !$this->nameMatchesExpectedBranch($name, $expectedBranch)) {
                return null;
            }

            if (!empty($allowedTerms) && !$this->containsAny($name, $allowedTerms)) {
                return null;
            }

            if (
                ($isHair || $isHairOil || $isHairTreatment || $isHairBleach || $isHairColorRemover || $isHairColor || $isConditioner || $isShampoo) &&
                $this->containsAny($name, [
                    'makeup',
                    'face makeup',
                    'face powder',
                    'vision care',
                    'eyeglasses',
                    'eyewear',
                    'hardware',
                    'painting consumables',
                    'primers',
                    'industrial',
                    'medical',
                    'sporting goods',
                    'arts & entertainment',
                    'eyelets',
                    'grommets',
                    'lotions',
                    'moisturizers',
                    'skin care',
                    'hair styling tool',
                    'hair styling tools',
                    'hair styling tool sets',
                ])
            ) {
                return null;
            }

            if (
                ($isHair || $isHairOil || $isHairTreatment || $isHairBleach || $isHairColorRemover || $isHairColor || $isConditioner || $isShampoo) &&
                !$this->containsAny($name, [
                    'hair',
                    'hair care',
                    'hair treatments',
                    'hair treatment',
                    'hair oil',
                    'hair oils',
                    'hair mask',
                    'hair serum',
                    'hair color',
                    'bleach',
                    'lightener',
                    'conditioners',
                    'conditioner',
                    'shampoo',
                    'shampoo & conditioner',
                ])
            ) {
                return null;
            }

            if (
                $isConditioner &&
                str_contains($name, '> shampoo') &&
                !$this->containsAny($name, ['conditioner', 'conditioners'])
            ) {
                return null;
            }

            if (
                $isShampoo &&
                str_contains($name, '> conditioner') &&
                !str_contains($name, '> shampoo')
            ) {
                return null;
            }

            $score = 0;

            if ($name === $term) {
                $score += 1000;
            }

            if (str_contains($name, $term)) {
                $score += 300;
            }

            similar_text(
                mb_substr($name, 0, 180),
                mb_substr($term, 0, 180),
                $percent
            );
            $score += (int) $percent;

            foreach ($expectedBranch as $good) {
                if (str_contains($name, $good)) {
                    $score += 160;
                }
            }

            foreach ($allowedTerms as $good) {
                if (str_contains($name, $good)) {
                    $score += 220;
                }
            }

            if (
                ($isHair || $isHairOil || $isHairTreatment || $isHairBleach || $isHairColorRemover || $isHairColor || $isConditioner || $isShampoo) &&
                $this->containsAny($name, [
                    'hair care',
                    'hair treatments',
                    'hair treatment',
                    'hair oils',
                    'hair oil',
                    'hair color',
                    'bleach',
                    'lightener',
                    'conditioners',
                    'conditioner',
                    'shampoo',
                    'shampoo & conditioner',
                ])
            ) {
                $score += 2000;
            }

            if (
                $isHairBleach &&
                $this->containsAny($name, [
                    'bleach',
                    'lightener',
                    'hair color',
                    'hair care',
                ])
            ) {
                $score += 2600;
            }

            if (
                $isHairStylingProduct &&
                $this->containsAny($name, [
                    'hair styling products',
                    'hair mousse',
                    'hair gel',
                    'hair wax',
                    'pomade',
                    'hair spray',
                    'texturizing spray',
                ])
            ) {
                $score += 2900;
            }

            if (
                $isHairColorRemover &&
                $this->containsAny($name, [
                    'hair color',
                    'hair care',
                    'hair treatments',
                    'lightener',
                ])
            ) {
                $score += 2400;
            }

            if (
                $isHairColor &&
                $this->containsAny($name, [
                    'hair color',
                    'hair care',
                ])
            ) {
                $score += 2300;
            }

            if (
                $isHairOil &&
                $this->containsAny($name, [
                    'hair oils',
                    'hair oil',
                    'hair treatments',
                    'hair care',
                ])
            ) {
                $score += 2200;
            }

            if (
                $isHairTreatment &&
                $this->containsAny($name, [
                    'hair treatments',
                    'hair treatment',
                    'hair care',
                    'hair mask',
                    'hair serum',
                    'conditioners',
                ])
            ) {
                $score += 2200;
            }

            if (
                $isConditioner &&
                $this->containsAny($name, [
                    'conditioner',
                    'conditioners',
                    'shampoo & conditioner',
                    'hair care',
                ])
            ) {
                $score += 2600;
            }

            if (
                $isShampoo &&
                $this->containsAny($name, [
                    'shampoo',
                    'shampoo & conditioner',
                    'hair care',
                ])
            ) {
                $score += 2600;
            }

            $row['score'] = $score;
            return $row;
        })->filter()->sortByDesc('score')->values();

        $winner = $scored->first();

        if (!$winner) {
            return null;
        }

        if (($winner['score'] ?? 0) < 120) {
            return null;
        }

        return $winner;
    }

    protected function buildSearchTerms(Product $product): array
    {
        $name = mb_strtolower(trim((string) $product->name));
        $brand = mb_strtolower(trim((string) $product->brand));
        $categoryName = $this->normalizedCategoryName($product);
        $text = $this->fullContext($product);

        $terms = [];

        if ($this->isMlMueblesEsteticaLavabosCategory($product)) {
            $terms[] = 'salon shampoo bowl';
            $terms[] = 'shampoo sink';
            $terms[] = 'portable shampoo basin';
        }

        if ($this->isMlMueblesEsteticaCarrosAuxiliaresCategory($product)) {
            $terms[] = 'salon utility cart';
            $terms[] = 'rolling utility cart';
            $terms[] = 'furniture cart';
        }

        if ($this->isMlMueblesEsteticaSillasCategory($product)) {
            $terms[] = 'salon styling chair';
            $terms[] = 'salon chair';
            $terms[] = 'barber chair';
        }

        if ($this->isHairBleachContext($text, $categoryName) && !$this->isMakeupSettingSprayContext($text, $categoryName)) {
            $terms[] = 'hair bleach';
            $terms[] = 'hair lightener';
            $terms[] = 'hair color';
            $terms[] = 'hair care';
        }

        if ($this->isHairColorRemoverContext($text, $categoryName)) {
            $terms[] = 'hair color';
            $terms[] = 'hair treatments';
            $terms[] = 'hair care';
            $terms[] = 'hair lightener';
        }

        if ($this->isHairColorContext($text, $categoryName)) {
            $terms[] = 'hair color';
            $terms[] = 'hair care';
        }

        if ($this->isShampooContext($text, $categoryName)) {
            $terms[] = 'shampoo';
            $terms[] = 'hair shampoo';
            $terms[] = 'hair care';
        }

        if ($this->isConditionerContext($text, $categoryName)) {
            $terms[] = 'hair conditioner';
            $terms[] = 'conditioners';
            $terms[] = 'conditioner';
            $terms[] = 'hair care conditioner';
        }

        if ($this->isHairOilContext($text, $categoryName)) {
            $terms[] = 'hair oil';
            $terms[] = 'hair oils';
            $terms[] = 'hair treatment oils';
            $terms[] = 'hair care';
        }

        if ($this->isHairTreatmentContext($text, $categoryName)) {
            $terms[] = 'hair treatments';
            $terms[] = 'hair treatment';
            $terms[] = 'hair mask';
            $terms[] = 'hair serum';
            $terms[] = 'hair care';
        }

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            $terms[] = 'makeup setting spray';
            $terms[] = 'face makeup setting spray';
            $terms[] = 'makeup primer';
            $terms[] = 'face primer';
            $terms[] = 'face makeup';
        }

        if ($categoryName !== '') {
            $terms[] = $categoryName;
        }

        if ($name !== '') {
            $terms[] = $name;
        }

        if ($brand !== '' && $name !== '') {
            $terms[] = "{$brand} {$name}";
        }

        $prepend = [];
        if ($this->isMlSportsSupplementsCategory($product)) {
            $prepend = array_merge($prepend, [
                'vitamins supplements',
                'dietary supplement',
                'nutritional supplement',
            ]);
        }

        if ($this->isMlManosPiesCremasCategory($product)) {
            if ($this->isDietarySupplementProductByText($text)) {
                $prepend = ['vitamin gummies', 'dietary supplement', 'multivitamin'];
            } else {
                $prepend = ['hand cream', 'body lotion', 'body moisturizer'];
            }
        }

        if ($this->isMlFajasCategory($product)) {
            $prepend = array_merge(
                ['waist cincher', 'shapewear', 'body shaper'],
                $prepend
            );
        }

        return collect($prepend)
            ->merge($terms)
            ->filter()
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Corta por bytes sin recorrer cadenas enormes: `strlen` en PHP usa la longitud almacenada (O(1)).
     * `mb_strcut` respeta límites UTF-8 (no parte un multibyte al final).
     */
    protected function truncateContextBytes(string $value, int $maxBytes): string
    {
        if ($value === '' || $maxBytes <= 0) {
            return '';
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maxBytes);
    }

    /**
     * `category_name` en BD a veces viene corrupto (p. ej. HTML enorme pegado en el campo).
     * Acotar por bytes antes de `trim` / `mb_strtolower` evita minutos de CPU en una sola lectura.
     */
    protected function truncatedCategoryName(Product $product, int $maxBytes = 8192): string
    {
        $raw = $product->category_name;

        return $this->truncateContextBytes($raw === null ? '' : (string) $raw, $maxBytes);
    }

    protected function normalizedCategoryName(Product $product): string
    {
        return mb_strtolower(trim($this->truncatedCategoryName($product)));
    }

    /**
     * Contexto heurístico para reglas locales: solo **nombre + marca** del listado.
     * No incluye descripción ni SKU: la descripción ML suele ser enorme y aporta poco a la taxonomía Shopify
     * frente al coste de CPU. La categoría ML (`category_name` / `category_id`) sigue en el modelo y la mayoría
     * de reglas la usan como argumento aparte de esta cadena.
     */
    protected function fullContext(Product $product): string
    {
        $name = $this->truncateContextBytes((string) ($product->name ?? ''), 8192);
        $brand = $this->truncateContextBytes((string) ($product->brand ?? ''), 8192);

        $raw = trim(implode(' ', array_filter([$name, $brand])));

        if (mb_strlen($raw) > self::FULL_CONTEXT_MAX_CHARS) {
            $raw = mb_substr($raw, 0, self::FULL_CONTEXT_MAX_CHARS);
        }

        return mb_strtolower($raw);
    }

    /**
     * Nombre + marca + SKU (sin descripción ML) para inferir kits sin ensuciar
     * con listados "shampoo, mask, aceite…" del texto largo.
     */
    protected function compactListingText(Product $product): string
    {
        $name = $this->truncateContextBytes((string) ($product->name ?? ''), 8192);
        $brand = $this->truncateContextBytes((string) ($product->brand ?? ''), 8192);
        $sku = $this->truncateContextBytes((string) ($product->sku ?? ''), 512);

        return mb_strtolower(trim(implode(' ', array_filter([
            $name,
            $brand,
            $sku,
        ]))));
    }

    protected function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function stripCategoryNameFromContext(string $text, string $categoryName = ''): string
    {
        $normalizedText = mb_strtolower(trim($text));
        $normalizedCategory = mb_strtolower(trim($categoryName));

        if ($normalizedCategory !== '') {
            $normalizedText = str_replace($normalizedCategory, ' ', $normalizedText);
        }

        return trim((string) preg_replace('/\s+/', ' ', $normalizedText));
    }

    protected function isGenericShampooConditionerCategory(string $categoryName): bool
    {
        $cat = mb_strtolower(trim($categoryName));

        return str_contains($cat, 'shampoos y acondicionadores')
            || str_contains($cat, 'shampoo y acondicionador');
    }

    protected function nameMatchesExpectedBranch(string $name, array $expectedBranch): bool
    {
        if (empty($expectedBranch)) {
            return true;
        }

        $matches = 0;

        foreach ($expectedBranch as $part) {
            if ($part !== '' && str_contains($name, $part)) {
                $matches++;
            }
        }

        if ($matches >= 2) {
            return true;
        }

        $importantLast = end($expectedBranch);
        if (is_string($importantLast) && $importantLast !== '' && str_contains($name, $importantLast)) {
            return true;
        }

        return false;
    }

    protected function isHairContext(string $text, string $categoryName = ''): bool
    {
        if ($this->isMlEsteticaLavabosOrPortableSinkContext($text, $categoryName)) {
            return false;
        }

        $hayEarly = mb_strtolower(trim($text . ' ' . $categoryName));
        if ($this->isMlEsteticaCarrosAuxiliaresCategoryPath($hayEarly)) {
            return false;
        }

        if ($this->isMlLipsticksCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlMakeupCasesCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeLashBrowOtherProductsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashAdhesiveCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeShadowsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashBrowTreatmentCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isAfterSunBodyWashContext($text, $categoryName)) {
            return false;
        }

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHandFootBodySkinOrSupplementContext($text, $categoryName)) {
            return false;
        }

        $hay = mb_strtolower(trim($text . ' ' . $categoryName));

        // "chair" contiene la subcadena "hair" → evitar falsos positivos en sillones / sillas de salón.
        if (preg_match('/\bhair\b/u', $hay) === 1) {
            return true;
        }

        return $this->containsAny($hay, [
            'cabello',
            'capilar',
            'shampoo',
            'champú',
            'champu',
            'conditioner',
            'acondicionador',
            'olio',
            'hair oil',
            'hair treatment',
            'hair treatments',
            'mascarilla',
            'ampollas',
            'semi di lino',
            'hydrator',
            'repair',
            'nutritive',
            'leave in',
            'leave-in',
            'oil rebel',
            'bonding oil',
            'dark oil',
            'silk system',
            'moringa',
            'argan',
            'argán',
            'batana',
            'olaplex',
            'joico',
            'davines',
            'alfaparf',
            'tec italy',
            'wella',
            'blondor',
            'effasor',
            'l’oréal professionnel',
            'loreal professionnel',
            'olio vital color',
            'tratamientos para el cabello',
            'cuidado del cabello',
            'tintes y decolorantes',
            'shampoos y acondicionadores',
        ]);
    }

    protected function isShampooContext(string $text, string $categoryName = ''): bool
    {
        if ($this->isMlEsteticaLavabosOrPortableSinkContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHairCareKitContext($text, $categoryName, mb_substr($text, 0, 480))) {
            return false;
        }

        if ($this->containsAny(mb_strtolower(trim($text . ' ' . $categoryName)), [
            'mascarilla',
            'hair mask',
            'ampollas',
            'ampoules',
            'serum capilar',
            'hair serum',
            'leave-in',
            'leave in',
            'detangling fluid',
        ])) {
            return false;
        }

        $textOnly = $this->stripCategoryNameFromContext($text, $categoryName);

        $hasShampoo = $this->containsAny($textOnly, [
            'shampoo',
            'champú',
            'champu',
        ]);

        if (!$hasShampoo && !$this->isGenericShampooConditionerCategory($categoryName)) {
            $hasShampoo = $this->containsAny(mb_strtolower(trim($categoryName)), [
                'shampoo',
                'champú',
                'champu',
            ]);
        }

        return $hasShampoo && !$this->isConditionerContext($text, $categoryName);
    }

    protected function isConditionerContext(string $text, string $categoryName = ''): bool
    {
        if ($this->isHairCareKitContext($text, $categoryName, mb_substr($text, 0, 480))) {
            return false;
        }

        if ($this->isLeaveInConditionerContext($text, $categoryName)) {
            return true;
        }

        $textOnly = $this->stripCategoryNameFromContext($text, $categoryName);

        if ($this->containsAny($textOnly, [
            'acondicionador',
            'conditioner',
            'conditioners',
            'balsami',
            'balsam',
            'momo conditioner',
            'replenish conditioner',
            'the conditioner',
        ])) {
            return true;
        }

        if ($this->isGenericShampooConditionerCategory($categoryName)) {
            return false;
        }

        return $this->containsAny(mb_strtolower(trim($categoryName)), [
            'acondicionador',
            'conditioner',
            'conditioners',
        ]);
    }

    protected function isHairOilContext(string $text, string $categoryName = ''): bool
    {
        $hayBaseCapilar = $this->isHairContext($text, $categoryName);

        $hayAceiteCapilar = $this->containsAny($text . ' ' . $categoryName, [
            'aceite capilar',
            'aceite para el cabello',
            'aceite para cabello',
            'hair oil',
            'hair oils',
            'olio',
            'olio vital color',
            'oil rebel',
            'bonding oil',
            'dark oil',
            'silk system',
            'argan',
            'argán',
            'coco',
            'coconut',
            'moringa',
            'batana',
            'moroccanoil',
            'aceite de batana',
            'aceite de moringa',
            'aceite de argan',
            'aceite de argán',
        ]);

        return $hayBaseCapilar && $hayAceiteCapilar;
    }

    protected function isHairTreatmentContext(string $text, string $categoryName = ''): bool
    {
        if ($this->isMlLipsticksCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlMakeupCasesCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeLashBrowOtherProductsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashAdhesiveCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeShadowsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashBrowTreatmentCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isAfterSunBodyWashContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHandFootBodySkinOrSupplementContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHairOilContext($text, $categoryName)) {
            return true;
        }

        return (
            (
                $this->hasHairTreatmentKeywords($text, $categoryName) ||
                $this->containsAny($text . ' ' . $categoryName, [
                    'repair',
                    'moisture',
                    'nutritive',
                    'leave in',
                    'leave-in',
                    'hydrator',
                    'k-pak',
                    'semi di lino',
                    'essential oil sublime',
                    'oil sublime',
                    'reparación',
                    'reparacion',
                    'fortificante',
                    'fortifying',
                    'bonding',
                    'absolut repair',
                    'therapy',
                    'nutrición',
                    'nutricion',
                ])
            ) &&
            !$this->isHairBleachContext($text, $categoryName) &&
            !$this->isHairColorRemoverContext($text, $categoryName) &&
            !$this->isHairColorContext($text, $categoryName) &&
            !$this->isConditionerContext($text, $categoryName) &&
            !$this->isShampooContext($text, $categoryName)
        );
    }

    protected function isHairBleachContext(string $text, string $categoryName = ''): bool
    {
        if (
            $this->isShampooContext($text, $categoryName)
            || $this->isConditionerContext($text, $categoryName)
            || $this->isHairOilContext($text, $categoryName)
            || $this->hasHairTreatmentKeywords($text, $categoryName)
        ) {
            return false;
        }

        if ($this->isMlLipsticksCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlMakeupCasesCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeLashBrowOtherProductsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashAdhesiveCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeShadowsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashBrowTreatmentCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isAfterSunBodyWashContext($text, $categoryName)) {
            return false;
        }

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            return false;
        }

        $hay = mb_strtolower($text . ' ' . $categoryName);
        if (str_contains($hay, 'iluminadores') && str_contains($hay, 'rubores')) {
            return false;
        }

        if (str_contains($hay, 'rostro') && (str_contains($hay, 'correctores') || str_contains($hay, 'corrector'))) {
            return false;
        }

        if (str_contains($hay, 'bases de maquillaje')
            || (str_contains($hay, 'rostro') && str_contains($hay, 'bases') && str_contains($hay, 'maquillaje'))) {
            return false;
        }

        return $this->containsAny($text . ' ' . $categoryName, [
            'decolorante',
            'decolorantes',
            'blondor',
            'blondeador',
            'bleach',
            'lightener',
            'rubios',
            'aclaración',
            'aclaracion',
            'polvo decolorante',
            'powder lightener',
            'blond me',
            'plex',
            'tonos de aclaracion',
            'tintes y decolorantes',
        ]);
    }

    protected function isHairColorRemoverContext(string $text, string $categoryName = ''): bool
    {
        return $this->containsAny($text . ' ' . $categoryName, [
            'effasor',
            'extracción',
            'extraccion',
            'barrido de tinte',
            'removedor de tinte',
            'remover color',
            'quita color',
            'color remover',
            'eliminador de color',
            'corrección de color',
            'correccion de color',
        ]);
    }

    protected function isHairColorContext(string $text, string $categoryName = ''): bool
    {
        $hayQuick = mb_strtolower(trim($text));
        if (preg_match('/\bacondicionador\b/u', $hayQuick) === 1
            || preg_match('/\bconditioners?\b/u', $hayQuick) === 1) {
            return false;
        }

        if (
            $this->isShampooContext($text, $categoryName)
            || $this->isConditionerContext($text, $categoryName)
            || $this->isHairOilContext($text, $categoryName)
            || $this->isHairStylingProductContext($text, $categoryName)
        ) {
            return false;
        }

        $haySalonPath = mb_strtolower(trim($text . ' ' . $categoryName));
        if ($this->isMlEsteticaSillasCategoryPath($haySalonPath)) {
            return false;
        }

        if ($this->isMlEsteticaCarrosAuxiliaresCategoryPath($haySalonPath)) {
            return false;
        }

        if ($this->isMlEsteticaLavabosOrPortableSinkContext($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLipsticksCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlMakeupCasesCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyelashExtensionsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeLashBrowOtherProductsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashAdhesiveCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlEyeShadowsCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isMlLashBrowTreatmentCategoryFromStrings($text, $categoryName)) {
            return false;
        }

        if ($this->isAfterSunBodyWashContext($text, $categoryName)) {
            return false;
        }

        if ($this->isMakeupSettingSprayContext($text, $categoryName)) {
            return false;
        }

        if ($this->isHandFootBodySkinOrSupplementContext($text, $categoryName)) {
            return false;
        }

        $haystack = mb_strtolower($text . ' ' . $categoryName);

        if (
            $this->isHairAmpouleTreatmentContext($text, $categoryName)
            && !$this->containsAny($haystack, [
                'tinte',
                'tintura',
                'pigment',
                'pigments',
                'pigmento',
                'matizador',
                'tonalizante',
                'coloración permanente',
                'coloracion permanente',
                'revelador',
                'developer',
                'oxidante',
                'peróxido',
                'peroxido',
            ])
        ) {
            return false;
        }

        if (
            $this->hasHairTreatmentKeywords($text, $categoryName)
            && !$this->containsAny($haystack, [
                'tinte',
                'tintura',
                'matizador',
                'tonalizante',
                'coloración permanente',
                'coloracion permanente',
                'revelador',
                'developer',
                'oxidante',
                'peróxido',
                'peroxido',
            ])
        ) {
            return false;
        }

        if ($this->containsAny($haystack, [
            'tinte',
            'tintura',
            'hair color',
            'coloración',
            'coloracion',
            'evolution of the color',
            'matizador',
            'tonalizante',
            'coloración permanente',
            'coloracion permanente',
            'tintes y decolorantes',
        ])) {
            return true;
        }

        // "oxidante"/"peróxido" aparecen dentro de "antioxidante" en suplementos → falso tinte.
        if (str_contains($haystack, 'antioxid')) {
            return false;
        }

        return $this->containsAny($haystack, [
            'oxidante',
            'peróxido',
            'peroxido',
            'revelador',
            'developer',
        ]);
    }

    /**
     * @param  bool  $clearWhenUnresolved  Si es false (por defecto), no se borran categorías Shopify
     *                                       ya guardadas cuando el resolver no obtiene match (evita pérdidas en bulk).
     */
    public function resolveAndSave(Product $product, bool $clearWhenUnresolved = false): ?array
    {
        $this->noteCategoryResolveProgress(sprintf(
            'BEGIN resolve id=%s ml=%s',
            (string) ($product->id ?? ''),
            (string) ($product->ml ?? '')
        ));

        try {
            $resolved = $this->resolveForProduct($product);

            if (!$resolved || empty($resolved['id']) || empty($resolved['name'])) {
                if ($clearWhenUnresolved) {
                    $product->update([
                        'shopify_category_id' => null,
                        'shopify_category_name' => null,
                        'shopify_category_source' => null,
                    ]);
                }

                $this->noteCategoryResolveProgress(sprintf(
                    'END resolve id=%s (sin match o incompleto)',
                    (string) ($product->id ?? '')
                ));

                return null;
            }

            $product->update([
                'shopify_category_id' => $resolved['id'],
                'shopify_category_name' => $resolved['name'],
                'shopify_category_source' => $resolved['source'] ?? 'taxonomy_api',
            ]);

            $this->noteCategoryResolveProgress(sprintf(
                'END resolve id=%s source=%s',
                (string) ($product->id ?? ''),
                (string) ($resolved['source'] ?? '')
            ));

            return $resolved;
        } catch (\Throwable $e) {
            $this->noteCategoryResolveProgress(sprintf(
                'END resolve id=%s ERROR=%s',
                (string) ($product->id ?? ''),
                $e->getMessage()
            ));

            Log::warning('No se pudo resolver categoría Shopify', [
                'name' => $product->name,
                'ml' => $product->ml,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}