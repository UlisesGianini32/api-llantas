<?php

namespace App\Console\Commands;

use App\Models\InventoryChannelLink;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class InventoryRolloutCheckCommand extends Command
{
    protected $signature = 'inventory:rollout-check';

    protected $description = 'Comprueba de forma read-only que Inventory está listo para un rollout seguro';

    /** @var list<string> */
    private const INVENTORY_MIGRATIONS = [
        '2026_09_24_000001_create_inventory_products_table',
        '2026_09_24_000002_create_inventory_locations_table',
        '2026_09_24_000003_add_primary_location_id_to_inventory_products_table',
        '2026_09_24_000004_create_inventory_movements_table',
        '2026_09_24_000005_create_inventory_reservations_table',
        '2026_09_24_000006_add_product_type_to_inventory_products_table',
        '2026_09_24_000007_create_inventory_kit_components_table',
        '2026_09_24_000008_create_inventory_kit_reservations_table',
        '2026_09_25_000001_create_inventory_channel_links_table',
        '2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links',
        '2026_09_25_000003_create_inventory_channel_stock_syncs_table',
        '2026_09_25_000004_add_verification_to_inventory_channel_stock_syncs',
    ];

    /** @var array<string,string> */
    private const INVENTORY_TABLES = [
        'inventory_products' => 'productos',
        'inventory_locations' => 'locations',
        'inventory_movements' => 'movimientos',
        'inventory_reservations' => 'reservas',
        'inventory_kit_components' => 'componentes de kits',
        'inventory_kit_reservations' => 'reservas de kits',
        'inventory_channel_links' => 'channel links',
        'inventory_channel_stock_syncs' => 'auditoría de stock ML',
    ];

    public function handle(): int
    {
        $checks = [];
        $failures = 0;
        $warnings = 0;

        $this->check($checks, $failures, $warnings, 'database', function (): string {
            DB::connection()->getPdo();

            return 'conexión disponible';
        }, true);

        foreach (self::INVENTORY_TABLES as $table => $label) {
            $this->check($checks, $failures, $warnings, 'table:'.$table, function () use ($table, $label): string {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException("falta {$label}");
                }

                return 'presente';
            }, true);
        }

        $this->check($checks, $failures, $warnings, 'migrations', function (): string {
            if (! Schema::hasTable('migrations')) {
                throw new \RuntimeException('no existe el repositorio de migraciones');
            }

            $applied = DB::table('migrations')
                ->whereIn('migration', self::INVENTORY_MIGRATIONS)
                ->pluck('migration')
                ->all();
            $missing = array_values(array_diff(self::INVENTORY_MIGRATIONS, $applied));
            if ($missing !== []) {
                throw new \RuntimeException('faltan: '.implode(', ', $missing));
            }

            return count($applied).' migraciones Inventory aplicadas';
        }, true);

        foreach (['stock_sync_enabled', 'identity_key'] as $column) {
            $this->check($checks, $failures, $warnings, 'column:inventory_channel_links.'.$column, function () use ($column): string {
                if (! Schema::hasColumn('inventory_channel_links', $column)) {
                    throw new \RuntimeException('columna ausente');
                }

                return 'presente';
            }, true);
        }
        foreach (['verified_quantity', 'verification_status', 'verified_at'] as $column) {
            $this->check($checks, $failures, $warnings, 'column:inventory_channel_stock_syncs.'.$column, function () use ($column): string {
                if (! Schema::hasColumn('inventory_channel_stock_syncs', $column)) {
                    throw new \RuntimeException('columna ausente');
                }

                return 'presente';
            }, true);
        }

        $this->check($checks, $failures, $warnings, 'counts', function () use (&$checks): string {
            $counts = [];
            foreach (['inventory_products', 'inventory_locations', 'inventory_channel_links'] as $table) {
                $counts[$table] = DB::table($table)->count();
            }
            $counts['meli_links'] = DB::table('inventory_channel_links')
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->count();
            $checks['counts_detail'] = [
                'products' => $counts['inventory_products'],
                'locations' => $counts['inventory_locations'],
                'channel_links' => $counts['inventory_channel_links'],
                'meli_links' => $counts['meli_links'],
            ];

            return sprintf(
                'products=%d locations=%d channel_links=%d meli_links=%d',
                $counts['inventory_products'],
                $counts['inventory_locations'],
                $counts['inventory_channel_links'],
                $counts['meli_links'],
            );
        }, true);

        $linkSchemaReady = false;

        if (Schema::hasTable('inventory_channel_links')) {
            $requiredLinkColumns = ['channel', 'account_key', 'is_active', 'stock_sync_enabled'];

            $this->check($checks, $failures, $warnings, 'channel-link-columns', function () use ($requiredLinkColumns, &$linkSchemaReady): string {
                $missingColumns = array_values(array_filter(
                    $requiredLinkColumns,
                    fn (string $column): bool => ! Schema::hasColumn('inventory_channel_links', $column),
                ));

                if ($missingColumns !== []) {
                    throw new \RuntimeException('faltan columnas: '.implode(', ', $missingColumns));
                }

                $linkSchemaReady = true;

                return 'schema listo para lectura segura';
            }, true);
        }

        if ($linkSchemaReady) {
            $accountsTableAvailable = Schema::hasTable('meli_accounts');
            $links = InventoryChannelLink::query()
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->get(['id', 'account_key', 'is_active', 'stock_sync_enabled']);
            $enabled = $links->where('stock_sync_enabled', true);

            $this->check($checks, $failures, $warnings, 'stock_sync_enabled', function () use ($enabled): string {
                if ($enabled->isNotEmpty()) {
                    throw new \RuntimeException($enabled->count().' vinculo(s) habilitado(s) inesperadamente');
                }

                return '0 vinculos habilitados';
            }, true);

            foreach ($links as $link) {
                $hasAccount = $accountsTableAvailable
                    && ctype_digit((string) $link->account_key)
                    && DB::table('meli_accounts')->where('id', (int) $link->account_key)->exists();

                $level = $link->stock_sync_enabled ? 'FAIL' : 'WARN';
                $message = $hasAccount ? 'cuenta valida' : 'cuenta ML referenciada ausente';

                $this->addCheck($checks, 'link:'.$link->id, $hasAccount ? 'PASS' : $level, $message);

                if (! $hasAccount && $link->stock_sync_enabled) {
                    $failures++;
                } elseif (! $hasAccount) {
                    $warnings++;
                }
            }
        }

        $this->check($checks, $failures, $warnings, 'scheduler', function (): string {
            $inventoryCommands = [
                'inventory:meli-stock-sync',
                'inventory:meli-stock-pilot',
            ];

            $dangerous = [];

            foreach (app(Schedule::class)->events() as $event) {
                $command = (string) ($event->command ?? '');

                if ($command === '' || ! str_contains($command, '--apply')) {
                    continue;
                }

                foreach ($inventoryCommands as $inventoryCommand) {
                    if (str_contains($command, $inventoryCommand)) {
                        $dangerous[] = $inventoryCommand;
                    }
                }
            }

            $dangerous = array_values(array_unique($dangerous));

            if ($dangerous !== []) {
                throw new \RuntimeException('scheduler peligroso: '.implode(', ', $dangerous));
            }

            return 'sin APPLY automatico en scheduler';
        }, true);

        $this->check($checks, $failures, $warnings, 'cache', function (): string {
            $driver = (string) config('cache.default', '');
            if ($driver === '') {
                throw new \RuntimeException('driver no configurado');
            }
            app('cache')->store()->get('__inventory_rollout_check_probe__');

            return 'driver='.$driver.'; lectura disponible; no se adquirió ningún lock';
        }, false);

        $state = $failures > 0 ? 'FAIL' : ($warnings > 0 ? 'WARN' : 'PASS');
        $this->line("Inventory rollout check: {$state}");
        $this->table(['Check', 'Estado', 'Detalle'], array_map(
            fn (array $check): array => [$check['name'], $check['status'], $check['message']],
            array_values(array_filter($checks, fn (mixed $value): bool => is_array($value) && isset($value['name']))),
        ));

        if ($this->output->isVerbose() && isset($checks['counts_detail'])) {
            $this->line('Detalle de conteos: '.json_encode($checks['counts_detail'], JSON_UNESCAPED_UNICODE));
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,mixed> $checks */
    private function check(array &$checks, int &$failures, int &$warnings, string $name, callable $callback, bool $fatal): void
    {
        try {
            $this->addCheck($checks, $name, 'PASS', $callback());
        } catch (Throwable $exception) {
            $this->addCheck($checks, $name, $fatal ? 'FAIL' : 'WARN', $exception->getMessage());
            $fatal ? $failures++ : $warnings++;
        }
    }

    /** @param array<string,mixed> $checks */
    private function addCheck(array &$checks, string $name, string $status, string $message): void
    {
        $checks[] = ['name' => $name, 'status' => $status, 'message' => $message];
    }
}
