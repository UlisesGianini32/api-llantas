<?php

namespace App\Console\Commands;

use App\Services\SyscomPortalScraper;
use Illuminate\Console\Command;

class SyscomTestPortalScrape extends Command
{
    protected $signature = 'syscom:test-portal-scrape
        {producto_id : ID SYSCOM del producto a consultar (ej. 193139)}
        {--branch=hermosillo : Sucursal a resaltar}
        {--raw : Imprimir el JSON crudo recibido del portal}';

    protected $description = 'Prueba el scraper del portal www.syscom.mx /api/productos/{id}/existencias y muestra el desglose por sucursal';

    public function handle(SyscomPortalScraper $scraper): int
    {
        $id = (int) $this->argument('producto_id');
        $branch = trim((string) $this->option('branch')) ?: 'hermosillo';

        if ($id <= 0) {
            $this->error('producto_id inválido.');

            return self::INVALID;
        }

        $this->line("Estado del scraper:");
        $this->line('  enabled (config)  : '.((bool) config('syscom.portal_scrape_enabled') ? 'true' : 'false'));
        $cookieLen = strlen((string) config('syscom.portal_cookies', ''));
        $this->line('  cookies set       : '.($cookieLen > 0 ? 'sí ('.$cookieLen.' chars)' : 'NO'));
        $this->line('  base_url          : '.config('syscom.portal_base_url'));
        $this->newLine();

        if (! $scraper->isEnabled()) {
            $this->error('Scraper desactivado o sin cookies. Configurá:');
            $this->line('  SYSCOM_PORTAL_SCRAPE_ENABLED=true');
            $this->line('  SYSCOM_PORTAL_COOKIES="..."   (Cookie completa del header del navegador)');
            $this->line('Luego: php artisan optimize:clear && volvé a probar.');

            return self::FAILURE;
        }

        $this->line("Consultando producto SYSCOM {$id}...");
        $payload = $scraper->fetchExistencias($id);

        if ($payload === null) {
            $this->error('No se obtuvo respuesta válida. Revisá storage/logs/laravel.log para ver el HTTP status / body.');
            $this->line('Causa más común: cookies vencidas (cf_clearance dura horas, session ~30 días) o Cloudflare bloqueó el IP del servidor.');

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        $breakdown = $scraper->branchBreakdown($id);
        if ($breakdown === null) {
            $this->warn('No vino el detalle por sucursal en la respuesta. ¿Producto sin stock detallado?');
            $this->line('Total general (texto del portal): '.(string) data_get($payload, "{$id}.existencia.nuevo", '—'));

            return self::SUCCESS;
        }

        $this->info('Desglose por sucursal (existencia "nuevo"):');
        ksort($breakdown);
        $rowsWithStock = 0;
        foreach ($breakdown as $name => $qty) {
            $marker = ($name === $branch || str_contains($name, $branch)) ? '  →' : '   ';
            $line = sprintf('%s %-22s %4d', $marker, $name, $qty);
            if ($qty > 0) {
                $rowsWithStock++;
                $this->info($line);
            } else {
                $this->line($line);
            }
        }
        $this->newLine();

        $h = $scraper->branchStockNuevo($id, $branch);
        $this->info(sprintf(
            'Stock %s: %s',
            $branch,
            $h === null ? 'NO ENCONTRADO' : (string) $h
        ));
        $this->line(sprintf('Sucursales con stock: %d / %d', $rowsWithStock, count($breakdown)));

        return self::SUCCESS;
    }
}
