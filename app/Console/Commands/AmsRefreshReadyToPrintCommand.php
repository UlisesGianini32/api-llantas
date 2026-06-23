<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MeliOrderSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Refresca contra MeLi (GET /shipments/{id}) todas las órdenes que en BD figuran
 * como "ready_to_print" (efectivo). Ese estado es justo el que ML usa para el filtro
 * "Etiqueta lista para imprimir": si una orden vieja cambió en ML a printed/picked_up/
 * shipped y nuestro sync por fecha de creación no la volvió a tocar, se queda colgada
 * en el listado AMS. Este comando alinea el estado.
 */
class AmsRefreshReadyToPrintCommand extends Command
{
    protected $signature = 'ams:refresh-ready-to-print
                            {--user_id= : ID del usuario a usar para el token (default: el primero con access_token)}
                            {--max=300 : Tope de shipping_ids a refrescar por corrida}';

    protected $description = 'Refresca shipments ready_to_print contra ML para que el panel AMS no muestre fantasmas.';

    public function handle(MeliOrderSyncService $sync): int
    {
        $userId = $this->option('user_id');
        $user = $userId
            ? User::query()->whereKey((int) $userId)->whereNotNull('access_token')->first()
            : User::query()->whereNotNull('access_token')->orderBy('id')->first();

        if (! $user) {
            $this->error('No hay usuario con access_token de ML.');

            return self::FAILURE;
        }

        $effSt = "LOWER(TRIM(COALESCE(
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meli_orders.shipping_raw, '$.status')), '')), ''),
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meli_orders.raw, '$.shipping.status')), '')), ''),
            NULLIF(TRIM(COALESCE(meli_orders.shipping_status, '')), '')
        )))";

        $effSub = "LOWER(TRIM(COALESCE(
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meli_orders.shipping_raw, '$.substatus')), '')), ''),
            NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meli_orders.raw, '$.shipping.substatus')), '')), ''),
            NULLIF(TRIM(COALESCE(meli_orders.shipping_substatus, '')), '')
        )))";

        $max = max(1, (int) $this->option('max'));

        $ids = DB::table('meli_orders')
            ->whereNotNull('shipping_id')
            ->where('shipping_id', '!=', '')
            ->whereRaw("LOWER(COALESCE(meli_orders.status, '')) = 'paid'")
            ->whereRaw("({$effSt}) = 'ready_to_ship'")
            ->whereRaw("({$effSub}) = 'ready_to_print'")
            ->orderByDesc('created_at')
            ->limit($max)
            ->pluck('shipping_id')
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            $this->info('No hay shipments ready_to_print por refrescar.');

            return self::SUCCESS;
        }

        $this->info('Refrescando '.count($ids).' shipping_ids...');
        $touched = $sync->refreshShipmentsByShippingIds($user, $ids);
        $this->info("Filas meli_orders tocadas: {$touched}");

        return self::SUCCESS;
    }
}
