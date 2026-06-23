<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Llanta;
use App\Models\ProductoCompuesto;
use App\Models\MeliPublication;
use App\Services\MeliApi; // si ya tienes uno, úsalo; si no, abajo te digo alternativa
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeliItemNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->onQueue('meli');
    }

    public function handle()
    {
        $topic = (string)($this->payload['topic'] ?? '');
        $resource = (string)($this->payload['resource'] ?? '');

        if ($topic !== 'items' || !preg_match('#^/items/(MLM\d+)#', $resource, $m)) {
            Log::info('MELI ITEM JOB: ignorado', ['topic'=>$topic,'resource'=>$resource]);
            return;
        }

        $mlm = $m[1];

        // toma un usuario con token válido
        $user = User::whereNotNull('access_token')->orderByDesc('id')->first();
        if (!$user) {
            throw new \RuntimeException('No hay usuario con access_token para consultar items.');
        }

        // =============================
        // 1) Leer item desde ML
        // =============================
        // Si ya tienes servicio para GET, úsalo.
        // Si NO, usa Guzzle directo (como tu prueba):
        $client = new \GuzzleHttp\Client(['base_uri' => 'https://api.mercadolibre.com', 'timeout' => 20]);

        $res = $client->get("/items/{$mlm}", [
            'headers' => [
                'Authorization' => 'Bearer '.$user->access_token,
                'Accept' => 'application/json',
            ],
        ]);

        $item = json_decode((string)$res->getBody(), true);

        $available = (int)($item['available_quantity'] ?? 0);

        // 2) Sacar SKU desde attributes SELLER_SKU
        $sku = null;
        foreach (($item['attributes'] ?? []) as $attr) {
            if (($attr['id'] ?? '') === 'SELLER_SKU') {
                $sku = trim((string)($attr['value_name'] ?? ''));
                break;
            }
        }

        if (!$sku) {
            // fallback: intenta mapear por tu tabla meli_publications
            $pub = MeliPublication::where('MLM', $mlm)->first();
            $sku = $pub?->sku;
        }

        if (!$sku) {
            Log::warning('MELI ITEM JOB: no pude resolver SKU', ['mlm'=>$mlm, 'available'=>$available]);
            return;
        }

        // =============================
        // 3) Actualizar tu BD según SKU
        // =============================
        $updated = false;

        // Si el SKU es compuesto (termina en -2 o -4)
        if (preg_match('/-(2|4)$/', $sku)) {
            $comp = ProductoCompuesto::where('sku', $sku)->first();
            if ($comp) {
                // aquí tú decides si guardas stock en compuestos o solo en llanta base
                $comp->stock = $available;
                $comp->save();
                $updated = true;
            }
        } else {
            $llanta = Llanta::where('sku', $sku)->first();
            if ($llanta) {
                $llanta->stock = $available;
                $llanta->save();
                $updated = true;
            }
        }

        // También actualiza tabla de publicaciones si la usas
        MeliPublication::where('MLM', $mlm)->update([
            'available_quantity' => $available,
            'last_sync_at' => now(),
        ]);

        Log::info('MELI ITEM JOB: actualizado', [
            'mlm' => $mlm,
            'sku' => $sku,
            'available_quantity' => $available,
            'updated' => $updated,
        ]);
    }
}