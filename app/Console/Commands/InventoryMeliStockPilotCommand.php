<?php

namespace App\Console\Commands;

use App\Models\InventoryChannelLink;
use App\Services\InventoryMeliStockPilotService;
use App\Services\InventoryMeliStockSyncService;
use Illuminate\Console\Command;

class InventoryMeliStockPilotCommand extends Command
{
    protected $signature = 'inventory:meli-stock-pilot
        {--link= : ID del vínculo único a revisar}
        {--apply : Ejecuta el PUT después de la prelectura}
        {--confirm= : Debe coincidir exactamente con el MLM o MLM:variation mostrado}';

    protected $description = 'Previsualiza y prueba stock de un único vínculo Mercado Libre';

    public function handle(InventoryMeliStockPilotService $pilot): int
    {
        $linkId = $this->option('link');
        if (! is_numeric($linkId) || (int) $linkId < 1) {
            $this->error('Debe indicar exactamente un vínculo con --link=<id>.');

            return self::FAILURE;
        }

        $link = InventoryChannelLink::query()->with('product')->find((int) $linkId);
        if (! $link) {
            $this->error('El vínculo indicado no existe.');

            return self::FAILURE;
        }

        $preview = $pilot->preview($link);
        $this->table(['Campo', 'Valor'], [
            ['Vínculo', $link->id],
            ['SKU', $preview['sku'] ?? '—'],
            ['Producto', $preview['product_name'] ?? '—'],
            ['Tipo', $preview['simple_or_kit'] ?? '—'],
            ['Cuenta', $preview['account_name'] ?? $preview['account_key'] ?? '—'],
            ['Identidad', $preview['identity']],
            ['Requested identity', $preview['requested_identity'] ?? $preview['identity']],
            ['Write identity', $preview['write_identity'] ?? $preview['identity']],
            ['Remote user product', $preview['remote_user_product_id'] ?? '—'],
            ['Shared stock group', $preview['shared_stock_group'] ?? '—'],
            ['Representative link', $preview['representative_link_id'] ?? $link->id],
            ['Sibling links', implode(', ', $preview['sibling_link_ids'] ?? []) ?: '—'],
            ['Conflicto de propiedad', ! empty($preview['remote_user_product_conflict'])
                ? 'REMOTE_USER_PRODUCT_CONFLICT; links='.implode(',', $preview['conflict_link_ids'] ?? []).'; products='.implode(',', $preview['conflict_product_ids'] ?? [])
                : '—'],
            ['Inventory disponible', $preview['available'] ?? '—'],
            ['Target', $preview['target'] ?? '—'],
            ['ML actual', $preview['remote_current_quantity'] ?? '—'],
            ['Delta', $preview['delta'] ?? '—'],
            ['Legacy', implode(',', $preview['legacy_sources'] ?? []) ?: 'sin fuente detectada'],
            ['Drift', ! empty($preview['remote_drift']) ? 'REMOTE_DRIFT' : (! empty($preview['remote_drift_explained']) ? 'explicado por escritura auditada del grupo' : '—')],
            ['Elegibilidad', $preview['eligibility']],
        ]);

        if (! $this->option('apply')) {
            $this->line('Preview únicamente: no se ejecutó PUT.');

            return self::SUCCESS;
        }

        if ((string) $this->option('confirm') !== (string) ($preview['write_identity'] ?? $preview['identity'])) {
            $this->error('La confirmación no coincide exactamente con la identidad que recibirá el PUT. No se ejecutó PUT.');

            return self::FAILURE;
        }
        if (($preview['eligibility'] ?? null) !== InventoryMeliStockPilotService::READY) {
            $this->error('El vínculo no es elegible para el piloto: '.$preview['eligibility']);

            return self::FAILURE;
        }

        $result = $pilot->apply($link, null, (string) $this->option('confirm'));
        $this->line('PUT: '.($result['write_status'] ?? 'UNKNOWN'));
        if (($result['write_status'] ?? null) === InventoryMeliStockSyncService::REMOTE_USER_PRODUCT_CONFLICT) {
            $this->line('Conflicto seguro: user_product_id='.($result['remote_user_product_id'] ?? '—')
                .' links='.implode(',', $result['link_ids'] ?? $result['conflict_link_ids'] ?? [])
                .' products='.implode(',', $result['product_ids'] ?? $result['conflict_product_ids'] ?? []));
        }
        $this->line('Verificación: '.($result['verification_status'] ?? '—'));

        return in_array(($result['write_status'] ?? null), ['SUCCESS', InventoryMeliStockSyncService::NO_CHANGE], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
