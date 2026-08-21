<?php

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;

class SystemHeartbeatCommand extends Command
{
    protected $signature = 'system:heartbeat';
    protected $description = 'Registra que el scheduler de Laravel se ejecutó correctamente';

    public function handle(): int
    {
        SystemHeartbeat::query()->updateOrCreate(
            ['name' => 'scheduler'],
            ['ran_at' => now(), 'meta' => ['hostname' => gethostname() ?: null, 'php_version' => PHP_VERSION]],
        );

        $this->info('Heartbeat del scheduler registrado.');
        return self::SUCCESS;
    }
}
