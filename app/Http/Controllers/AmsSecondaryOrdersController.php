<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\User;
use App\Services\MeliOrderSyncService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AmsSecondaryOrdersController extends AmsPedidosController
{
    protected ?int $selectedAccountId = null;

    protected ?User $selectedApiUser = null;

    /**
     * Pantalla de pedidos de cuentas secundarias.
     */
    public function procesar(
        Request $request,
        MeliOrderSyncService $meliSync
    ): Response {
        /** @var User $owner */
        $owner = $request->user();

        $accounts = $owner->meliAccounts()
            ->where('is_default', false)
            ->orderBy('nickname')
            ->orderBy('id')
            ->get();

        $selectedAccount = null;

        if ($accounts->isNotEmpty()) {
            $requestedId = $request->integer('account_id');

            $selectedAccount = $requestedId
                ? $accounts->firstWhere('id', $requestedId)
                : $accounts->first();
        }

        if ($selectedAccount) {
            $this->selectedAccountId = (int) $selectedAccount->id;
            $this->selectedApiUser = $this->makeApiUser($owner, $selectedAccount);
        }

        $fechaSeleccionada = $this->resolveFecha($request, false);
        $alcance = $this->resolveAlcanceProcesar($request);
        $orden = $this->resolveOrdenProcesar($request);

        if ($selectedAccount && $this->selectedApiUser) {
            $this->maybeRefreshSecondaryShipments(
                $request,
                $fechaSeleccionada,
                $alcance,
                $meliSync
            );

            if ($alcance === 'procesados') {
                $query = $this->processedSecondaryOrdersQuery(
                    $fechaSeleccionada
                );
            } else {
                $query = $this->procesarCandidateQuery(
                    $fechaSeleccionada,
                    $alcance
                );

                $this->applyProcesarSoloListosEnTiendaSinEnviar($query);
            }

            $rows = $query->get();
        } else {
            $rows = collect();
        }

        $grouped = $this->computePedidosGrouped($rows, true);
        $pedidos = $grouped['pedidos'];

        if ($orden === 'marca') {
            $pedidos = $this->sortPedidosPorMarca($pedidos);
        }

        $accountOptions = $accounts->map(function (MeliAccount $account) {
            $nickname = trim((string) ($account->nickname ?? ''));

            return [
                'id' => $account->id,
                'meli_user_id' => (string) $account->meli_user_id,
                'nickname' => $nickname !== ''
                    ? $nickname
                    : 'Cuenta '.$account->meli_user_id,
                'has_access_token' => ! empty($account->access_token),
            ];
        })->values()->all();

        $selectedLabel = $selectedAccount
            ? (
                trim((string) ($selectedAccount->nickname ?? '')) !== ''
                    ? $selectedAccount->nickname
                    : 'Cuenta '.$selectedAccount->meli_user_id
            )
            : 'Sin cuenta secundaria';

        return Inertia::render('Ams/PedidosProcesar_secondary', [
            'pedidos' => $this->pedidosToInertiaArray(
                $pedidos,
                $orden === 'marca'
            ),
            'fechaSeleccionada' => $fechaSeleccionada,
            'totalPedidos' => $grouped['totalPedidos'],
            'totalPiezas' => $grouped['totalPiezas'],

            'tituloPagina' => 'AMS - Pedidos cuenta secundaria',
            'subtitulo' => $selectedAccount
                ? (
                    $alcance === 'procesados'
                        ? 'Pedidos ya procesados de: '.$selectedLabel.'.'
                        : 'Pedidos listos para procesar de: '.$selectedLabel.'.'
                )
                : 'No existe una cuenta secundaria vinculada.',

            'formAction' => route('ams.secondary.procesar'),
            'syncAction' => route('ams.secondary.sync'),
            'labelBaseUrl' => '/ams/secundaria/pedidos/shipping-label',

            'orden' => $orden,
            'alcance' => $alcance,

            'meliAccounts' => $accountOptions,
            'selectedMeliAccountId' => $selectedAccount?->id,
            'selectedMeliAccountLabel' => $selectedLabel,
        ]);
    }

    /**
     * Sincroniza ventas recientes de la cuenta secundaria seleccionada.
     */
    public function sync(
        Request $request,
        MeliOrderSyncService $meliSync
    ): RedirectResponse {
        $validated = $request->validate([
            'account_id' => [
                'required',
                'integer',
                'exists:meli_accounts,id',
            ],
            'days' => [
                'nullable',
                'integer',
                'min:1',
                'max:14',
            ],
        ]);

        /** @var User $owner */
        $owner = $request->user();

        $account = $owner->meliAccounts()
            ->where('is_default', false)
            ->whereKey((int) $validated['account_id'])
            ->first();

        if (! $account) {
            return back()->with(
                'error',
                'La cuenta secundaria seleccionada no existe.'
            );
        }

        if (empty($account->access_token)) {
            return back()->with(
                'error',
                'La cuenta secundaria no tiene access token. Reautorízala.'
            );
        }

        $apiUser = $this->makeApiUser($owner, $account);

        $days = (int) ($validated['days'] ?? 7);
        $orders = 0;
        $items = 0;

        try {
            for ($offset = 0; $offset < $days; $offset++) {
                $date = now()
                    ->subDays($offset)
                    ->toDateString();

                $result = $meliSync->syncDay($apiUser, $date);

                $orders += (int) ($result['orders'] ?? 0);
                $items += (int) ($result['items'] ?? 0);
            }
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'No se pudieron sincronizar los pedidos secundarios: '
                .$exception->getMessage()
            );
        }

        return redirect()
            ->route('ams.secondary.procesar', [
                'account_id' => $account->id,
                'alcance' => $request->input('alcance', 'ml_listado'),
                'orden' => $request->input('orden', 'fecha'),
                'fecha' => $request->input('fecha', now()->toDateString()),
            ])
            ->with(
                'success',
                "Sincronización terminada: {$orders} pedidos y {$items} artículos."
            );
    }

    /**
     * Filtra todas las consultas heredadas por la cuenta secundaria.
     */
        protected function basePedidosQuery(
        bool $innerJoinOrderItems = true,
        ?int $meliAccountId = null
    ) {
        $accountId = $meliAccountId
            ?? $this->selectedAccountId
            ?? 2;

        return parent::basePedidosQuery(
            $innerJoinOrderItems,
            $accountId
        );
	}

    /**
     * La vista secundaria admite los dos alcances heredados y el historial.
     *
     * @return 'colecta'|'ml_listado'|'procesados'
     */
    protected function resolveAlcanceProcesar(Request $request): string
    {
        $value = strtolower(
            trim((string) $request->input('alcance', 'ml_listado'))
        );

        return in_array(
            $value,
            ['colecta', 'ml_listado', 'procesados'],
            true
        )
            ? $value
            : 'ml_listado';
    }

    /**
     * Pedidos secundarios cuyo envío ya avanzó después de generar la etiqueta.
     */
    protected function processedSecondaryOrdersQuery(string $fechaSeleccionada)
    {
        $effectiveStatus = $this->sqlEffectiveShippingStatus();
        $effectiveSubstatus = $this->sqlEffectiveShippingSubstatus();

        return $this->basePedidosQuery(false)
            ->whereDate('o.created_at', $fechaSeleccionada)
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
                            ->whereIn(
                                DB::raw("({$effectiveSubstatus})"),
                                [
                                    'printed',
                                    'invoice_pending',
                                    'waiting_for_carrier_authorization',
                                ]
                            );
                    })
                    ->orWhereIn(
                        DB::raw("({$effectiveStatus})"),
                        [
                            'handling',
                            'shipped',
                            'in_transit',
                            'delivered',
                        ]
                    )
                    ->orWhereIn(
                        DB::raw("({$effectiveSubstatus})"),
                        [
                            'printed',
                            'dropped_off',
                            'picked_up',
                            'in_hub',
                            'out_for_delivery',
                            'delivered',
                        ]
                    );
            });
    }

    protected function maybeRefreshSecondaryShipments(
        Request $request,
        string $fechaSeleccionada,
        string $alcance,
        MeliOrderSyncService $meliSync
    ): void {
        if ($request->boolean('sin_refrescar')) {
            return;
        }

        if (! config('ams_colecta.refresh_shipments_on_procesar', true)) {
            return;
        }

        if (! $this->selectedApiUser) {
            return;
        }

        if ($alcance === 'ml_listado') {
            $effSt = $this->sqlEffectiveShippingStatus();
            $effSub = $this->sqlEffectiveShippingSubstatus();

            $candidate = $this->basePedidosQuery(false)
                ->where(function ($query) {
                    $query
                        ->whereRaw(
                            "LOWER(COALESCE(o.status, '')) = 'paid'"
                        )
                        ->orWhereRaw(
                            "LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.status')), ''))) = 'paid'"
                        );
                })
                ->whereRaw("({$effSt}) = 'ready_to_ship'")
                ->whereRaw("({$effSub}) = 'ready_to_print'");
        } elseif ($alcance === 'procesados') {
            /*
             * Para detectar una etiqueta recién impresa, refrescamos todos los
             * envíos pagados del día. Antes de refrescar, la BD todavía puede
             * tener ready_to_print aunque Mercado Libre ya lo cambió a printed.
             */
            $candidate = $this->basePedidosQuery(false)
                ->whereDate('o.created_at', $fechaSeleccionada)
                ->where(function ($query) {
                    $query
                        ->whereRaw(
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

        $max = max(
            1,
            (int) config(
                'ams_colecta.refresh_shipments_max_ids',
                150
            )
        );

        if (count($ids) > $max) {
            $ids = array_slice($ids, 0, $max);
        }

        if ($ids !== []) {
            $meliSync->refreshShipmentsByShippingIds(
                $this->selectedApiUser,
                $ids
            );
        }
    }

    public function shippingLabelPrintPage(
        Request $request,
        string $shippingId
    ): Response|RedirectResponse {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $order = $this->findSecondaryOrderByShippingId(
            $request,
            $shippingId
        );

        if (! $order?->meliAccount?->access_token) {
            return redirect()
                ->route('ams.secondary.procesar')
                ->with(
                    'error',
                    'No hay token de la cuenta secundaria para imprimir.'
                );
        }

        return Inertia::render('Ams/ShippingLabelPrint', [
            'shippingId' => $shippingId,
            'pdfUrl' => route(
                'ams.secondary.shipping_label',
                ['shippingId' => $shippingId]
            ),
            'procesarUrl' => route('ams.secondary.procesar', [
                'account_id' => $order->meli_account_id,
                'alcance' => $request->input('volver', 'ml_listado'),
                'fecha' => $request->input('fecha', now()->toDateString()),
            ]),
        ]);
    }

    public function printShippingLabel(
        Request $request,
        string $shippingId
    ) {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $order = $this->findSecondaryOrderByShippingId(
            $request,
            $shippingId
        );

        $token = trim(
            (string) ($order?->meliAccount?->access_token ?? '')
        );

        if ($token === '') {
            return back()->with(
                'error',
                'No hay token de la cuenta secundaria.'
            );
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/pdf',
            ])
            ->timeout(25)
            ->get(
                'https://api.mercadolibre.com/shipment_labels',
                [
                    'shipment_ids' => $shippingId,
                    'response_type' => 'pdf',
                ]
            );

        $contentType = (string) $response->header(
            'Content-Type',
            ''
        );

        if (
            ! $response->successful()
            || stripos($contentType, 'pdf') === false
        ) {
            return back()->with(
                'error',
                'Mercado Libre no devolvió la etiqueta secundaria.'
            );
        }

        return response($response->body(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' =>
                'inline; filename="etiqueta_'.$shippingId.'.pdf"',
            'Cache-Control' =>
                'no-store, no-cache, must-revalidate',
        ]);
    }


    /**
     * Descarga la respuesta térmica original de Mercado Libre.
     *
     * Mercado Libre normalmente entrega un ZIP que contiene el archivo ZPL.
     */
    public function downloadShippingLabelZpl(
        Request $request,
        string $shippingId
    ) {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $order = $this->findSecondaryOrderByShippingId(
            $request,
            $shippingId
        );

        $token = trim(
            (string) ($order?->meliAccount?->access_token ?? '')
        );

        if ($token === '') {
            return back()->with(
                'error',
                'No hay token de la cuenta secundaria.'
            );
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/zip, application/octet-stream, text/plain',
            ])
            ->timeout(25)
            ->get(
                'https://api.mercadolibre.com/shipment_labels',
                [
                    'shipment_ids' => $shippingId,
                    'response_type' => 'zpl2',
                ]
            );

        if (! $response->successful() || $response->body() === '') {
            return back()->with(
                'error',
                'Mercado Libre no devolvió la etiqueta térmica.'
            );
        }

        $contentType = strtolower(
            trim((string) $response->header('Content-Type', ''))
        );

        $isZip = str_contains($contentType, 'zip')
            || str_contains($contentType, 'octet-stream')
            || str_starts_with($response->body(), "PK");

        $extension = $isZip ? 'zip' : 'zpl';
        $downloadType = $isZip
            ? 'application/zip'
            : 'text/plain; charset=UTF-8';

        return response($response->body(), 200, [
            'Content-Type' => $downloadType,
            'Content-Disposition' =>
                'attachment; filename="etiqueta_termica_'.$shippingId.'.'.$extension.'"',
            'Cache-Control' =>
                'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Devuelve únicamente el código ZPL para enviarlo directamente a QZ Tray.
     */
    public function rawShippingLabelZpl(
        Request $request,
        string $shippingId
    ) {
        $shippingId = trim($shippingId);

        if ($shippingId === '' || ! ctype_digit($shippingId)) {
            abort(422, 'shipping_id inválido');
        }

        $order = $this->findSecondaryOrderByShippingId(
            $request,
            $shippingId
        );

        $token = trim(
            (string) ($order?->meliAccount?->access_token ?? '')
        );

        if ($token === '') {
            return response(
                'No hay token de la cuenta secundaria.',
                422,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $meliResponse = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/zip, application/octet-stream, text/plain',
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
            return response(
                'Mercado Libre no devolvió la etiqueta térmica.',
                502,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $body = $meliResponse->body();

        /*
         * Algunas respuestas llegan como ZPL directo.
         */
        if (str_contains($body, '^XA') && str_contains($body, '^XZ')) {
            $zplFinal = $this->injectOrderContentsIntoZpl(
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
                'La extensión ZIP de PHP no está habilitada en el servidor.',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'meli_zpl_');

        if ($temporaryFile === false) {
            return response(
                'No se pudo crear el archivo temporal para la etiqueta.',
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
                    'No se encontró código ZPL dentro del ZIP de Mercado Libre.',
                    502,
                    ['Content-Type' => 'text/plain; charset=UTF-8']
                );
            }

            $zplFinal = $this->injectOrderContentsIntoZpl(
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
     * Inserta el contenido del pedido dentro del espacio libre de la
     * etiqueta original de Mercado Libre.
     *
     * La plantilla analizada deja libre aproximadamente desde Y=870
     * hasta Y=945. La zona del destinatario comienza en Y=950.
     */
    protected function injectOrderContentsIntoZpl(
        string $zpl,
        MeliOrder $order
    ): string {
        /*
         * ETIQUETA 4x8 PARA KAMO / TPL
         *
         * Reservamos la parte superior para:
         *
         *   CONTENIDO DEL PAQUETE
         *   2x Titulo
         *   SKU: XXXXX
         *
         * y desplazamos la guia original de Mercado Libre hacia abajo.
         *
         * También movemos ligeramente la guia hacia la izquierda
         * porque físicamente la Kamo estaba cortando el lado derecho.
         */
        $zpl = $this->shiftOriginalMercadoLibreZpl(
            $zpl,
            340,
            -18
        );

        $extraZpl = $this->buildCompactOrderContentsZpl($order);

        /*
         * Insertamos nuestro bloque inmediatamente después de ^XA.
         * Así queda arriba y la guía de Mercado Libre comienza debajo.
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
     * Construye un bloque compacto de hasta tres líneas para colocarlo
     * dentro de la misma etiqueta de Mercado Libre.
     */
    protected function buildCompactOrderContentsZpl(
        MeliOrder $order
    ): string {
        $items = $order->items;

        $lines = [
            '^FX CONTENIDO DEL PAQUETE AMS ^FS',

            /*
             * Caja superior centrada.
             */
            '^FO20,15^GB772,315,2^FS',

            /*
             * Barra negra.
             */
            '^FO20,15^GB772,48,48^FS',

            /*
             * Titulo blanco sobre negro.
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
         * En 4x8 caben cómodamente hasta 5 productos
         * manteniendo título + SKU.
         */
        $itemsToPrint = $items->take(5);

        $count = $itemsToPrint->count();

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

            $title = $this->cleanZplLabelText(
                (string) ($item->title ?? '')
            );

            if ($title === '') {
                $title = 'PRODUCTO';
            }

            /*
             * Una sola línea para evitar que invada el siguiente SKU.
             */
            if (mb_strlen($title) > 48) {
                $title = mb_substr($title, 0, 45).'...';
            }

            $sku = $this->cleanZplLabelText(
                (string) ($item->sku ?? '')
            );

            if ($sku === '') {
                $sku = 'N/A';
            }

            $productText = $quantity.'x '.$title;

            /*
             * TÍTULO DEL PRODUCTO
             *
             * Duplicamos 1 punto para darle efecto de negrita.
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
         * Si existen más de cinco artículos, avisamos.
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
     * Mueve hacia abajo los elementos ZPL cuya coordenada Y sea igual
     * o superior al límite indicado.
     */
    /**
     * Prepara la guía original de Mercado Libre para nuestra etiqueta 4x8.
     *
     * - Desplaza todos los objetos hacia abajo.
     * - Corrige ligeramente el eje X para evitar recorte a la derecha.
     * - Fuerza ancho imprimible de 812 puntos.
     * - Fuerza longitud aproximada de 4x8 a 203 dpi.
     */
    protected function shiftOriginalMercadoLibreZpl(
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
                 * Nunca permitimos coordenadas negativas
                 * en el eje horizontal.
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
         * Alto 4x8 aproximado a 203 dpi.
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
         * Algunos recuadros originales usan 850 puntos de ancho.
         * Los limitamos para que no rebasen el ancho útil de la Kamo.
         */
        $zpl = preg_replace_callback(
            '/\^FO(\d+),(\d+)\^GB(\d+),(\d+),(\d+)/',
            static function (array $match): string {
                $x = (int) $match[1];
                $y = (int) $match[2];
                $width = (int) $match[3];
                $height = (int) $match[4];
                $thickness = (int) $match[5];

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

    protected function shiftSecondaryZplLowerSection(
        string $zpl,
        int $fromY,
        int $offset
    ): string {
        $zpl = preg_replace_callback(
            '/\^(FO|FT)(-?\d+),(-?\d+)/',
            static function (
                array $match
            ) use (
                $fromY,
                $offset
            ): string {
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
         * Aumenta la longitud física de la etiqueta para que los
         * elementos desplazados sigan dentro del área imprimible.
         */
        $zpl = preg_replace_callback(
            '/\^LL(\d+)/',
            static function (
                array $match
            ) use (
                $offset
            ): string {
                return '^LL'.(
                    (int) $match[1]
                    + $offset
                );
            },
            $zpl,
            1
        ) ?? $zpl;

        return $zpl;
    }

    protected function cleanZplLabelText(string $text): string
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

    protected function findSecondaryOrderByShippingId(
        Request $request,
        string $shippingId
    ): ?MeliOrder {
        return MeliOrder::query()
            ->with([
                'meliAccount',
                'items' => function ($query) {
                    $query->orderBy('id');
                },
            ])
            ->where('shipping_id', $shippingId)
            ->whereHas('meliAccount', function ($query) use ($request) {
                $query
                    ->where('user_id', $request->user()->id)
                    ->where('is_default', false);
            })
            ->first();
    }

    protected function makeApiUser(
        User $owner,
        MeliAccount $account
    ): User {
        /** @var User $apiUser */
        $apiUser = clone $owner;

        $apiUser->forceFill([
            'meli_id' => $account->meli_user_id,
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_at' => $account->expires_at,
            'official_store_id' => $account->official_store_id,
        ]);

        $apiUser->setAttribute('id', $owner->id);

        return $apiUser;
    }
}