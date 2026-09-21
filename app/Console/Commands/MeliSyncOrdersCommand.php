<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Models\User;
use App\Services\MeliOrderSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MeliSyncOrdersCommand extends Command
{
    protected $signature = 'meli:sync-orders
                            {--user_id= : ID del usuario}
                            {--email= : Email del usuario}
                            {--account_id= : ID de una cuenta Mercado Libre}
                            {--all-accounts : Sincroniza todas las cuentas registradas con token}
                            {--date= : Fecha YYYY-MM-DD}
                            {--from= : Fecha inicial YYYY-MM-DD}
                            {--to= : Fecha final YYYY-MM-DD}
                            {--days= : Cantidad de días hacia atrás, incluyendo hoy}
                            {--today : Sincroniza hoy}';

    protected $description = 'Sincroniza órdenes de todas las cuentas Mercado Libre y las guarda en meli_orders';

    public function handle(MeliOrderSyncService $service): int
    {
        $userId = $this->option('user_id');
        $email = $this->option('email');
        $accountId = $this->option('account_id');
        $allAccounts = (bool) $this->option('all-accounts');

        if ($allAccounts && ($accountId || $userId || $email)) {
            $this->error('--all-accounts no se combina con --account_id, --user_id o --email.');

            return self::FAILURE;
        }

        if ($accountId && ($userId || $email)) {
            $this->error('--account_id no se combina con --user_id o --email.');

            return self::FAILURE;
        }

        $users = collect();
        if ($allAccounts || $accountId) {
            $users = MeliAccount::query()
                ->with('user')
                ->whereNotNull('access_token')
                ->when($accountId, fn ($query) => $query->whereKey((int) $accountId))
                ->orderBy('id')
                ->get()
                ->filter(fn (MeliAccount $account): bool => $account->user !== null)
                ->map(fn (MeliAccount $account): User => $this->apiUser($account));
        } else {
            $user = $userId
                ? User::find($userId)
                : ($email ? User::where('email', $email)->first() : null);

            if ($user) {
                $users->push($user);
            }
        }

        if ($users->isEmpty()) {
            $this->error('No se encontraron cuentas. Usa --all-accounts, --account_id, --user_id o --email.');

            return self::FAILURE;
        }

        if ($users->contains(fn (User $user): bool => empty($user->access_token))) {
            $this->error('Una de las cuentas seleccionadas no tiene access_token.');

            return self::FAILURE;
        }

        try {
            $dates = $this->resolveDatesToSync(
                $this->option('date'),
                $this->option('from'),
                $this->option('to'),
                $this->option('days'),
                (bool) $this->option('today')
            );
            $totalOrders = 0;
            $totalItems = 0;
            $totalFailed = 0;
            $sellerId = null;

            foreach ($users as $user) {
                foreach ($dates as $syncDate) {
                    $result = $service->syncDay($user, $syncDate);
                    $sellerId = $result['seller_id'];
                    $totalOrders += (int) $result['orders'];
                    $totalItems += (int) $result['items'];
                    $totalFailed += (int) ($result['failed'] ?? 0);

                    $this->line(sprintf(
                        'Cuenta %d · %s: %d órdenes, %d items, %d errores.',
                        $result['meli_account_id'],
                        $result['date'],
                        $result['orders'],
                        $result['items'],
                        $result['failed'] ?? 0,
                    ));
                }
            }

            $this->info('Sincronización completada.');
            $this->line('Cuentas procesadas: '.$users->count());
            $this->line('Fechas procesadas por cuenta: '.count($dates));
            $this->line('Último seller ID: '.($sellerId ?? '—'));
            $this->line('Órdenes guardadas: '.$totalOrders);
            $this->line('Items guardados: '.$totalItems);
            $this->line('Errores aislados: '.$totalFailed);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Error al sincronizar órdenes: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function resolveDatesToSync(mixed $date, mixed $from, mixed $to, mixed $days, bool $today): array
    {
        if ($date && ($from || $to || $days)) {
            throw new \InvalidArgumentException('Usa sólo --date o un rango (--from/--to/--days), no ambos.');
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

    /** @return list<string> */
    private function dateRangeDescending(Carbon $start, Carbon $end): array
    {
        $dates = [];
        for ($cursor = $end->copy(); $cursor->greaterThanOrEqualTo($start); $cursor->subDay()) {
            $dates[] = $cursor->toDateString();
        }

        return $dates;
    }

    private function apiUser(MeliAccount $account): User
    {
        $owner = $account->user;
        $user = $owner->replicate();
        $user->forceFill([
            'id' => $owner->id,
            'meli_id' => $account->meli_user_id,
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_at' => $account->expires_at,
        ]);
        $user->exists = true;

        return $user;
    }
}
