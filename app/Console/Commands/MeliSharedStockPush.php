<?php

namespace App\Console\Commands;

use App\Jobs\PushMeliSharedStockGroupJob;
use App\Models\MeliSharedStockGroup;
use App\Services\MeliSharedStockPushService;
use Illuminate\Console\Command;

class MeliSharedStockPush extends Command
{
    protected $signature = 'meli:shared-stock-push
        {--group= : ID de un grupo específico}
        {--master=1 : Cuenta maestra}
        {--sync : Ejecutar directamente en esta terminal en lugar de usar cola}';

    protected $description = 'Envía el stock maestro a todas las publicaciones conectadas';

    public function handle(MeliSharedStockPushService $service): int
    {
        $query = MeliSharedStockGroup::query()
            ->where('is_enabled', true)
            ->where('master_account_id', max(1, (int) $this->option('master')));

        if ($this->option('group')) {
            $query->whereKey((int) $this->option('group'));
        }

        $groups = $query->orderBy('id')->get();
        $updated = $skipped = $errors = 0;

        foreach ($groups as $group) {
            if ($this->option('sync')) {
                $result = $service->pushGroup($group);
                $updated += $result['updated'];
                $skipped += $result['skipped'];
                $errors += $result['errors'];
                $this->line("Grupo {$group->id}: actualizadas {$result['updated']}, omitidas {$result['skipped']}, errores {$result['errors']}");
            } else {
                PushMeliSharedStockGroupJob::dispatch((int) $group->id)->onQueue('meli');
            }
        }

        if (! $this->option('sync')) {
            $this->info("Se enviaron {$groups->count()} grupos a la cola meli.");
        } else {
            $this->table(['Actualizadas', 'Omitidas', 'Errores'], [[$updated, $skipped, $errors]]);
        }

        return self::SUCCESS;
    }
}
