<?php

namespace App\Console\Commands;

use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use App\Models\MeliSharedStockMovement;
use Illuminate\Console\Command;

class MeliSharedStockStatus extends Command
{
    protected $signature = 'meli:shared-stock-status
        {--master=1 : Cuenta maestra}
        {--sku= : Filtrar por SKU}
        {--limit=20 : Grupos de muestra}';

    protected $description = 'Muestra el estado de los grupos de stock compartido';

    public function handle(): int
    {
        $masterId = max(1, (int) $this->option('master'));
        $groups = MeliSharedStockGroup::query()->where('master_account_id', $masterId);

        if ($this->option('sku')) {
            $groups->where('sku', 'like', '%'.trim((string) $this->option('sku')).'%');
        }

        $groupIds = (clone $groups)->pluck('id');

        $this->table(['Concepto', 'Cantidad'], [
            ['Grupos activos', (clone $groups)->where('is_enabled', true)->count()],
            ['Grupos deshabilitados', (clone $groups)->where('is_enabled', false)->count()],
            ['Stock total maestro', (clone $groups)->where('is_enabled', true)->sum('stock')],
            ['Miembros cuenta 1', MeliSharedStockMember::query()->whereIn('group_id', $groupIds)->where('role', 'master')->where('is_active', true)->count()],
            ['Miembros espejo', MeliSharedStockMember::query()->whereIn('group_id', $groupIds)->where('role', 'mirror')->where('is_active', true)->count()],
            ['Miembros con error', MeliSharedStockMember::query()->whereIn('group_id', $groupIds)->where('last_push_status', 'error')->count()],
            ['Movimientos de venta', MeliSharedStockMovement::query()->whereIn('group_id', $groupIds)->where('type', 'meli_order')->count()],
        ]);

        $sample = (clone $groups)
            ->withCount([
                'members as master_members_count' => fn ($query) => $query->where('is_active', true)->where('role', 'master'),
                'members as mirror_members_count' => fn ($query) => $query->where('is_active', true)->where('role', 'mirror'),
                'members as error_members_count' => fn ($query) => $query->where('last_push_status', 'error'),
            ])
            ->orderByDesc('updated_at')
            ->limit(max(1, min(100, (int) $this->option('limit'))))
            ->get();

        if ($sample->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Grupo', 'SKU', 'MLM maestro', 'Variante', 'Stock', 'Cuenta 1', 'Cuenta 2', 'Errores'],
                $sample->map(fn ($group) => [
                    $group->id,
                    $group->sku ?: '—',
                    $group->master_mlm ?: '—',
                    $group->master_variation_id ?: '—',
                    $group->stock,
                    $group->master_members_count,
                    $group->mirror_members_count,
                    $group->error_members_count,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
