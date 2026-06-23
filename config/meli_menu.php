<?php

/**
 * Menú automático posventa y mensajería MeLi.
 *
 * Mensajería (GET/POST, tag=post_sale, agentes por site): ver documentación regional, ej. Chile o México.
 *
 * @see https://developers.mercadolibre.cl/es_ar/mensajeria-post-venta
 * @see https://developers.mercadolibre.com.mx/es_ar/mensajeria-post-venta
 */
return [

    'catalog_pdf_url' => env('MELI_MENU_CATALOG_URL', ''),

    /**
     * URL genérica de catálogo (PDF o página web) si no hay MELI_MENU_CATALOG_URL.
     */
    'catalog_fallback_url' => env('MELI_MENU_CATALOG_FALLBACK_URL', ''),

    /**
     * URL genérica de “detalle producto” si no hay publicación en BD ni permalink.
     * Ej. página de tu tienda o Drive con fichas.
     */
    'product_detail_fallback_url' => env('MELI_MENU_PRODUCT_DETAIL_FALLBACK_URL', ''),

    /**
     * Si true, además intenta /pdfs/productos/{sku|item_id}.pdf (requiere archivos en servidor).
     */
    'use_product_pdf_path' => filter_var(env('MELI_MENU_USE_PRODUCT_PDF_PATH', false), FILTER_VALIDATE_BOOLEAN),

    'invoice_url' => env('MELI_MENU_INVOICE_URL', ''),

    'default_site_id' => env('MELI_SITE_ID', 'MLM'),

    /**
     * En true, POST /messages/packs/... usa to.user_id = ID del agente del site (tabla message_agents).
     * Recomendado alineado con ML para entrega al comprador vía agente.
     */
    'use_message_agent' => filter_var(env('MELI_MESSAGES_USE_AGENT', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * IDs de usuario "agente de mensajería" por site (tabla oficial ML).
     */
    'message_agents' => [
        'MLC' => '3020819166',
        'MCO' => '3037204123',
        'MLM' => '3037204279',
        'MLA' => '3037674934',
        'MLB' => '3037675074',
        'MLU' => '3037204685',
    ],

    /**
     * En estos sites la documentación exige to = agente al crear mensajes (capa de agentes).
     * Lista separada por comas en .env para sobreescribir.
     */
    'message_agent_required_sites' => array_values(array_filter(array_map('trim', explode(',', (string) env('MELI_MESSAGE_AGENT_REQUIRED_SITES', 'MLB,MLC'))))),

    /** Rate limit compartido escritura posventa: 500 rpm (doc ML). */
    'post_sale_write_rate_limit_rpm' => 500,

    'seller_max_message_length' => (int) env('MELI_SELLER_MESSAGE_MAX', 350),

    /**
     * Si es false (por defecto), solo acepta 1–4 como mensaje (sin palabras clave).
     */
    'menu_keyword_synonyms' => filter_var(env('MELI_MENU_KEYWORD_SYNONYMS', false), FILTER_VALIDATE_BOOLEAN),
];
