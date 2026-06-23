<?php

/**
 * AMS “Procesar” — filtro por día de colecta.
 *
 * Ventana: desde el mediodía del día anterior hasta ~10:30 del día de colecta (lo que “cae” en la mañana).
 * Corte operativo: lo que entra después de las 12:00 suele contar para el siguiente ciclo (ajustá horas abajo).
 *
 * merge_shipping_process_dates: feriados que acumulan en un día hábil.
 *
 * “Procesar” usa `meli_orders.shipping_raw` (GET /shipments/{id}). Al abrir la pantalla se refrescan esos envíos
 * para la ventana actual (no alcanza con `meli:sync-orders --today`: solo reindexa por fecha de creación de la orden).
 */
return [

    'strict_day_filter' => env('AMS_COLECTA_STRICT_DAY_FILTER', true),

    /** Antes de listar “Procesar”, consultar MeLi por cada shipping_id del lote (evita ver 32 “listos” si ya van en camino). */
    'refresh_shipments_on_procesar' => filter_var(env('AMS_COLECTA_REFRESH_SHIPMENTS_ON_PROCESAR', true), FILTER_VALIDATE_BOOL),

    /** Tope de llamadas a /shipments por carga de página (evita timeouts). */
    'refresh_shipments_max_ids' => (int) env('AMS_COLECTA_REFRESH_SHIPMENTS_MAX', 300),

    /** Pausa entre llamadas a la API (microsegundos). */
    'refresh_shipments_delay_micros' => (int) env('AMS_COLECTA_REFRESH_SHIPMENTS_DELAY_US', 80000),

    /**
     * Ventana horaria en zona AMS_COLECTA_TIMEZONE (America/Mexico_City por defecto).
     * Inicio = (día anterior al elegido) a esta hora; fin = día elegido a esta hora.
     */
    'window' => [
        'start_hour' => (int) env('AMS_COLECTA_WINDOW_START_HOUR', 12),
        'start_minute' => (int) env('AMS_COLECTA_WINDOW_START_MINUTE', 0),
        'end_hour' => (int) env('AMS_COLECTA_WINDOW_END_HOUR', 10),
        'end_minute' => (int) env('AMS_COLECTA_WINDOW_END_MINUTE', 32),
    ],

    /**
     * Subestados que SÍ van a “Procesar” (etiqueta pendiente o impresa y aún en tienda).
     * MeLi suele pasar a picked_up / in_transit cuando ya salió — eso se excluye por bloqueo explícito.
     */
    /**
     * Solo estos subestados en “Procesar” (por defecto solo etiqueta pendiente = listo para empacar).
     * Si también querés los que ya imprimieron etiqueta: AMS_COLECTA_PROCESAR_SUBSTATUSES=ready_to_print,printed
     */
    'procesar_allowed_substatuses' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'AMS_COLECTA_PROCESAR_SUBSTATUSES',
        'ready_to_print'
    ))))),

    /**
     * Subestados que nunca deben aparecer (ya salieron / van en camino según MeLi).
     */
    'procesar_blocked_substatuses' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'AMS_COLECTA_PROCESAR_BLOCKED_SUBSTATUSES',
        'picked_up,shipped,in_transit,dropped_off,delivered,out_for_delivery,in_hub,at_destination'
    ))))),

    /**
     * @var array<string, list<string>> fecha_procesamiento => lista de fechas Y-m-d
     */
    'merge_shipping_process_dates' => [
        // Semana Santa 2026: lote del lunes 6 abr incluye ventas/envíos del jue 2 al lun 6 (ajustá si tu calendario operativo difiere).
        '2026-04-06' => ['2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05', '2026-04-06'],
    ],

];
