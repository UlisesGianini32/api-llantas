<?php

namespace App\Http\Controllers;

use App\Models\MeliOrder;
use App\Models\User;
use App\Services\MeliOrderSyncService;
use App\Support\AmsMarcaPedidos;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AmsPedidosController extends Controller
{
    public function index(Request $request): Response
    {
        $fechaSeleccionada = $this->resolveFecha($request, false);

        $rows = $this->basePedidosQuery()
            ->whereDate('o.created_at', $fechaSeleccionada)
            ->get();

        $grouped = $this->computePedidosGrouped($rows);

        return Inertia::render('Ams/PedidosIndex', [
            'pedidos' => $this->pedidosToInertiaArray($grouped['pedidos']),
            'fechaSeleccionada' => $fechaSeleccionada,
            'totalPedidos' => $grouped['totalPedidos'],
            'totalPiezas' => $grouped['totalPiezas'],
            'totalVendido' => $grouped['totalVendido'],
            'tituloPagina' => 'AMS - Pedidos del día',
            'subtitulo' => 'Mostrando pedidos vendidos el día:',
            'dateFilterUrl' => route('ams.pedidos.index'),
        ]);
    }

    public function procesar(Request $request, MeliOrderSyncService $meliSync): Response
    {
        $fechaSeleccionada = $this->resolveFecha($request, false);
        $alcance = $this->resolveAlcanceProcesar($request);

        $this->maybeRefreshProcesarShipments(
            $request,
            $fechaSeleccionada,
            $alcance,
            $meliSync
        );

        if ($alcance === 'procesados') {
            $query = $this->processedMainOrdersQuery(
                $fechaSeleccionada
            );
        } else {
            $query = $this->procesarCandidateQuery(
                $fechaSeleccionada,
                $alcance
            );

            $this->applyProcesarSoloListosEnTiendaSinEnviar(
                $query
            );
        }

        $rows = $query->get();

        if ($alcance === 'procesados') {
            $subtitulo =
                'Pedidos ya procesados, impresos o cuyo envío ya avanzó.';
        } elseif ($alcance === 'colecta') {
            $subtitulo =
                'Lote Flex/Colecta: solo envíos aún en tienda (listo para enviar / etiqueta). Ventana y feriados: config/ams_colecta.php. Referencia:';
        } else {
            $subtitulo =
                'Etiqueta lista: pagado + listo para enviar + etiqueta por imprimir. Incluye filas sin ítems en BD y estados leídos del JSON si las columnas van atrasadas.';
        }

        return $this->renderPedidosProcesarInertia(
            $rows,
            $fechaSeleccionada,
            'AMS - Pedidos por procesar',
            $subtitulo,
            route('ams.pedidos.procesar'),
            $this->resolveOrdenProcesar($request),
            $alcance
        );
    }

    public function procesarManana(Request $request, MeliOrderSyncService $meliSync): Response
    {
        $fechaSeleccionada = now()->addDay()->toDateString();

        $this->maybeRefreshProcesarShipments($request, $fechaSeleccionada, 'colecta', $meliSync);

        $query = $this->procesarCandidateQuery($fechaSeleccionada, 'colecta');

        $this->applyProcesarSoloListosEnTiendaSinEnviar($query);

        $rows = $query->get();

        return $this->renderPedidosProcesarInertia(
            $rows,
            $fechaSeleccionada,
            'AMS - Pedidos para mañana',
            'Mostrando pedidos para mañana (misma ventana colecta; fecha = día de colecta / envío):',
            route('ams.pedidos.manana'),
            $this->resolveOrdenProcesar($request),
            'colecta'
        );
    }

    /**
     * colecta    = Flex/Colecta + ventana de fecha.
     * ml_listado = etiqueta lista para imprimir.
     * procesados = pedidos cuya etiqueta ya fue impresa o
     *              cuyo envío ya avanzó.
     *
     * @return 'colecta'|'ml_listado'|'procesados'
     */
    protected function resolveAlcanceProcesar(Request $request): string
    {
        $v = strtolower(
            trim(
                (string) $request->input(
                    'alcance',
                    'ml_listado'
                )
            )
        );

        return in_array(
            $v,
            [
                'colecta',
                'ml_listado',
                'procesados',
            ],
            true
        )
            ? $v
            : 'ml_listado';
    }

    /**
     * Pedidos principales cuyo envío ya avanzó después
     * de generar o imprimir la etiqueta.
     */
    protected function processedMainOrdersQuery(
        string $fechaSeleccionada
    ) {
        $effectiveStatus =
            $this->sqlEffectiveShippingStatus();

        $effectiveSubstatus =
            $this->sqlEffectiveShippingSubstatus();

        return $this->basePedidosQuery(false)
            ->whereDate(
                'o.created_at',
                $fechaSeleccionada
            )
            ->where(function ($query) {
                $query
                    ->whereRaw(
                        "LOWER(COALESCE(o.status, '')) = 'paid'"
                    )
                    ->orWhereRaw(
                        "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.status')), ''))) = 'paid'"
                    );
            })
            ->where(function ($query) use (
                $effectiveStatus,
                $effectiveSubstatus
            ) {
                $query
                    ->where(function ($printed) use (
                        $effectiveStatus,
                        $effectiveSubstatus
                    ) {
                        $printed
                            ->whereRaw(
                                "({$effectiveStatus}) = 'ready_to_ship'"
                            )
                            ->whereRaw(
                                "({$effectiveSubstatus}) IN (
                                    'printed',
                                    'invoice_pending',
                                    'waiting_for_carrier_authorization',
                                    'packed',
                                    'in_packing_list'
                                )"
                            );
                    })
                    ->orWhereRaw(
                        "({$effectiveStatus}) IN (
                            'handling',
                            'shipped',
                            'in_transit',
                            'delivered'
                        )"
                    )
                    ->orWhereRaw(
                        "({$effectiveSubstatus}) IN (
                            'printed',
                            'packed',
                            'in_packing_list',
                            'dropped_off',
                            'picked_up',
                            'in_hub',
                            'out_for_delivery',
                            'delivered'
                        )"
                    );
            });
    }


    /**
     * @return 'fecha'|'marca'
     */
    protected function resolveOrdenProcesar(Request $request): string
    {
        $orden = strtolower(trim((string) $request->input('orden', 'fecha')));

        return $orden === 'marca' ? 'marca' : 'fecha';
    }

    /**
     * Misma base que “Procesar” pero sin filtrar por listo-en-tienda (sirve para armar la lista de shipping_id a refrescar).
     *
     * @param  'colecta'|'ml_listado'  $alcance
     */
    protected function procesarCandidateQuery(string $fechaSeleccionada, string $alcance)
    {
        $query = $this->basePedidosQuery($alcance === 'colecta');

        if ($alcance === 'ml_listado') {
            $query->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(o.status, '')) = 'paid'")
                    ->orWhereRaw(
                        "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.status')), ''))) = 'paid'"
                    );
            });
            $this->applyMlListadoShippingReadyToPrint($query);
        } else {
            $query->whereRaw("LOWER(COALESCE(o.status, '')) = 'paid'");
        }

        if ($alcance === 'colecta') {
            $query->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(o.shipping_mode, '')) = 'flex'")
                    ->orWhereRaw("LOWER(COALESCE(o.shipping_type, '')) LIKE '%flex%'")
                    ->orWhereRaw("LOWER(COALESCE(o.shipping_logistic_type, '')) LIKE '%flex%'")

                    ->orWhereRaw("LOWER(COALESCE(o.shipping_type, '')) IN ('drop_off', 'xd_drop_off', 'self_service')")
                    ->orWhereRaw("LOWER(COALESCE(o.shipping_logistic_type, '')) IN ('cross_docking', 'drop_off', 'xd_drop_off', 'self_service')")
                    ->orWhereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.shipping.logistic_type')), '')) IN ('cross_docking', 'drop_off', 'xd_drop_off', 'self_service')")
                    ->orWhereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.shipping.shipping_option.name')), '')) LIKE '%colecta%'")
                    ->orWhereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.shipping.shipping_option.shipping_method_name')), '')) LIKE '%colecta%'");
            });
            $this->applyColectaProcesarDayFilter($query, $fechaSeleccionada);
        }

        return $query;
    }

    /**
     * Consulta GET /shipments/{id} por cada envío del lote para que el filtro “solo listos” use datos actuales de MeLi.
     *
     * Nota importante (ml_listado):
     * Antes el universo de refresh era "todos los pagados" ordenados por created_at DESC,
     * cap a 150. Eso refrescaba los 150 más recientes y dejaba las órdenes viejas con
     * shipping_substatus 'ready_to_print' colgadas en el listado aunque en ML ya estuvieran
     * 'printed' / 'picked_up' / 'shipped'. Resultado: el conteo en el panel quedaba MAYOR
     * que el filtro de ML "Etiqueta lista para imprimir".
     *
     * Ahora, en ml_listado, el universo de refresh es exactamente lo que el listado piensa
     * mostrar (effective_substatus = 'ready_to_print'). Esos son los únicos que pueden
     * estar stale; refrescar otra cosa es desperdicio. Después de refrescar, el filtro
     * final saca los que ML ya marcó como avanzados y el conteo coincide.
     */
    protected function maybeRefreshProcesarShipments(
        Request $request,
        string $fechaSeleccionada,
        string $alcance,
        MeliOrderSyncService $meliSync
    ): void {
        if ($request->boolean('sin_refrescar')) {
            return;
        }

        if (!config('ams_colecta.refresh_shipments_on_procesar', true)) {
            return;
        }

        $user = User::query()->whereNotNull('access_token')->first();
        if (!$user) {
            return;
        }

        if ($alcance === 'ml_listado') {
            $effSt = $this->sqlEffectiveShippingStatus();
            $effSub = $this->sqlEffectiveShippingSubstatus();

            $candidate = $this->basePedidosQuery(false)
                ->where(function ($q) {
                    $q->whereRaw(
                        "LOWER(COALESCE(o.status, '')) = 'paid'"
                    )
                        ->orWhereRaw(
                            "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.status')), ''))) = 'paid'"
                        );
                })
                ->whereRaw(
                    "({$effSt}) = 'ready_to_ship'"
                )
                ->whereRaw(
                    "({$effSub}) = 'ready_to_print'"
                );
        } elseif ($alcance === 'procesados') {
            /*
             * Refrescamos todos los envíos pagados de la fecha.
             *
             * Esto permite detectar inmediatamente que una
             * etiqueta pasó de ready_to_print a printed.
             */
            $candidate = $this->basePedidosQuery(false)
                ->whereDate(
                    'o.created_at',
                    $fechaSeleccionada
                )
                ->where(function ($q) {
                    $q->whereRaw(
                        "LOWER(COALESCE(o.status, '')) = 'paid'"
                    )
                        ->orWhereRaw(
                            "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.status')), ''))) = 'paid'"
                        );
                });
        } else {
            $candidate = $this->procesarCandidateQuery(
                $fechaSeleccionada,
                $alcance
            );
        }

        $ids = $candidate
            ->whereNotNull('o.shipping_id')
            ->where('o.shipping_id', '!=', '')
            ->select('o.shipping_id')
            ->distinct()
            ->pluck('o.shipping_id')
            ->unique()
            ->values()
            ->all();

        $max = max(1, (int) config('ams_colecta.refresh_shipments_max_ids', 150));
        if (count($ids) > $max) {
            $ids = array_slice($ids, 0, $max);
        }

        if ($ids !== []) {
            $meliSync->refreshShipmentsByShippingIds($user, $ids);
        }
    }

    /**
     * Varias órdenes tienen shipping_status/substatus desactualizado en columnas; el JSON `raw` suele ir más al día.
     */
    protected function applyMlListadoShippingReadyToPrint($query): void
    {
        $effSt = $this->sqlEffectiveShippingStatus();
        $effSub = $this->sqlEffectiveShippingSubstatus();

        $query->whereRaw("({$effSt}) = 'ready_to_ship'")
            ->whereRaw("({$effSub}) = 'ready_to_print'");
    }

    /**
     * @param  bool  $innerJoinOrderItems  false = LEFT JOIN (modo ML listado: no pierde pedidos sin meli_order_items).
     */
    protected function basePedidosQuery(bool $innerJoinOrderItems = true, ?int $meliAccountId = 1)
    {
        $productsBySku = DB::table('products as p1')
            ->select(
                'p1.sku',
                DB::raw('MAX(p1.id) as id'),
                DB::raw('MAX(p1.name) as name'),
                DB::raw('MAX(p1.thumbnail) as thumbnail'),
                DB::raw('MAX(p1.price) as price')
            )
            ->whereNotNull('p1.sku')
            ->where('p1.sku', '!=', '')
            ->groupBy('p1.sku');

        $productsByMl = DB::table('products as p2')
            ->select(
                'p2.ml',
                DB::raw('MAX(p2.id) as id'),
                DB::raw('MAX(p2.name) as name'),
                DB::raw('MAX(p2.thumbnail) as thumbnail'),
                DB::raw('MAX(p2.price) as price')
            )
            ->whereNotNull('p2.ml')
            ->where('p2.ml', '!=', '')
            ->groupBy('p2.ml');

        $orders = DB::table('meli_orders as o');

        // Este controlador corresponde a la cuenta principal.
        // El filtro evita que sus listados y contadores mezclen pedidos
        // de otras cuentas de Mercado Libre almacenadas en meli_orders.
        if ($meliAccountId !== null) {
            $orders->where('o.meli_account_id', $meliAccountId);
        }

        if ($innerJoinOrderItems) {
            $orders->join('meli_order_items as i', 'i.meli_order_id', '=', 'o.id');
        } else {
            $orders->leftJoin('meli_order_items as i', 'i.meli_order_id', '=', 'o.id');
        }

        return $orders

            ->leftJoinSub($productsBySku, 'ps', function ($join) {
                $join->on('ps.sku', '=', 'i.sku');
            })

            ->leftJoinSub($productsByMl, 'pm', function ($join) {
                $join->on('pm.ml', '=', 'i.item_id');
            })

            ->where(function ($q) {
                $q->whereRaw("COALESCE(LOWER(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.fulfilled'))), 'false') <> 'true'")
                  ->whereRaw("COALESCE(LOWER(o.shipping_mode), '') NOT IN ('fulfillment', 'full')")
                  ->whereRaw("COALESCE(LOWER(o.shipping_logistic_type), '') NOT IN ('fulfillment', 'full')")
                  ->whereRaw("COALESCE(LOWER(o.shipping_type), '') NOT LIKE '%full%'");
            })

            ->select([
                'o.id as id_local',
                'o.order_id as order_id',
                'o.display_id as ml_display_id',
                'o.status as order_status',
                'o.created_at as fecha_pedido',
                'o.shipping_process_date',
                'o.shipping_id',
                'o.shipping_status',
                'o.shipping_substatus',
                'o.shipping_mode',
                'o.shipping_type',
                'o.shipping_logistic_type',
                'o.shipping_raw as order_shipping_raw',
                'o.raw as raw_order',
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.pack_id')) as raw_pack_id"),

                'i.id as item_row_id',
                'i.item_id',
                'i.sku',
                'i.quantity',
                'i.unit_price',
                DB::raw('COALESCE(i.title, "") as item_title'),
                DB::raw('COALESCE(i.variation_text, "") as item_variation_text'),

                'ps.name as sku_product_name',
                'ps.thumbnail as sku_product_thumbnail',
                'ps.price as sku_product_price',

                'pm.name as ml_product_name',
                'pm.thumbnail as ml_product_thumbnail',
                'pm.price as ml_product_price',
            ])
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.order_id')
            ->orderByDesc('i.id');
    }

    protected function resolveFecha(Request $request, bool $defaultTomorrow = false): string
    {
        $fecha = $request->input('fecha');

        try {
            if ($fecha) {
                return Carbon::parse($fecha)->toDateString();
            }

            return $defaultTomorrow
                ? now()->addDay()->toDateString()
                : now()->toDateString();
        } catch (\Throwable $e) {
            return $defaultTomorrow
                ? now()->addDay()->toDateString()
                : now()->toDateString();
        }
    }

    /**
     * Zona horaria para ventanas de colecta MeLi (México). Ajustá con AMS_COLECTA_TIMEZONE en .env si operás otro estado.
     */
    protected function colectaBusinessTimezone(): string
    {
        return env('AMS_COLECTA_TIMEZONE', 'America/Mexico_City');
    }

    /**
     * 1) shipping_raw = JSON del GET /shipments/{id} (sync reciente); 2) raw.shipping (suele ir sin status); 3) columnas.
     */
    protected function sqlEffectiveShippingStatus(): string
    {
        return "LOWER(TRIM(COALESCE(
            NULLIF(TRIM(COALESCE(o.shipping_status, '')), ''),
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.shipping_raw, '$.status')), '')), ''),
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.shipping.status')), '')), '')
        )))";
    }

    protected function sqlEffectiveShippingSubstatus(): string
    {
        return "LOWER(TRIM(COALESCE(
            NULLIF(TRIM(COALESCE(o.shipping_substatus, '')), ''),
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.shipping_raw, '$.substatus')), '')), ''),
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.shipping.substatus')), '')), '')
        )))";
    }

    /**
     * Misma prioridad que sqlEffectiveShipping*: shipping_raw → raw.shipping → columnas (primer valor no vacío).
     *
     * @return array{status: string, substatus: string, label: string}
     */
    protected function resolveEffectiveMeliShippingFromRow(object $row): array
    {
        $shippingRaw = null;
        $payload = $row->order_shipping_raw ?? null;
        if ($payload !== null && $payload !== '') {
            if (is_string($payload)) {
                try {
                    $shippingRaw = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $shippingRaw = null;
                }
            } elseif (is_array($payload)) {
                $shippingRaw = $payload;
            }
        }

        $raw = [];
        if (!empty($row->raw_order)) {
            try {
                $raw = is_array($row->raw_order)
                    ? $row->raw_order
                    : json_decode((string) $row->raw_order, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $raw = [];
            }
        }
        $shipOrder = is_array($raw['shipping'] ?? null) ? $raw['shipping'] : [];

        $st = '';
        foreach ([data_get($shippingRaw, 'status'), data_get($shipOrder, 'status'), $row->shipping_status ?? ''] as $cand) {
            $t = strtolower(trim((string) $cand));
            if ($t !== '') {
                $st = $t;
                break;
            }
        }

        $sub = '';
        foreach ([data_get($shippingRaw, 'substatus'), data_get($shipOrder, 'substatus'), $row->shipping_substatus ?? ''] as $cand) {
            $t = strtolower(trim((string) $cand));
            if ($t !== '') {
                $sub = $t;
                break;
            }
        }

        return [
            'status' => $st,
            'substatus' => $sub,
            'label' => $this->labelEnvioAmsParaPantalla($st, $sub),
        ];
    }

    protected function labelEnvioAmsParaPantalla(string $status, string $substatus): string
    {
        if (in_array($status, ['shipped', 'in_transit'], true)) {
            return 'En camino';
        }
        if ($status === 'delivered' || $substatus === 'delivered') {
            return 'Entregado';
        }
        if ($status === 'cancelled' || $substatus === 'cancelled') {
            return 'Cancelado';
        }
        if ($status === 'ready_to_ship') {
            if ($substatus === 'ready_to_print') {
                return 'Listo para imprimir / empacar';
            }
            if ($substatus === 'printed') {
                return 'Etiqueta impresa (llevar a colecta)';
            }
            if ($substatus !== '') {
                return 'Listo para enviar · '.$substatus;
            }

            return 'Listo para enviar';
        }
        if ($status === 'pending' || $status === '') {
            return $substatus !== '' ? 'Envío: '.$substatus : 'Estado de envío pendiente';
        }

        return $status !== '' ? $status.($substatus !== '' ? ' · '.$substatus : '') : 'Sin estado de envío';
    }

    /**
     * Excluye pedidos ya enviados / en tránsito: solo ready_to_ship + subestados de etiqueta en tienda.
     */
    protected function applyProcesarSoloListosEnTiendaSinEnviar($query): void
    {
        $effSt = $this->sqlEffectiveShippingStatus();
        $effSub = $this->sqlEffectiveShippingSubstatus();

        $allowed = config('ams_colecta.procesar_allowed_substatuses', ['ready_to_print']);
        $allowed = array_values(array_filter(array_map(static fn ($s) => strtolower(trim((string) $s)), is_array($allowed) ? $allowed : [])));
        if ($allowed === []) {
            $allowed = ['ready_to_print'];
        }

        $inList = implode(',', array_map(static function (string $s): string {
            return "'".str_replace("'", "''", $s)."'";
        }, $allowed));

        $query->whereRaw("({$effSt}) = 'ready_to_ship'")
            ->whereRaw("({$effSub}) IN ({$inList})")
            ->whereRaw("({$effSt}) NOT IN ('shipped', 'in_transit', 'delivered', 'cancelled')");

        $blockedSubs = config('ams_colecta.procesar_blocked_substatuses', []);
        $blockedSubs = array_values(array_filter(array_map(static fn ($s) => strtolower(trim((string) $s)), is_array($blockedSubs) ? $blockedSubs : [])));
        if ($blockedSubs !== []) {
            $blockedIn = implode(',', array_map(static function (string $s): string {
                return "'".str_replace("'", "''", $s)."'";
            }, $blockedSubs));
            $query->whereRaw("({$effSub}) NOT IN ({$blockedIn})");
        }
    }

    /**
     * Día elegido = foco de colecta.
     *
     * Modo estricto (config ams_colecta.strict_day_filter): evita meter dos días enteros de created_at.
     * Ventana horaria: config `ams_colecta.window` (por defecto desde 12:00 del día anterior hasta 10:32 del día elegido).
     *
     * Modo legacy: comportamiento anterior (OR amplio).
     */
    protected function applyColectaProcesarDayFilter($query, string $fechaColectaYmd): void
    {
        $tz = $this->colectaBusinessTimezone();
        $F = Carbon::createFromFormat('Y-m-d', $fechaColectaYmd, $tz)->startOfDay();

        $w = config('ams_colecta.window', []);
        $sh = (int) ($w['start_hour'] ?? 12);
        $sm = (int) ($w['start_minute'] ?? 0);
        $eh = (int) ($w['end_hour'] ?? 10);
        $em = (int) ($w['end_minute'] ?? 32);

        $windowStart = $F->copy()->subDay()->setTime(max(0, min(23, $sh)), max(0, min(59, $sm)), 0);
        $windowEnd = $F->copy()->setTime(max(0, min(23, $eh)), max(0, min(59, $em)), 59);

        $startUtc = $windowStart->clone()->timezone('UTC');
        $endUtc = $windowEnd->clone()->timezone('UTC');
        $dateF = $F->toDateString();
        $datePrev = $F->copy()->subDay()->toDateString();

        if (!config('ams_colecta.strict_day_filter', true)) {
            $query->where(function ($q) use ($fechaColectaYmd, $dateF, $datePrev, $startUtc, $endUtc) {
                $q->whereDate('o.created_at', '=', $fechaColectaYmd)
                    ->orWhereDate('o.created_at', '=', $datePrev)
                    ->orWhereDate('o.shipping_process_date', '=', $dateF)
                    ->orWhereDate('o.shipping_process_date', '=', $datePrev)
                    ->orWhere(function ($q2) use ($startUtc, $endUtc) {
                        $q2->where('o.created_at', '>', $startUtc)
                            ->where('o.created_at', '<=', $endUtc);
                    });
            });

            return;
        }

        $merge = config('ams_colecta.merge_shipping_process_dates.' . $fechaColectaYmd);
        $candidateDates = is_array($merge) && $merge !== []
            ? array_values(array_unique(array_map(static fn ($d) => (string) $d, $merge)))
            : [$dateF];

        $query->where(function ($q) use ($candidateDates, $startUtc, $endUtc) {
            $q->where(function ($qShip) use ($candidateDates) {
                foreach ($candidateDates as $d) {
                    $qShip->orWhereDate('o.shipping_process_date', '=', $d);
                }
            })->orWhere(function ($qCre) use ($candidateDates) {
                foreach ($candidateDates as $d) {
                    $qCre->orWhereDate('o.created_at', '=', $d);
                }
            })->orWhere(function ($q2) use ($startUtc, $endUtc) {
                $q2->where('o.created_at', '>', $startUtc)
                    ->where('o.created_at', '<=', $endUtc);
            });
        });
    }

    protected function renderPedidosProcesarInertia(
        $rows,
        string $fechaSeleccionada,
        string $tituloPagina,
        string $subtitulo,
        string $formAction,
        string $orden = 'fecha',
        string $alcance = 'colecta'
    ): Response {
        // En "procesar" conviene unir por pack_id para que un mismo pedido con varios ítems
        // se vea junto en una sola tarjeta (especialmente en tablet al empacar/imprimir).
        $grouped = $this->computePedidosGrouped($rows, true);
        $pedidos = $grouped['pedidos'];
        if ($orden === 'marca') {
            $pedidos = $this->sortPedidosPorMarca($pedidos);
        }

        return Inertia::render('Ams/PedidosProcesar', [
            'pedidos' => $this->pedidosToInertiaArray($pedidos, $orden === 'marca'),
            'fechaSeleccionada' => $fechaSeleccionada,
            'totalPedidos' => $grouped['totalPedidos'],
            'totalPiezas' => $grouped['totalPiezas'],
            'tituloPagina' => $tituloPagina,
            'subtitulo' => $subtitulo,
            'formAction' => $formAction,
            'orden' => $orden,
            'alcance' => $alcance,
        ]);
    }

    /**
     * Orden global por marca (menor índice entre ítems del pedido); dentro de la misma marca, SKU alfanumérico.
     * Los ítems dentro del pedido quedan ordenados por marca y luego por SKU (alfanumérico natural).
     */
    protected function sortPedidosPorMarca(Collection $pedidosAgrupados): Collection
    {
        return $pedidosAgrupados
            ->map(function ($p) {
                $items = collect($p->items)
                    ->map(function ($i) {
                        [$idx, $label] = AmsMarcaPedidos::resolve(
                            (string) ($i->titulo ?? ''),
                            (string) ($i->sku ?? '')
                        );

                        return (object) array_merge((array) $i, [
                            'ams_marca_idx' => $idx,
                            'ams_marca_label' => $label,
                        ]);
                    })
                    ->sort(function ($a, $b) {
                        $cmpMarca = ((int) ($a->ams_marca_idx ?? AmsMarcaPedidos::UNKNOWN_INDEX))
                            <=> ((int) ($b->ams_marca_idx ?? AmsMarcaPedidos::UNKNOWN_INDEX));
                        if ($cmpMarca !== 0) {
                            return $cmpMarca;
                        }

                        $cmpSku = strnatcasecmp(
                            $this->skuComparableValue((string) ($a->sku ?? '')),
                            $this->skuComparableValue((string) ($b->sku ?? ''))
                        );
                        if ($cmpSku !== 0) {
                            return $cmpSku;
                        }

                        return strnatcasecmp(
                            mb_strtolower((string) ($a->titulo ?? '')),
                            mb_strtolower((string) ($b->titulo ?? ''))
                        );
                    })
                    ->values();

                $minIdx = (int) $items->min('ams_marca_idx');
                $primaryLabel = AmsMarcaPedidos::UNKNOWN_LABEL;
                foreach ($items as $it) {
                    if ((int) $it->ams_marca_idx === $minIdx) {
                        $primaryLabel = (string) $it->ams_marca_label;
                        break;
                    }
                }

                return (object) [
                    'group_key' => $p->group_key,
                    'pack_id' => $p->pack_id ?? null,
                    'order_id' => $p->order_id,
                    'orders' => $p->orders ?? collect(),
                    'display_id' => $p->display_id,
                    'ams_tipo' => $p->ams_tipo ?? 'OTRO',
                    'fecha_pedido' => $p->fecha_pedido,
                    'fecha_pedido_formateada' => $p->fecha_pedido_formateada,
                    'ml_envio_status' => (string) ($p->ml_envio_status ?? ''),
                    'ml_envio_substatus' => (string) ($p->ml_envio_substatus ?? ''),
                    'ml_envio_label' => (string) ($p->ml_envio_label ?? ''),
                    'items' => $items,
                    'total_piezas' => $p->total_piezas,
                    'total_pedido' => $p->total_pedido,
                    'ams_marca_sort' => $minIdx,
                    'ams_marca_label' => $primaryLabel,
                    'ams_sku_sort' => $this->pedidoPrimarySkuSort($items),
                ];
            })
            ->sort(function ($a, $b) {
                $cmp = ((int) $a->ams_marca_sort) <=> ((int) $b->ams_marca_sort);
                if ($cmp !== 0) {
                    return $cmp;
                }

                $cmpSku = strnatcasecmp(
                    (string) ($a->ams_sku_sort ?? ''),
                    (string) ($b->ams_sku_sort ?? '')
                );
                if ($cmpSku !== 0) {
                    return $cmpSku;
                }

                $ta = $a->fecha_pedido ? strtotime((string) $a->fecha_pedido) : 0;
                $tb = $b->fecha_pedido ? strtotime((string) $b->fecha_pedido) : 0;
                if ($tb !== $ta) {
                    return $tb <=> $ta;
                }

                return strnatcasecmp((string) ($a->display_id ?? ''), (string) ($b->display_id ?? ''));
            })
            ->values();
    }

    protected function skuComparableValue(string $sku): string
    {
        $value = mb_strtolower(trim($sku));

        return $value !== '' ? $value : 'zzzzzzzzzz';
    }

    protected function pedidoPrimarySkuSort(Collection $items): string
    {
        foreach ($items as $item) {
            $sku = $this->skuComparableValue((string) ($item->sku ?? ''));
            if ($sku !== 'zzzzzzzzzz') {
                return $sku;
            }
        }

        return 'zzzzzzzzzz';
    }

    /**
     * Evita agrupar mal por pack_id inválido o la cadena "null" desde JSON/SQL.
     */
    protected function normalizeMeliPackId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '' || strcasecmp($s, 'null') === 0) {
                return null;
            }

            return $s;
        }
        if (is_numeric($value)) {
            $s = (string) $value;
            if ($s === '0') {
                return null;
            }

            return $s;
        }

        return null;
    }

    protected function isMeliOrderCancelledForPresentation(array $raw, ?string $storedStatus = null): bool
    {
        $tags = array_map(fn (mixed $tag): string => strtolower((string) $tag), (array) ($raw['tags'] ?? []));
        $feedback = is_array($raw['feedback'] ?? null) ? $raw['feedback'] : [];

        return (array_key_exists('cancel_detail', $raw) && $raw['cancel_detail'] !== null)
            || (array_key_exists('sale', $feedback) && $feedback['sale'] !== null)
            || in_array('unfulfilled', $tags, true)
            || in_array(strtolower((string) ($raw['status'] ?? $storedStatus ?? '')), ['cancelled', 'canceled'], true);
    }

    /**
     * @param  bool  $mergeByPack  Si false, un renglón por orden ML (p. ej. pantalla "por procesar").
     * @return array{pedidos: \Illuminate\Support\Collection, totalPedidos: int, totalPiezas: int, totalVendido: float}
     */
    protected function computePedidosGrouped($rows, bool $mergeByPack = true): array
    {
        $items = collect($rows)->map(function ($row) use ($mergeByPack) {
            $raw = [];

            if (!empty($row->raw_order)) {
                try {
                    $raw = is_array($row->raw_order)
                        ? $row->raw_order
                        : json_decode($row->raw_order, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $raw = [];
                }
            }

            $packIdRaw = $row->raw_pack_id ?? null;
            if ($packIdRaw === null || $packIdRaw === '') {
                $packIdRaw = $raw['pack_id'] ?? null;
            }
            $packId = $this->normalizeMeliPackId($packIdRaw);

            $orderIdStr = $row->order_id !== null && (string) $row->order_id !== ''
                ? (string) $row->order_id
                : '';
            $orderGroupPart = $orderIdStr !== '' ? $orderIdStr : ('id_' . $row->id_local);

            $groupKey = ($mergeByPack && $packId !== null)
                ? ('pack_' . $packId)
                : ('order_' . $orderGroupPart);

            $rawItem = null;
            $rawItems = $raw['order_items'] ?? [];

            foreach ($rawItems as $candidate) {
                $candidateItem = $candidate['item'] ?? [];
                $candidateItemId = (string) ($candidateItem['id'] ?? '');
                $candidateSku = (string) ($candidateItem['seller_sku'] ?? '');

                if (
                    $candidateItemId === (string) $row->item_id ||
                    ($candidateSku !== '' && $candidateSku === (string) $row->sku)
                ) {
                    $rawItem = $candidate;
                    break;
                }
            }

            $rawItemInfo = $rawItem['item'] ?? [];
            $variationAttributes = $rawItemInfo['variation_attributes'] ?? [];

            $tituloBase = $row->item_title
                ?: ($rawItemInfo['title']
                ?? $row->sku_product_name
                ?? $row->ml_product_name
                ?? ('Producto SKU: ' . ($row->sku ?: $row->item_id)));

            $variationText = trim((string) ($row->item_variation_text ?? ''));
            $tono = $this->extractVariationLabel($variationAttributes);
            $presentacion = $this->extractPresentationLabel($variationAttributes);

            if ($variationText !== '' && !$this->titleAlreadyContainsText($tituloBase, $variationText)) {
                $tituloBase .= ' - ' . $variationText;
            } else {
                if ($tono && !$this->titleAlreadyContainsText($tituloBase, $tono)) {
                    $tituloBase .= ' - ' . $tono;
                }

                if ($presentacion && !$this->titleAlreadyContainsText($tituloBase, $presentacion)) {
                    $tituloBase .= ' - ' . $presentacion;
                }
            }

            $cantidad = (int) ($row->quantity ?? 0);
            $precioUnitario = $row->unit_price !== null
                ? (float) $row->unit_price
                : (float) ($row->sku_product_price ?? $row->ml_product_price ?? 0);

            $imagen = $row->sku_product_thumbnail
                ?: $row->ml_product_thumbnail
                ?: ($rawItemInfo['thumbnail'] ?? null);

            $amsTipo = $this->classifyAmsTipo(
                $row->shipping_mode,
                $row->shipping_type,
                $row->shipping_logistic_type,
                $raw
            );

            $effEnvio = $this->resolveEffectiveMeliShippingFromRow($row);

            return (object) [
                'group_key' => $groupKey,
                'pack_id' => $packId,
                'id_local' => (int) $row->id_local,
                'item_row_id' => (int) ($row->item_row_id ?? 0),
                'ml_display_id' => isset($row->ml_display_id) && $row->ml_display_id !== null && (string) $row->ml_display_id !== ''
                    ? (string) $row->ml_display_id
                    : null,
                'order_id' => $orderIdStr,
                'item_id' => (string) $row->item_id,
                'sku' => (string) ($row->sku ?? ''),
                'titulo' => $tituloBase,
                'imagen' => $imagen,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'total_linea' => $cantidad * $precioUnitario,
                'fecha_pedido' => $row->fecha_pedido,
                'shipping_id' => isset($row->shipping_id) ? (string) $row->shipping_id : '',
                'fecha_pedido_formateada' => $row->fecha_pedido
                    ? Carbon::parse($row->fecha_pedido)->format('d/m/Y H:i')
                    : null,
                'ams_tipo' => $amsTipo,
                'order_status' => (string) ($row->order_status ?? ''),
                'order_cancelled' => $this->isMeliOrderCancelledForPresentation($raw, $row->order_status ?? null),
                'order_total_amount' => is_numeric($raw['total_amount'] ?? null) ? (float) $raw['total_amount'] : null,
                'order_currency_id' => filled($raw['currency_id'] ?? null) ? (string) $raw['currency_id'] : null,
                'ml_envio_status' => $effEnvio['status'],
                'ml_envio_substatus' => $effEnvio['substatus'],
                'ml_envio_label' => $effEnvio['label'],
            ];
        });

        $ordersByGroup = $items->groupBy('group_key')->map(fn (Collection $group) => $group
            ->groupBy('id_local')->map(function (Collection $orderItems): array {
                $first = $orderItems->first();
                $individualItems = $orderItems->unique(fn (object $item): string => $item->item_row_id > 0
                    ? 'row_'.$item->item_row_id
                    : implode('|', [$item->item_id, $item->sku, $item->cantidad, $item->precio_unitario]));

                return [
                    'id' => $first->id_local,
                    'order_id' => $first->order_id,
                    'status' => $first->order_status,
                    'cancelled' => $first->order_cancelled,
                    'shipping_id' => $first->shipping_id,
                    'shipping_status' => $first->ml_envio_status,
                    'shipping_label' => $first->ml_envio_label,
                    'total_amount' => $first->order_total_amount ?? ($individualItems->isNotEmpty() ? (float) $individualItems->sum('total_linea') : null),
                    'currency_id' => $first->order_currency_id,
                    'items' => $individualItems->map(fn (object $item): array => [
                        'titulo' => $item->titulo,
                        'sku' => $item->sku,
                        'cantidad' => $item->cantidad,
                    ])->values()->all(),
                ];
            })->values());

        $items = $this->removeDuplicateItems($items);

        $pedidosAgrupados = $items
            ->groupBy('group_key')
            ->map(function (Collection $group) use ($ordersByGroup) {
                $primer = $group->sortByDesc('fecha_pedido')->first();

                $headerLabel = $primer->ml_display_id
                    ?: (trim((string) ($primer->order_id ?? '')) !== '' ? (string) $primer->order_id : null)
                    ?: ('ID-' . $primer->id_local);

                return (object) [
                    'group_key' => $primer->group_key,
                    'pack_id' => $primer->pack_id,
                    'order_id' => $primer->order_id,
                    'orders' => $ordersByGroup->get($primer->group_key, collect()),
                    'display_id' => $headerLabel,
                    'ams_tipo' => $primer->ams_tipo ?? 'OTRO',
                    'fecha_pedido' => $primer->fecha_pedido,
                    'shipping_id' => (string) ($primer->shipping_id ?? ''),
                    'fecha_pedido_formateada' => $primer->fecha_pedido_formateada,
                    'ml_envio_status' => (string) ($primer->ml_envio_status ?? ''),
                    'ml_envio_substatus' => (string) ($primer->ml_envio_substatus ?? ''),
                    'ml_envio_label' => (string) ($primer->ml_envio_label ?? ''),
                    'items' => $group->values(),
                    'total_piezas' => (int) $group->sum('cantidad'),
                    'total_pedido' => (float) $group->sum('total_linea'),
                ];
            })
            ->sortByDesc(function ($pedido) {
                return $pedido->fecha_pedido;
            })
            ->values();

        $totalPedidos = $pedidosAgrupados->count();
        $totalPiezas = (int) $pedidosAgrupados->sum('total_piezas');
        $totalVendido = (float) $pedidosAgrupados->sum('total_pedido');

        return [
            'pedidos' => $pedidosAgrupados,
            'totalPedidos' => $totalPedidos,
            'totalPiezas' => $totalPiezas,
            'totalVendido' => $totalVendido,
        ];
    }

    protected function pedidosToInertiaArray(Collection $pedidosAgrupados, bool $incluirMarca = false): array
    {
        return $pedidosAgrupados
            ->map(function ($p) use ($incluirMarca) {
                $row = [
                    'group_key' => $p->group_key,
                    'order_id' => (string) $p->order_id,
                    'pack_id' => isset($p->pack_id) ? (string) $p->pack_id : null,
                    'orders' => collect($p->orders ?? [])->map(fn (mixed $order): array => (array) $order)->values()->all(),
                    'display_id' => $p->display_id,
                    'ams_tipo' => $p->ams_tipo ?? 'OTRO',
                    'fecha_pedido_formateada' => $p->fecha_pedido_formateada,
                    'shipping_id' => (string) ($p->shipping_id ?? ''),
                    'ml_envio_status' => (string) ($p->ml_envio_status ?? ''),
                    'ml_envio_substatus' => (string) ($p->ml_envio_substatus ?? ''),
                    'ml_envio_label' => (string) ($p->ml_envio_label ?? ''),
                    'total_piezas' => $p->total_piezas,
                    'total_pedido' => (float) $p->total_pedido,
                    'items' => collect($p->items)
                        ->map(function ($i) use ($incluirMarca) {
                            $item = [
                                'item_id' => (string) ($i->item_id ?? ''),
                                'titulo' => $i->titulo,
                                'imagen' => $i->imagen,
                                'cantidad' => (int) $i->cantidad,
                                'sku' => (string) ($i->sku ?? ''),
                                'precio_unitario' => (float) $i->precio_unitario,
                                'total_linea' => (float) $i->total_linea,
                            ];
                            if ($incluirMarca && isset($i->ams_marca_label)) {
                                $item['ams_marca_label'] = (string) $i->ams_marca_label;
                            }

                            return $item;
                        })
                        ->values()
                        ->all(),
                ];
                if ($incluirMarca && isset($p->ams_marca_label)) {
                    $row['ams_marca_label'] = (string) $p->ams_marca_label;
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Pantalla tablet: mensaje "Imprimiendo..." y disparo de impresión del PDF (sin abrir solo el visor).
     */
    public function shippingLabelPrintPage(Request $request, string $shippingId)
    {
        $shippingId = trim($shippingId);
        if ($shippingId === '' || !ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $user = $request->user();
        if (!$user || !$user->access_token) {
            return redirect()
                ->route('ams.pedidos.procesar')
                ->with('error', 'No hay token de Mercado Libre para imprimir la etiqueta.');
        }

        return Inertia::render('Ams/ShippingLabelPrint', [
            'shippingId' => $shippingId,
            'pdfUrl' => route('ams.pedidos.shipping_label', ['shippingId' => $shippingId]),
            'procesarUrl' => route('ams.pedidos.procesar'),
        ]);
    }

    public function printShippingLabel(Request $request, string $shippingId)
    {
        $shippingId = trim($shippingId);
        if ($shippingId === '' || !ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $user = $request->user();
        if (!$user || !$user->access_token) {
            return redirect()
                ->back()
                ->with('error', 'No hay token de Mercado Libre para imprimir la etiqueta.');
        }

        $endpoint = 'https://api.mercadolibre.com/shipment_labels';
        $query = [
            'shipment_ids' => $shippingId,
            'response_type' => 'pdf',
        ];

        $ml = Http::withToken((string) $user->access_token)
            ->withHeaders(['Accept' => 'application/pdf'])
            ->timeout(25)
            ->get($endpoint, $query);

        if (!$ml->successful() || stripos((string) $ml->header('Content-Type', ''), 'pdf') === false) {
            return redirect()
                ->back()
                ->with('error', 'No se pudo descargar la etiqueta desde Mercado Libre.');
        }

        $filename = "etiqueta_{$shippingId}.pdf";

        return response($ml->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Devuelve la etiqueta ZPL de la cuenta principal, con el contenido
     * del pedido insertado en el espacio libre de la misma guía.
     */
    public function rawShippingLabelZpl(
        Request $request,
        string $shippingId
    ) {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $user = $request->user();

        if (! $user || empty($user->access_token)) {
            return response(
                'No hay token de Mercado Libre.',
                422,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        /*
         * Un mismo PACK puede estar compuesto por varias órdenes de Mercado
         * Libre que comparten el mismo shipping_id. Por eso se cargan todas,
         * no únicamente la primera.
         */
        $orders = MeliOrder::query()
            ->with([
                'items' => function ($query) {
                    $query->orderBy('id');
                },
            ])
            ->where('shipping_id', $shippingId)
            ->whereHas('meliAccount', function ($query) use ($user) {
                $query
                    ->where('user_id', $user->id)
                    ->where('is_default', true);
            })
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return response(
                'No se encontró el pedido de la cuenta principal.',
                404,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        /*
         * Se utiliza la primera orden como contenedor para conservar el flujo
         * existente, pero su relación items se reemplaza por el contenido
         * consolidado de todas las órdenes del mismo envío.
         */
        $order = $orders->first();
        $order->setRelation(
            'items',
            $this->mergeMainShippingLabelItems($orders)
        );

        $meliResponse = Http::withToken((string) $user->access_token)
            ->withHeaders([
                'Accept' =>
                    'application/zip, application/octet-stream, text/plain',
            ])
            ->timeout(25)
            ->get(
                'https://api.mercadolibre.com/shipment_labels',
                [
                    'shipment_ids' => $shippingId,
                    'response_type' => 'zpl2',
                ]
            );

        if (! $meliResponse->successful() || $meliResponse->body() === '') {
            $message = 'Mercado Libre no devolvió la etiqueta térmica.';

            $json = $meliResponse->json();

            if (is_array($json)) {
                $apiMessage = trim((string) ($json['message'] ?? ''));

                if ($apiMessage !== '') {
                    $message .= ' '.$apiMessage;
                }
            }

            return response(
                $message,
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $body = $meliResponse->body();

        /*
         * Algunas respuestas llegan como ZPL directo.
         */
        if (
            str_contains($body, '^XA')
            && str_contains($body, '^XZ')
        ) {
            $zplFinal = $this->injectOrderContentsIntoMainZpl(
                $body,
                $order
            );

            return response($zplFinal, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        }

        if (! class_exists(\ZipArchive::class)) {
            return response(
                'La extensión ZIP de PHP no está habilitada.',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $temporaryFile = tempnam(
            sys_get_temp_dir(),
            'meli_main_zpl_'
        );

        if ($temporaryFile === false) {
            return response(
                'No se pudo crear el archivo temporal.',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        try {
            if (file_put_contents($temporaryFile, $body) === false) {
                return response(
                    'No se pudo guardar temporalmente la etiqueta.',
                    500,
                    ['Content-Type' => 'text/plain; charset=UTF-8']
                );
            }

            $zip = new \ZipArchive();

            if ($zip->open($temporaryFile) !== true) {
                return response(
                    'Mercado Libre devolvió una etiqueta térmica inválida.',
                    502,
                    ['Content-Type' => 'text/plain; charset=UTF-8']
                );
            }

            $zpl = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string) $zip->getNameIndex($index);
                $entryContents = $zip->getFromIndex($index);

                if ($entryContents === false) {
                    continue;
                }

                $extension = strtolower(
                    pathinfo($entryName, PATHINFO_EXTENSION)
                );

                if (
                    in_array($extension, ['txt', 'zpl'], true)
                    || (
                        str_contains($entryContents, '^XA')
                        && str_contains($entryContents, '^XZ')
                    )
                ) {
                    $zpl = $entryContents;
                    break;
                }
            }

            $zip->close();

            if (
                ! is_string($zpl)
                || ! str_contains($zpl, '^XA')
                || ! str_contains($zpl, '^XZ')
            ) {
                return response(
                    'No se encontró código ZPL dentro del ZIP.',
                    502,
                    ['Content-Type' => 'text/plain; charset=UTF-8']
                );
            }

            $zplFinal = $this->injectOrderContentsIntoMainZpl(
                $zpl,
                $order
            );

            return response($zplFinal, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * Inserta el producto dentro de la misma etiqueta, antes de la
     * zona del destinatario que comienza aproximadamente en Y=950.
     */
    /**
     * Convierte la etiqueta ZPL personalizada del AMS principal
     * a PNG 4x8 para impresoras que no interpretan ZPL,
     * especialmente KAMO KA-L1.
     */
    public function kamoShippingLabelPng(
        Request $request,
        string $shippingId
    ) {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        /*
         * Reutilizamos exactamente el mismo endpoint ZPL.
         * De esta forma NO existen dos diseños diferentes.
         */
        $zplResponse = $this->rawShippingLabelZpl(
            $request,
            $shippingId
        );

        $status = method_exists($zplResponse, 'getStatusCode')
            ? $zplResponse->getStatusCode()
            : 500;

        if ($status !== 200) {
            return $zplResponse;
        }

        $zpl = method_exists($zplResponse, 'getContent')
            ? (string) $zplResponse->getContent()
            : '';

        if (
            $zpl === ''
            || ! str_contains($zpl, '^XA')
            || ! str_contains($zpl, '^XZ')
        ) {
            return response(
                'No se pudo generar el ZPL personalizado.',
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        /*
         * Labelary:
         * 8dpmm ~= 203 dpi
         * 4 x 8 pulgadas
         * índice 0 = primera etiqueta
         */
        try {
            $render = Http::withHeaders([
                    'Accept' => 'image/png',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->timeout(25)
                ->withBody(
                    $zpl,
                    'application/x-www-form-urlencoded'
                )
                ->post(
                    'https://api.labelary.com/v1/printers/8dpmm/labels/4x8/0/'
                );
        } catch (\Throwable $e) {
            report($e);

            return response(
                'No se pudo convertir la etiqueta para KAMO.',
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        if (! $render->successful() || $render->body() === '') {
            $message = trim((string) $render->body());

            return response(
                'Labelary no pudo convertir la etiqueta.'
                    .($message !== '' ? ' '.$message : ''),
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return response($render->body(), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' =>
                'inline; filename="kamo_'.$shippingId.'.png"',
            'Cache-Control' =>
                'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }


    /**
     * Genera TSPL RAW para la KAMO KA-L1.
     *
     * El diseño proviene exactamente del mismo PNG 4x8 generado
     * desde el ZPL personalizado.
     */
    public function kamoShippingLabelTspl(
        Request $request,
        string $shippingId
    ) {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        /*
         * Reutilizamos la misma imagen PNG 4x8.
         */
        $pngResponse = $this->kamoShippingLabelPng(
            $request,
            $shippingId
        );

        $status = method_exists($pngResponse, 'getStatusCode')
            ? $pngResponse->getStatusCode()
            : 500;

        if ($status !== 200) {
            return $pngResponse;
        }

        $png = method_exists($pngResponse, 'getContent')
            ? (string) $pngResponse->getContent()
            : '';

        if ($png === '') {
            return response(
                'La imagen KAMO está vacía.',
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        if (! function_exists('imagecreatefromstring')) {
            return response(
                'GD no está disponible en PHP.',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $source = @imagecreatefromstring($png);

        if ($source === false) {
            return response(
                'No se pudo abrir la imagen KAMO.',
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        /*
         * Tamaño físico validado:
         * 4 x 8 pulgadas a 203 dpi.
         */
        $width = 812;
        $height = 1624;

        /*
         * BITMAP trabaja por bytes.
         * 812 px necesitan 102 bytes:
         *
         * 102 * 8 = 816 pixels.
         */
        $bytesPerRow = (int) ceil($width / 8);

        $canvas = imagecreatetruecolor(
            $width,
            $height
        );

        if ($canvas === false) {
            imagedestroy($source);

            return response(
                'No se pudo crear el lienzo KAMO.',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $white = imagecolorallocate(
            $canvas,
            255,
            255,
            255
        );

        imagefill(
            $canvas,
            0,
            0,
            $white
        );

        /*
         * Escalamos al tamaño exacto 812x1624.
         */
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source)
        );

        imagedestroy($source);

        /*
         * Generar datos BITMAP.
         *
         * POLARIDAD KAMO COMPROBADA:
         *
         * 1 = blanco
         * 0 = negro
         */
        $bitmap = '';

        for ($y = 0; $y < $height; $y++) {
            for ($byteIndex = 0; $byteIndex < $bytesPerRow; $byteIndex++) {
                $byte = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    $x = ($byteIndex * 8) + $bit;

                    /*
                     * Los cuatro píxeles extra de padding
                     * deben permanecer blancos.
                     */
                    if ($x >= $width) {
                        $byte |= (1 << (7 - $bit));
                        continue;
                    }

                    $rgb = imagecolorat(
                        $canvas,
                        $x,
                        $y
                    );

                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    /*
                     * Luminancia simple.
                     */
                    $gray = (
                        ($r * 299)
                        + ($g * 587)
                        + ($b * 114)
                    ) / 1000;

                    /*
                     * Fondo blanco = bit 1.
                     *
                     * Umbral relativamente alto para
                     * conservar texto fino y códigos.
                     */
                    if ($gray >= 180) {
                        $byte |= (1 << (7 - $bit));
                    }
                }

                $bitmap .= chr($byte);
            }
        }

        imagedestroy($canvas);

        /*
         * Comandos TSPL.
         *
         * No usamos REFERENCE ni SHIFT porque ya comprobamos
         * que esta KAMO no los maneja bien.
         */
        $header =
            "SIZE 4 in,8 in\r\n"
            ."GAP 0,0\r\n"
            ."DIRECTION 1\r\n"
            ."CLS\r\n"
            ."BITMAP 0,0,"
            .$bytesPerRow
            .","
            .$height
            .",0,";

        $footer =
            "\r\n"
            ."PRINT 1,1\r\n";

        $tspl = $header
            .$bitmap
            .$footer;

        return response($tspl, 200, [
            'Content-Type' =>
                'application/octet-stream',

            'Content-Disposition' =>
                'inline; filename="kamo_'
                .$shippingId
                .'.prn"',

            'Content-Length' =>
                strlen($tspl),

            'Cache-Control' =>
                'no-store, no-cache, must-revalidate',

            'Pragma' => 'no-cache',
        ]);
    }


    protected function injectOrderContentsIntoMainZpl(
        string $zpl,
        MeliOrder $order
    ): string {
        /*
         * ETIQUETA 4x8 - AMS PRINCIPAL
         *
         * Mismo diseño validado físicamente en AMS secundaria:
         *
         * CONTENIDO DEL PAQUETE
         *
         * 2x TITULO
         * SKU: XXXXX
         *
         * La guía original de Mercado Libre se desplaza hacia abajo
         * para reservar la zona superior.
         */
        $zpl = $this->shiftOriginalMainMercadoLibreZpl(
            $zpl,
            340,
            -18
        );

        $extraZpl = $this->buildCompactMainOrderContentsZpl(
            $order,
            315
        );

        /*
         * Insertamos nuestro bloque inmediatamente después de ^XA.
         */
        $xaPosition = strpos($zpl, '^XA');

        if ($xaPosition !== false) {
            $insertAt = $xaPosition + 3;

            return substr($zpl, 0, $insertAt)
                ."
"
                .$extraZpl
                ."
"
                .substr($zpl, $insertAt);
        }

        return $zpl;
    }
    /**
     * Consolida todos los artículos de las órdenes que pertenecen al mismo
     * shipping_id. Si una misma línea aparece más de una vez, suma cantidades.
     *
     * @param  Collection<int, MeliOrder>  $orders
     * @return Collection<int, object>
     */
    protected function mergeMainShippingLabelItems(
        Collection $orders
    ): Collection {
        $merged = [];

        foreach ($orders as $order) {
            $items = $order->relationLoaded('items')
                ? $order->items
                : $order->items()->orderBy('id')->get();

            foreach ($items as $item) {
                $title = trim((string) ($item->title ?? ''));
                $sku = trim((string) ($item->sku ?? ''));
                $itemId = trim((string) ($item->item_id ?? ''));
                $variationText = trim(
                    (string) ($item->variation_text ?? '')
                );

                $keyParts = [
                    $sku !== '' ? 'sku:'.$sku : 'item:'.$itemId,
                    'variation:'.$variationText,
                    'title:'.$title,
                ];

                $key = implode('|', $keyParts);

                if (! isset($merged[$key])) {
                    $merged[$key] = (object) [
                        'id' => (int) ($item->id ?? 0),
                        'item_id' => $itemId,
                        'title' => $title,
                        'sku' => $sku,
                        'variation_text' => $variationText,
                        'quantity' => 0,
                    ];
                }

                $merged[$key]->quantity += max(
                    1,
                    (int) ($item->quantity ?? 1)
                );
            }
        }

        return collect(array_values($merged))
            ->sortBy('id')
            ->values();
    }

    /**
     * Genera el contenido compacto para el espacio Y=870 a Y=945.
     */
    protected function buildCompactMainOrderContentsZpl(
        MeliOrder $order,
        int $contentHeight = 315
    ): string {
        /*
         * Los items ya vienen consolidados por shipping_id desde
         * mergeMainShippingLabelItems().
         */
        $items = $order->items;

        $lines = [
            '^FX CONTENIDO DEL PAQUETE AMS PRINCIPAL ^FS',

            /*
             * Caja superior centrada.
             */
            '^FO20,15^GB772,315,2^FS',

            /*
             * Barra negra superior.
             */
            '^FO20,15^GB772,48,48^FS',

            /*
             * Título blanco sobre negro.
             */
            '^FO175,22^A0N,32,32^FR^FDCONTENIDO DEL PAQUETE^FS',
        ];

        if ($items->isEmpty()) {
            $lines[] =
                '^FO75,95^A0N,31,31^FDSIN PRODUCTOS REGISTRADOS^FS';

            $lines[] =
                '^FO75,140^A0N,27,27^FDSKU: N/A^FS';

            return implode("
", $lines);
        }

        /*
         * Máximo cinco productos visibles.
         */
        $itemsToPrint = $items->take(5);

        $count = $itemsToPrint->count();

        /*
         * Ajustamos tamaños automáticamente según cantidad.
         */
        if ($count <= 3) {
            $titleFont = 30;
            $skuFont = 25;
            $rowHeight = 78;
        } elseif ($count === 4) {
            $titleFont = 26;
            $skuFont = 22;
            $rowHeight = 63;
        } else {
            $titleFont = 23;
            $skuFont = 20;
            $rowHeight = 52;
        }

        $y = 75;

        foreach ($itemsToPrint as $item) {
            $quantity = max(
                1,
                (int) ($item->quantity ?? 1)
            );

            $title = $this->cleanMainZplLabelText(
                (string) ($item->title ?? '')
            );

            if ($title === '') {
                $title = 'PRODUCTO';
            }

            /*
             * Evitamos que un título demasiado largo invada el
             * siguiente producto.
             */
            if (mb_strlen($title) > 48) {
                $title = mb_substr($title, 0, 45).'...';
            }

            $sku = $this->cleanMainZplLabelText(
                (string) ($item->sku ?? '')
            );

            if ($sku === '') {
                $sku = 'N/A';
            }

            $productText = $quantity.'x '.$title;

            /*
             * Título en pseudo-negrita.
             */
            $lines[] =
                '^FO70,'.$y
                .'^A0N,'.$titleFont.','.$titleFont
                .'^FB680,1,0,L,0'
                .'^FD'.$productText.'^FS';

            $lines[] =
                '^FO71,'.$y
                .'^A0N,'.$titleFont.','.$titleFont
                .'^FB680,1,0,L,0'
                .'^FD'.$productText.'^FS';

            /*
             * SKU grande y en negrita debajo.
             */
            $skuY = $y + $titleFont + 5;

            $lines[] =
                '^FO90,'.$skuY
                .'^A0N,'.$skuFont.','.$skuFont
                .'^FDSKU: '.$sku.'^FS';

            $lines[] =
                '^FO91,'.$skuY
                .'^A0N,'.$skuFont.','.$skuFont
                .'^FDSKU: '.$sku.'^FS';

            $y += $rowHeight;
        }

        /*
         * Si existen más de cinco líneas consolidadas.
         */
        $remaining = max(
            0,
            $items->count() - $itemsToPrint->count()
        );

        if ($remaining > 0) {
            $lines[] =
                '^FO520,300^A0N,18,18'
                .'^FD+'.$remaining.' PRODUCTO(S)^FS';
        }

        return implode("
", $lines);
    }
    /**
     * Limpia texto dinámico para que no rompa los comandos ZPL.
     */
    /**
     * Mueve hacia abajo todos los elementos ZPL cuya coordenada Y sea igual
     * o superior al límite indicado. También alarga físicamente la etiqueta.
     */
    /**
     * Desplaza la guía original de Mercado Libre para reservar
     * la zona superior de contenido en la etiqueta 4x8.
     */
    protected function shiftOriginalMainMercadoLibreZpl(
        string $zpl,
        int $offsetY,
        int $offsetX = 0
    ): string {
        /*
         * Mover todos los campos ^FO y ^FT.
         */
        $zpl = preg_replace_callback(
            '/\^(FO|FT)(-?\d+),(-?\d+)/',
            static function (array $match) use (
                $offsetX,
                $offsetY
            ): string {
                $command = $match[1];

                $x = (int) $match[2];
                $y = (int) $match[3];

                $x += $offsetX;
                $y += $offsetY;

                /*
                 * Evitamos coordenadas horizontales negativas.
                 */
                $x = max(0, $x);

                return '^'.$command.$x.','.$y;
            },
            $zpl
        ) ?? $zpl;

        /*
         * Ancho real aproximado de 4 pulgadas a 203 dpi.
         */
        if (preg_match('/\^PW\d+/', $zpl)) {
            $zpl = preg_replace(
                '/\^PW\d+/',
                '^PW812',
                $zpl,
                1
            ) ?? $zpl;
        } else {
            $zpl = preg_replace(
                '/\^XA/',
                "^XA\n^PW812",
                $zpl,
                1
            ) ?? $zpl;
        }

        /*
         * Largo 4x8 aproximado a 203 dpi.
         */
        if (preg_match('/\^LL\d+/', $zpl)) {
            $zpl = preg_replace(
                '/\^LL\d+/',
                '^LL1624',
                $zpl,
                1
            ) ?? $zpl;
        } else {
            $zpl = preg_replace(
                '/\^XA/',
                "^XA\n^LL1624",
                $zpl,
                1
            ) ?? $zpl;
        }

        /*
         * Limitar los recuadros ^GB para que no rebasen el
         * ancho físico que ya validamos con la Kamo.
         */
        $zpl = preg_replace_callback(
            '/\^FO(\d+),(\d+)\^GB(\d+),(\d+),(\d+)/',
            static function (array $match): string {
                $x = (int) $match[1];
                $y = (int) $match[2];

                $width = (int) $match[3];
                $height = (int) $match[4];
                $thickness = (int) $match[5];

                /*
                 * Dejamos margen derecho físico.
                 */
                $maximumWidth = max(
                    1,
                    792 - $x
                );

                if ($width > $maximumWidth) {
                    $width = $maximumWidth;
                }

                return '^FO'
                    .$x.','.$y
                    .'^GB'
                    .$width.','.$height.','.$thickness;
            },
            $zpl
        ) ?? $zpl;

        return $zpl;
    }

    protected function shiftMainZplLowerSection(
        string $zpl,
        int $fromY,
        int $offset
    ): string {
        $zpl = preg_replace_callback(
            '/\^(FO|FT)(-?\d+),(-?\d+)/',
            static function (array $match) use ($fromY, $offset): string {
                $command = $match[1];
                $x = (int) $match[2];
                $y = (int) $match[3];

                if ($y >= $fromY) {
                    $y += $offset;
                }

                return '^'.$command.$x.','.$y;
            },
            $zpl
        ) ?? $zpl;

        /*
         * Aumenta la longitud configurada de la etiqueta para que la parte
         * desplazada no quede fuera del área imprimible.
         */
        $zpl = preg_replace_callback(
            '/\^LL(\d+)/',
            static function (array $match) use ($offset): string {
                return '^LL'.((int) $match[1] + $offset);
            },
            $zpl,
            1
        ) ?? $zpl;

        return $zpl;
    }

    protected function cleanMainZplLabelText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $text = \Illuminate\Support\Str::ascii($text);

        $text = str_replace(
            ['^', '~', '\\'],
            [' ', ' ', '/'],
            $text
        );

        $text = preg_replace(
            '/[\x00-\x1F\x7F]/',
            ' ',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/\s+/',
            ' ',
            $text
        ) ?? $text;

        return strtoupper(trim($text));
    }

    protected function removeDuplicateItems(Collection $items): Collection
    {
        return $items->unique(function ($item) {
            return implode('|', [
                (string) $item->group_key,
                (string) $item->item_id,
                (string) $item->sku,
                (string) $item->titulo,
                (string) $item->cantidad,
                number_format((float) $item->precio_unitario, 2, '.', ''),
            ]);
        })->values();
    }

    protected function extractVariationLabel(array $variationAttributes): ?string
    {
        if (empty($variationAttributes)) {
            return null;
        }

        $priorityNames = [
            'Tono',
            'HAIR_TONE',
            'Color',
            'COLOR',
            'Color del rubor',
            'BLUSH_COLOR',
        ];

        foreach ($priorityNames as $priority) {
            foreach ($variationAttributes as $attr) {
                $name = (string) ($attr['name'] ?? '');
                $id = (string) ($attr['id'] ?? '');
                $valueName = trim((string) ($attr['value_name'] ?? ''));

                if ($valueName === '') {
                    continue;
                }

                if ($name === $priority || $id === $priority) {
                    return $valueName;
                }
            }
        }

        return null;
    }

    protected function extractPresentationLabel(array $variationAttributes): ?string
    {
        if (empty($variationAttributes)) {
            return null;
        }

        $priorityNames = [
            'Presentación',
            'HAIR_SHAMPOO_AND_CONDITIONER_PRESENTATION',
            'Tipo de envase',
            'PACKAGING_TYPE',
        ];

        foreach ($priorityNames as $priority) {
            foreach ($variationAttributes as $attr) {
                $name = (string) ($attr['name'] ?? '');
                $id = (string) ($attr['id'] ?? '');
                $valueName = trim((string) ($attr['value_name'] ?? ''));

                if ($valueName === '') {
                    continue;
                }

                if ($name === $priority || $id === $priority) {
                    return $valueName;
                }
            }
        }

        return null;
    }

    protected function titleAlreadyContainsText(string $title, string $text): bool
    {
        return mb_stripos($title, $text) !== false;
    }

    protected function classifyAmsTipo(
        ?string $shippingMode,
        ?string $shippingType,
        ?string $shippingLogisticType,
        array $raw
    ): string {
        $mode = strtolower(trim((string) ($shippingMode ?? '')));
        $type = strtolower(trim((string) ($shippingType ?? '')));
        $logistic = strtolower(trim((string) ($shippingLogisticType ?? '')));

        $ship = is_array($raw['shipping'] ?? null) ? $raw['shipping'] : [];
        $jsonLt = strtolower(trim((string) (data_get($ship, 'logistic_type') ?? '')));
        $optName = strtolower(trim((string) (data_get($ship, 'shipping_option.name') ?? '')));
        $optMethod = strtolower(trim((string) (data_get($ship, 'shipping_option.shipping_method_name') ?? '')));

        if (data_get($raw, 'fulfilled') === true) {
            return 'FULL';
        }

        if (in_array($mode, ['fulfillment', 'full'], true)
            || in_array($logistic, ['fulfillment', 'full'], true)
            || str_contains($type, 'full')) {
            return 'FULL';
        }

        if ($mode === 'flex' || str_contains($type, 'flex') || str_contains($logistic, 'flex')) {
            return 'FLEX';
        }

        $colectaLogistic = ['cross_docking', 'drop_off', 'xd_drop_off', 'self_service'];
        if (in_array($type, $colectaLogistic, true)
            || in_array($logistic, $colectaLogistic, true)
            || in_array($jsonLt, $colectaLogistic, true)) {
            return 'COLECTA';
        }

        if (str_contains($optName, 'colecta') || str_contains($optMethod, 'colecta')) {
            return 'COLECTA';
        }

        return 'OTRO';
    }
}
