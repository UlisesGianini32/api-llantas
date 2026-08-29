<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use Illuminate\Console\Command;
use Throwable;

class SyncMeliClaimsCommand extends Command
{
    protected $signature = 'meli:sync-claims {--account=} {--status=} {--claim=} {--days=30} {--force}';

    protected $description = 'Sincroniza reclamos de Mercado Libre en modo de solo lectura';

    public function handle(MeliClaimsService $service): int
    {
        $query = MeliAccount::query()->whereNotNull('access_token');
        if ($this->option('account')) $query->whereKey((int) $this->option('account'));
        $failed = 0;

        foreach ($query->cursor() as $account) {
            try {
                if ($this->option('claim')) {
                    $service->syncClaim($account, (string) $this->option('claim'), (bool) $this->option('force'));
                    $this->info("Cuenta {$account->id}: reclamo sincronizado.");
                } else {
                    $result = $service->syncAccount($account, $this->option('status'), max(0, (int) $this->option('days')), (bool) $this->option('force'));
                    $this->info("Cuenta {$account->id}: {$result['saved']} guardados, {$result['failed']} fallidos.");
                    $failed += $result['failed'];
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("Cuenta {$account->id}: ".$e->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
