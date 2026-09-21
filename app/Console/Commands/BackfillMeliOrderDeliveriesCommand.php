<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Models\User;
use App\Services\MeliOrderSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillMeliOrderDeliveriesCommand extends Command
{
    protected $signature = 'meli:backfill-order-deliveries
                            {--account= : ID de una cuenta Mercado Libre}
                            {--all : Procesar todas las cuentas con token}
                            {--from= : Fecha inicial YYYY-MM-DD}
                            {--to= : Fecha final YYYY-MM-DD}
                            {--days= : Cantidad acotada de días hacia atrás}
                            {--mode=normal : normal o incremental}';

    protected $description = 'Recupera órdenes y recalcula su tipo de entrega por cuenta Mercado Libre';

    public function handle(MeliOrderSyncService $orders): int
    {
        $accountId = $this->option('account');
        $all = (bool) $this->option('all');
        $mode = strtolower(trim((string) $this->option('mode')));

        if (($accountId && $all) || (! $accountId && ! $all)) {
            $this->error('Usa exactamente una opción: --account=ID o --all.');

            return self::FAILURE;
        }

        if (! in_array($mode, ['normal', 'incremental'], true)) {
            $this->error('--mode debe ser normal o incremental.');

            return self::FAILURE;
        }

        try {
            $dates = $this->dates($mode);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $accounts = MeliAccount::query()
            ->with('user')
            ->whereNotNull('access_token')
            ->when($accountId, fn ($query) => $query->whereKey((int) $accountId))
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('No se encontraron cuentas Mercado Libre con token para procesar.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($accounts as $account) {
            if (! $account->user) {
                $failed++;
                $this->warn("Cuenta {$account->id}: propietario local no encontrado.");

                continue;
            }

            $apiUser = $this->apiUser($account->user, $account);
            foreach ($dates as $date) {
                try {
                    $result = $orders->syncDay($apiUser, $date);
                    $this->line(sprintf(
                        'Cuenta %d · %s: %d órdenes, %d items, %d errores.',
                        $account->id,
                        $date,
                        $result['orders'],
                        $result['items'],
                        $result['failed'] ?? 0,
                    ));
                    $failed += (int) ($result['failed'] ?? 0);
                } catch (\Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->warn("Cuenta {$account->id} · {$date}: {$exception->getMessage()}");
                }
            }
        }

        $this->info("Backfill terminado. Errores: {$failed}.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function dates(string $mode): array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $days = $this->option('days');

        if ($days && ($from || $to)) {
            throw new \InvalidArgumentException('Usa --days o --from/--to, no ambos.');
        }

        if ($days !== null && $days !== '') {
            $days = (int) $days;
            if ($days < 1 || $days > 365) {
                throw new \InvalidArgumentException('--days debe estar entre 1 y 365.');
            }

            $end = now()->startOfDay();
            $start = $end->copy()->subDays($days - 1);
        } elseif ($from || $to) {
            $start = Carbon::parse($from ?: $to)->startOfDay();
            $end = Carbon::parse($to ?: $from)->startOfDay();
            if ($start->greaterThan($end)) {
                throw new \InvalidArgumentException('--from no puede ser mayor que --to.');
            }
            if ($start->diffInDays($end) > 365) {
                throw new \InvalidArgumentException('El rango no puede exceder 365 días.');
            }
        } else {
            $end = now()->startOfDay();
            $start = $end->copy()->subDays($mode === 'incremental' ? 1 : 29);
        }

        $dates = [];
        for ($cursor = $end->copy(); $cursor->greaterThanOrEqualTo($start); $cursor->subDay()) {
            $dates[] = $cursor->toDateString();
        }

        return $dates;
    }

    private function apiUser(User $owner, MeliAccount $account): User
    {
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
