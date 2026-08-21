<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Services\MeliQuestionService;
use Illuminate\Console\Command;

class MeliSyncQuestions extends Command
{
    protected $signature = 'meli:sync-questions {--account_id=} {--pages=4}';

    protected $description = 'Consulta y guarda preguntas de productos de las cuentas de Mercado Libre';

    public function handle(MeliQuestionService $service): int
    {
        $query = MeliAccount::query()
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id');

        if ($this->option('account_id')) {
            $query->whereKey((int) $this->option('account_id'));
        }

        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            $this->warn('No hay cuentas vinculadas para sincronizar.');

            return self::SUCCESS;
        }

        $pages = max(1, min(20, (int) $this->option('pages')));
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $result = $service->syncAccount($account, $pages);
                $label = filled($account->nickname)
                    ? $account->nickname
                    : $account->meli_user_id;
                $this->info(sprintf(
                    '%s: %d preguntas sincronizadas (total remoto: %d).',
                    $label,
                    $result['saved'],
                    $result['total']
                ));
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->error('Cuenta '.$account->id.': '.$e->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
