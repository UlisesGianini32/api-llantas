<?php

namespace App\Services;

use App\Models\MeliOrder;
use App\Models\MeliPublication;
use App\Models\User;
use App\Support\SyscomCarritoPagoHelper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyscomOrderFromMeliService
{
    /**
     * Tras actualizar una orden ML: cancelar en SYSCOM si ya está cancelada en ML;
     * si no, intentar crear pedido cuando esté paid.
     */
    public function handleAfterMeliSync(User $user, MeliOrder $order): void
    {
        if ($this->isMeliOrderCancelled($order->status)) {
            $this->cancelIfEligible($user, $order);

            return;
        }

        $this->syncIfEligible($user, $order);
    }

    /**
     * Cancela en SYSCOM un pedido ya generado cuando ML cancela la venta.
     */
    public function cancelIfEligible(User $user, MeliOrder $order): void
    {
        if (! (bool) config('syscom.orders_from_meli.cancel_enabled', true)) {
            return;
        }

        if (! (bool) config('syscom.orders_from_meli.enabled', true)) {
            return;
        }

        $orderIdKey = trim((string) ($order->order_id ?? ''));
        if ($orderIdKey === '') {
            return;
        }

        if (! $this->isMeliOrderCancelled($order->status)) {
            return;
        }

        $folio = trim((string) ($order->syscom_order_folio ?? ''));
        if ($folio === '') {
            return;
        }

        if ($order->syscom_order_cancelled_at) {
            return;
        }

        $useLock = (bool) config('syscom.orders_from_meli.use_cancel_lock', true);
        $lock = null;
        if ($useLock) {
            $lock = Cache::lock('syscom:ml-order-cancel:' . $orderIdKey, 90);
            try {
                $lock->block(25);
            } catch (LockTimeoutException) {
                Log::info('ML->SYSCOM cancel: lock ocupado; se omite', [
                    'meli_order_id' => $orderIdKey,
                ]);

                return;
            }
        }

        try {
            $order = MeliOrder::query()->find($order->id);
            if (! $order) {
                return;
            }

            if (! $this->isMeliOrderCancelled($order->status)) {
                return;
            }

            $folio = trim((string) ($order->syscom_order_folio ?? ''));
            if ($folio === '' || $order->syscom_order_cancelled_at) {
                return;
            }

            $result = $this->requestSyscomCancel($folio, $orderIdKey);
            if ($result['ok']) {
                $order->syscom_order_cancelled_at = now();
                $order->syscom_order_cancel_error = null;
                $order->syscom_order_cancel_raw = $result['json'];
                $order->save();

                Log::info('ML->SYSCOM pedido cancelado', [
                    'meli_order_id' => $order->order_id,
                    'syscom_folio' => $folio,
                    'path' => $result['path'],
                ]);

                return;
            }

            $order->syscom_order_cancel_error = $result['error'];
            $order->save();

            Log::warning('ML->SYSCOM cancelar pedido falló', [
                'meli_order_id' => $order->order_id,
                'syscom_folio' => $folio,
                'error' => $result['error'],
            ]);
        } finally {
            $lock?->release();
        }
    }

    public function isMeliOrderCancelled(?string $status): bool
    {
        $s = mb_strtolower(trim((string) $status));

        return in_array($s, ['cancelled', 'canceled', 'invalid', 'expired'], true);
    }

    /**
     * @return array{ok:bool, error?:string, path?:string, json?:array<string,mixed>|null}
     */
    private function requestSyscomCancel(string $folio, string $meliOrderId): array
    {
        $token = app(SyscomApiService::class)->getAccessToken();
        $baseUrl = rtrim((string) config('services.syscom.base_url', 'https://developers.syscom.mx/api/v1'), '/');
        $method = strtoupper(trim((string) config('syscom.orders_from_meli.cancel_method', 'POST')));
        if (! in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            $method = 'POST';
        }

        $paths = [];
        $configured = trim((string) config('syscom.orders_from_meli.cancel_path', ''));
        if ($configured !== '') {
            $paths[] = $configured;
        } else {
            $paths = (array) config('syscom.orders_from_meli.cancel_path_candidates', []);
        }

        if ($paths === []) {
            return [
                'ok' => false,
                'error' => 'SYSCOM_CANCEL_NO_PATH: Configure SYSCOM_ORDER_CANCEL_PATH (soporte SYSCOM).',
            ];
        }

        $payloads = [
            [
                'folio_pedido' => $folio,
                'folio' => $folio,
                'orden_compra' => 'ML-' . $meliOrderId,
            ],
            [
                'folio' => $folio,
                'orden_compra' => 'ML-' . $meliOrderId,
            ],
            [
                'folio_pedido' => $folio,
            ],
        ];

        $errors = [];
        foreach ($paths as $path) {
            $path = '/' . ltrim($path, '/');
            foreach ($payloads as $body) {
                try {
                    $http = Http::withToken($token)->acceptJson()->timeout(60);
                    $url = $baseUrl . $path;
                    $resp = match ($method) {
                        'DELETE' => $http->delete($url, $body),
                        'PUT' => $http->put($url, $body),
                        default => $http->post($url, $body),
                    };

                    if ($resp->status() === 404) {
                        $errors[] = $path . ' 404';

                        continue;
                    }

                    if ($resp->successful() && $this->syscomCancelResponseLooksSuccessful($resp->json())) {
                        $json = $resp->json();

                        return [
                            'ok' => true,
                            'path' => $path,
                            'json' => is_array($json) ? $json : null,
                        ];
                    }

                    $bodyText = (string) $resp->body();
                    $errors[] = $path . ' ' . $resp->status() . ' ' . mb_substr($bodyText, 0, 400);
                } catch (\Throwable $e) {
                    $errors[] = $path . ' EX: ' . $e->getMessage();
                }
            }
        }

        return [
            'ok' => false,
            'error' => 'SYSCOM cancelar pedido falló: ' . mb_substr(implode(' | ', $errors), 0, 1500),
        ];
    }

    /**
     * @param  mixed  $json
     */
    private function syscomCancelResponseLooksSuccessful(mixed $json): bool
    {
        if (! is_array($json)) {
            return true;
        }

        foreach (['error', 'errores', 'errors'] as $key) {
            $v = $json[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return false;
            }
            if (is_array($v) && $v !== []) {
                return false;
            }
        }

        $success = $json['success'] ?? $json['exito'] ?? $json['ok'] ?? null;
        if ($success === false || $success === 0 || $success === '0') {
            return false;
        }

        $estado = mb_strtolower(trim((string) ($json['estado'] ?? $json['status'] ?? '')));
        if (in_array($estado, ['error', 'fallo', 'failed', 'cancelado_error'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Crea un pedido en SYSCOM a partir de una orden pagada de ML.
     *
     * Anti-duplicado por orden: si ya hay `syscom_order_synced_at` o `syscom_order_folio`, no vuelve a llamar
     * a SYSCOM. Tras tomar lock (opcional) se recarga la fila y se vuelve a comprobar por si otro proceso
     * terminó antes.
     *
     * Anti-duplicado por producto: varias líneas ML que resuelven al mismo id SYSCOM se suman en una sola
     * línea en el payload (`cantidad` total).
     */
    public function syncIfEligible(User $user, MeliOrder $order): void
    {
        if (! (bool) config('syscom.orders_from_meli.enabled', true)) {
            return;
        }

        $orderIdKey = trim((string) ($order->order_id ?? ''));
        if ($orderIdKey === '') {
            return;
        }

        if ($this->isMeliOrderCancelled($order->status)) {
            return;
        }

        $statusQuick = mb_strtolower(trim((string) ($order->status ?? '')));
        if ($statusQuick !== 'paid') {
            return;
        }

        if ($order->syscom_order_synced_at || trim((string) ($order->syscom_order_folio ?? '')) !== '') {
            return;
        }

        $useLock = (bool) config('syscom.orders_from_meli.use_sync_lock', true);
        $lock = null;
        if ($useLock) {
            $lock = Cache::lock('syscom:ml-order:' . $orderIdKey, 90);
            try {
                $lock->block(25);
            } catch (LockTimeoutException) {
                Log::info('ML->SYSCOM: lock ocupado o timeout; se omite (evita doble envío)', [
                    'meli_order_id' => $orderIdKey,
                ]);

                return;
            }
        }

        try {
            $order = MeliOrder::query()->find($order->id);
            if (! $order) {
                return;
            }

            if ($this->isMeliOrderCancelled($order->status)) {
                return;
            }

            $status = mb_strtolower(trim((string) ($order->status ?? '')));
            if ($status !== 'paid') {
                return;
            }

            if ($order->syscom_order_synced_at || trim((string) ($order->syscom_order_folio ?? '')) !== '') {
                return;
            }

            $productos = $this->aggregateProductosForSyscom($user, $order);

            if ($productos === []) {
                $order->syscom_order_error = 'SKIP_NO_SYSCOM_ITEMS: Sin items SYSCOM válidos (SKU SYSCOM-<id>) para generar pedido.';
                $order->save();

                return;
            }

            $token = app(SyscomApiService::class)->getAccessToken();
            $baseUrl = rtrim((string) config('services.syscom.base_url', 'https://developers.syscom.mx/api/v1'), '/');

            try {
                $payload = $this->buildPayload($token, $baseUrl, $user, $order, $productos);
            } catch (\Throwable $e) {
                $order->syscom_order_error = $e->getMessage();
                $order->save();
                Log::warning('ML->SYSCOM payload inválido', [
                    'meli_order_id' => $order->order_id,
                    'err' => $e->getMessage(),
                ]);

                return;
            }

            $resp = Http::withToken($token)
                ->acceptJson()
                ->timeout(60)
                ->post($baseUrl . '/carrito/generar', $payload);

            if (! $resp->successful()) {
                $body = (string) $resp->body();
                $order->syscom_order_error = 'SYSCOM carrito/generar falló: ' . $resp->status() . ' ' . mb_substr($body, 0, 1500);
                $order->save();

                Log::warning('ML->SYSCOM crear pedido falló', [
                    'meli_order_id' => $order->order_id,
                    'status' => $resp->status(),
                    'body' => $body,
                ]);

                return;
            }

            $json = $resp->json();
            $folio = trim((string) (data_get($json, 'resumen.folio_pedido') ?? data_get($json, 'resumen.folio') ?? ''));

            $order->syscom_order_folio = $folio !== '' ? $folio : null;
            $order->syscom_order_synced_at = now();
            $order->syscom_order_error = null;
            $order->syscom_order_raw = is_array($json) ? $json : null;
            $order->save();

            Log::info('ML->SYSCOM pedido creado', [
                'meli_order_id' => $order->order_id,
                'syscom_folio' => $order->syscom_order_folio,
                'items' => count($productos),
                'syscom_forma_pago' => data_get($json, 'resumen.forma_pago'),
                'syscom_codigo_pago' => data_get($json, 'resumen.codigo_pago'),
            ]);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Agrupa por id de producto SYSCOM: misma referencia en varias líneas ML → una sola línea con cantidad sumada.
     *
     * @return array<int, array{id:int, tipo:string, cantidad:int}>
     */
    private function aggregateProductosForSyscom(User $user, MeliOrder $order): array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $qtyBySyscomId = [];
        foreach ($items as $row) {
            $syscomId = $this->resolveSyscomIdFromOrderItem($user->id, (string) ($row->sku ?? ''), (string) ($row->item_id ?? ''));
            $qty = max(0, (int) ($row->quantity ?? 0));
            if (! $syscomId || $qty <= 0) {
                continue;
            }
            $qtyBySyscomId[$syscomId] = ($qtyBySyscomId[$syscomId] ?? 0) + $qty;
        }

        $productos = [];
        foreach ($qtyBySyscomId as $id => $qty) {
            $productos[] = [
                'id' => (int) $id,
                'tipo' => 'nuevo',
                'cantidad' => (int) $qty,
            ];
        }

        return $productos;
    }

    /**
     * @param  array<int, array{id:int,tipo:string,cantidad:int}>  $productos
     * @return array<string,mixed>
     */
    private function buildPayload(string $token, string $baseUrl, User $user, MeliOrder $order, array $productos): array
    {
        $metodoPago = trim((string) config('syscom.orders_from_meli.metodo_pago_id', ''));
        $paymentLabel = '';
        $paymentCodigoSat = null;
        $usoCfdi = trim((string) config('syscom.orders_from_meli.uso_cfdi_id', ''));
        $fletera = trim((string) config('syscom.orders_from_meli.fletera_id', ''));
        $sucursalEntrega = trim((string) config('syscom.orders_from_meli.sucursal_codigo', ''));
        $atencionA = trim((string) config('syscom.orders_from_meli.atencion_a', 'sucursal'));

        if ($metodoPago === '') {
            $resolved = $this->resolvePaymentForOrder($token, $baseUrl . '/carrito/pago');
            $metodoPago = $resolved['metodo_pago'];
            $paymentLabel = $resolved['label'];
            $paymentCodigoSat = $resolved['codigo_sat'];
        }
        if ($usoCfdi === '') {
            $usoCfdi = $this->resolveFirstId($token, $baseUrl . '/carrito/cfdi');
        }

        $branchName = trim((string) config('syscom.sucursal_nombre', 'hermosillo'));

        if ($sucursalEntrega === '') {
            $sucursalEntrega = $this->resolveOrderBranchCode($token, $branchName);
        }

        if ($atencionA === '') {
            $atencionA = 'sucursal';
        }

        $payload = [
            'tipo_entrega' => 'sucursal',
            'direccion' => [
                'atencion_a' => $atencionA,
                'sucursal' => $sucursalEntrega,
                'codigo_sucursal' => $sucursalEntrega,
            ],
            'metodo_pago' => $metodoPago,
            'productos' => $productos,
            'moneda' => 'mxn',
            'uso_cfdi' => $usoCfdi !== '' ? $usoCfdi : 'G03',
            'tipo_pago' => (string) config('syscom.orders_from_meli.tipo_pago', 'pue'),
            'orden_compra' => 'ML-' . $order->order_id,
            'ordenar' => true,
            'forzar' => (bool) config('syscom.orders_from_meli.forzar', false),
            'testmode' => (bool) config('syscom.orders_from_meli.testmode', false),
            'directo_cliente' => (bool) config('syscom.orders_from_meli.directo_cliente', false),
        ];

        if (
            $fletera !== ''
            && (bool) config('syscom.orders_from_meli.send_fletera_with_sucursal', false)
        ) {
            $payload['fletera'] = $fletera;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $payload['email'] = $email;
        }

        if ($metodoPago === '') {
            throw new \RuntimeException(
                'SYSCOM: no se pudo resolver metodo_pago (ID forma.pue). Ejecutá php artisan syscom:order-pago-methods '.
                'y configurá SYSCOM_ORDER_METODO_PAGO_ID con el valor metodo_pago_si_pue de tarjeta en sucursal (no codigo_sat=04).'
            );
        }

        Log::info('ML->SYSCOM payload pago', [
            'metodo_pago' => $metodoPago,
            'tipo_pago' => $payload['tipo_pago'],
            'codigo_sat_esperado' => $paymentCodigoSat,
            'label' => $paymentLabel,
        ]);

        return $payload;
    }

    /**
     * Código de sucursal de surtido para el pedido. Resuelve el código numérico real de la
     * sucursal local (Hermosillo) vía GET /carrito/sucursales y lo cachea, igual que el catálogo.
     * Así el pedido apunta siempre a la misma sucursal y SYSCOM no usa su sucursal por defecto.
     * Si no se puede resolver (o está desactivado), cae al nombre como texto.
     */
    private function resolveOrderBranchCode(string $token, string $branchName): string
    {
        $branchName = trim($branchName) !== '' ? trim($branchName) : 'hermosillo';

        if (! (bool) config('syscom.orders_from_meli.resolve_branch_code', true)) {
            return $branchName;
        }

        $cacheKey = 'syscom:order-branch-code:' . mb_strtolower($branchName);

        $code = Cache::remember($cacheKey, now()->addHours(12), function () use ($token, $branchName) {
            try {
                return app(SyscomApiService::class)->resolveBranchCodeByName($token, $branchName);
            } catch (\Throwable $e) {
                Log::warning('ML->SYSCOM: no se pudo resolver código de sucursal', [
                    'sucursal' => $branchName,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });

        $code = trim((string) $code);

        if ($code === '') {
            Cache::forget($cacheKey);

            return $branchName;
        }

        return $code;
    }

    /**
     * GET /carrito/pago → metodo_pago (forma.pue|ppd) + codigo_sat para el PDF.
     *
     * @return array{metodo_pago: string, codigo_sat: ?string, label: string}
     */
    private function resolvePaymentForOrder(string $token, string $url): array
    {
        $empty = ['metodo_pago' => '', 'codigo_sat' => null, 'label' => ''];

        try {
            $resp = Http::withToken($token)->acceptJson()->timeout(30)->get($url);
            if (! $resp->successful()) {
                return $empty;
            }
            $tipoPago = (string) config('syscom.orders_from_meli.tipo_pago', 'pue');
            $prefer = trim((string) config('syscom.orders_from_meli.metodo_pago_prefer', 'sucursal+tarjeta+credito'));

            return SyscomCarritoPagoHelper::resolvePaymentForOrder(
                $resp->json(),
                $tipoPago,
                $prefer !== '' ? $prefer : null
            );
        } catch (\Throwable) {
        }

        return $empty;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function flattenRowToSearchableLabel(array $row): string
    {
        $parts = [];
        foreach ($row as $k => $v) {
            if (in_array((string) $k, ['id', 'codigo', 'code'], true)) {
                continue;
            }
            if (is_string($v) && trim($v) !== '') {
                $parts[] = $v;
            } elseif (is_array($v)) {
                $parts[] = $this->flattenRowToSearchableLabel($v);
            }
        }

        $s = mb_strtolower(implode(' ', $parts), 'UTF-8');

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $s
        );
    }

    private function resolveFirstId(string $token, string $url): string
    {
        try {
            $resp = Http::withToken($token)->acceptJson()->timeout(30)->get($url);
            if (! $resp->successful()) {
                return '';
            }
            $json = $resp->json();
            if (! is_array($json)) {
                return '';
            }
            $rows = array_is_list($json) ? $json : array_values($json);
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (['id', 'codigo', 'code'] as $k) {
                    $v = trim((string) ($row[$k] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveSyscomIdFromOrderItem(int $userId, string $sku, string $itemId): ?int
    {
        $id = $this->extractSyscomIdFromSku($sku);
        if ($id) {
            return $id;
        }

        $itemId = trim($itemId);
        if ($itemId === '') {
            return null;
        }

        $pubSku = (string) (MeliPublication::query()
            ->where('user_id', $userId)
            ->where('mlm', $itemId)
            ->orderByDesc('id')
            ->value('sku') ?? '');

        return $this->extractSyscomIdFromSku($pubSku);
    }

    private function extractSyscomIdFromSku(string $sku): ?int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $prefix = (string) config('syscom.sku_prefix', 'SYSCOM');
        // Acepta SKU base (SYSCOM-12345) y variantes compuestas (SYSCOM-12345-2 / -4).
        $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)(?:-(?:2|4))?$/i';
        if (preg_match($pattern, $sku, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}

