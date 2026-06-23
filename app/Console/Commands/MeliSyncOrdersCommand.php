<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MeliOrderSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MeliSyncOrdersCommand extends Command
{
    protected $signature = 'meli:sync-orders
                            {--user_id= : ID del usuario}
                            {--email= : Email del usuario}
                            {--date= : Fecha YYYY-MM-DD}
                            {--from= : Fecha inicial YYYY-MM-DD}
                            {--to= : Fecha final YYYY-MM-DD}
                            {--days= : Cantidad de días hacia atrás, incluyendo hoy}
                            {--today : Sincroniza hoy}';

    protected $description = 'Sincroniza órdenes de Mercado Libre y las guarda en meli_orders y meli_order_items';

    public function handle(MeliOrderSyncService $service): int
    {
        $userId = $this->option('user_id');
        $email = $this->option('email');
        $today = (bool) $this->option('today');
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $days = $this->option('days');

        $user = null;

        if ($userId) {
            $user = User::find($userId);
        } elseif ($email) {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            $this->error('No se encontró el usuario. Usa --user_id= o --email=');
            return self::FAILURE;
        }

        if (!$user->access_token) {
            $this->error('El usuario no tiene access_token.');
            return self::FAILURE;
        }

        try {
            $dates = $this->resolveDatesToSync($date, $from, $to, $days, $today);
            $totalOrders = 0;
            $totalItems = 0;
            $sellerId = null;

            foreach ($dates as $syncDate) {
                $result = $service->syncDay($user, $syncDate);
                $sellerId = $result['seller_id'];
                $totalOrders += (int) $result['orders'];
                $totalItems += (int) $result['items'];

                $this->line("{$result['date']}: {$result['orders']} órdenes, {$result['items']} items");
            }

            $this->info('Sincronización completada.');
            $this->line('Fechas procesadas: ' . count($dates));
            $this->line('Seller ID: ' . ($sellerId ?? '—'));
            $this->line('Órdenes guardadas: ' . $totalOrders);
            $this->line('Items guardados: ' . $totalItems);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error al sincronizar órdenes: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveDatesToSync(mixed $date, mixed $from, mixed $to, mixed $days, bool $today): array
    {
        if ($date && ($from || $to || $days)) {
            throw new \InvalidArgumentException('Usa solo --date o un rango (--from/--to/--days), no ambos.');
        }

        if ($days !== null && $days !== '') {
            $daysInt = (int) $days;
            if ($daysInt < 1) {
                throw new \InvalidArgumentException('--days debe ser mayor a 0.');
            }

            $end = now()->startOfDay();
            $start = $end->copy()->subDays($daysInt - 1);

            return $this->dateRangeDescending($start, $end);
        }

        if ($from || $to) {
            $start = Carbon::parse($from ?: $to)->startOfDay();
            $end = Carbon::parse($to ?: $from)->startOfDay();

            if ($start->greaterThan($end)) {
                throw new \InvalidArgumentException('--from no puede ser mayor que --to.');
            }

            return $this->dateRangeDescending($start, $end);
        }

        $singleDate = $date ?: ($today ? now()->toDateString() : now()->toDateString());

        return [Carbon::parse($singleDate)->toDateString()];
    }

    /**
     * @return list<string>
     */
    private function dateRangeDescending(Carbon $start, Carbon $end): array
    {
        $dates = [];
        for ($cursor = $end->copy(); $cursor->greaterThanOrEqualTo($start); $cursor->subDay()) {
            $dates[] = $cursor->toDateString();
        }

        return $dates;
    }
}