<?php

namespace App\Console\Commands;

use App\Models\InventoryChannelLink;
use App\Services\InventoryMeliStockPilotService;
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
            ['Inventory disponible', $preview['available'] ?? '—'],
            ['Target', $preview['target'] ?? '—'],
            ['ML actual', $preview['remote_current_quantity'] ?? '—'],
            ['Delta', $preview['delta'] ?? '—'],
            ['Legacy', implode(',', $preview['legacy_sources'] ?? []) ?: 'sin fuente detectada'],
            ['Drift', ! empty($preview['remote_drift']) ? 'REMOTE_DRIFT' : '—'],
            ['Elegibilidad', $preview['eligibility']],
        ]);

        if (! $this->option('apply')) {
            $this->line('Preview únicamente: no se ejecutó PUT.');

            return self::SUCCESS;
        }

        if ((string) $this->option('confirm') !== (string) $preview['identity']) {
            $this->error('La confirmación no coincide exactamente con la identidad mostrada. No se ejecutó PUT.');

            return self::FAILURE;
        }
        if (($preview['eligibility'] ?? null) !== InventoryMeliStockPilotService::READY) {
            $this->error('El vínculo no es elegible para el piloto: '.$preview['eligibility']);

            return self::FAILURE;
        }

        $result = $pilot->apply($link);
        $this->line('PUT: '.($result['write_status'] ?? 'UNKNOWN'));
        $this->line('Verificación: '.($result['verification_status'] ?? '—'));

        return ($result['write_status'] ?? null) === 'SUCCESS'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
