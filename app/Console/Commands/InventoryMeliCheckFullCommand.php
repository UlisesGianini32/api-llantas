<?php

namespace App\Console\Commands;

use App\Models\InventoryChannelLink;
use App\Models\MeliAccount;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class InventoryMeliCheckFullCommand extends Command
{
    protected $signature = 'inventory:meli-check-full
        {--apply : Da de baja (desactiva el vínculo y apaga la sincronización de stock) los vínculos Full detectados}
        {--account= : Filtra por ID de cuenta MeLi}
        {--sku= : Filtra por SKU del producto}
        {--mlm= : Filtra por ID de publicación MLM}
        {--sync-only : Solo desactiva la sincronización de stock (stock_sync_enabled=0) manteniendo el vínculo activo}
        {--all : Muestra todos los vínculos analizados en la tabla, no únicamente los Full}';

    protected $description = 'Detecta publicaciones de Mercado Libre con logística Full (Fulfillment) y permite darlas de baja';

    public function handle(MeliAccountApiClient $api): int
    {
        $apply = (bool) $this->option('apply');
        $syncOnly = (bool) $this->option('sync-only');
        $showAll = (bool) $this->option('all');

        $query = InventoryChannelLink::query()
            ->with('product')
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->when(filled($this->option('account')), fn ($q) => $q->where('account_key', (string) $this->option('account')))
            ->when(filled($this->option('mlm')), fn ($q) => $q->where('external_listing_id', trim((string) $this->option('mlm'))))
            ->when(filled($this->option('sku')), fn ($q) => $q->whereHas('product', fn ($p) => $p->where('sku', 'like', '%'.trim((string) $this->option('sku')).'%')))
            ->orderBy('id');

        $links = $query->get();

        if ($links->isEmpty()) {
            $this->warn('No se encontraron vínculos de Mercado Libre que coincidan con los filtros.');

            return self::SUCCESS;
        }

        $this->info("Analizando {$links->count()} vínculo(s) de Mercado Libre...");

        $accountIds = $links->pluck('account_key')->filter(fn ($k) => ctype_digit((string) $k))->map(fn ($k) => (int) $k)->unique();
        $accounts = MeliAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $linksByAccount = $links->groupBy('account_key');
        $itemsInfo = [];

        foreach ($linksByAccount as $accountKey => $accountLinks) {
            $account = ctype_digit((string) $accountKey) ? $accounts->get((int) $accountKey) : null;
            if (! $account) {
                $this->warn("Cuenta MeLi ID {$accountKey} no encontrada en la base de datos.");
                continue;
            }

            try {
                $api->ensureFreshAccessToken($account);
            } catch (Throwable $e) {
                $this->error("Error al refrescar token de la cuenta {$account->nickname} (ID {$account->id}): {$e->getMessage()}");
                continue;
            }

            $uniqueMlms = $accountLinks->pluck('external_listing_id')->filter()->unique()->values();

            // Consultar en lotes de hasta 20 publicaciones por petición multiget
            foreach ($uniqueMlms->chunk(20) as $chunk) {
                $idsParam = $chunk->implode(',');
                try {
                    $response = $api->request(
                        $account,
                        'get',
                        "/items?ids={$idsParam}&attributes=id,title,status,sub_status,shipping,user_product_id"
                    );

                    $results = (array) $response->json();
                    foreach ($results as $itemEntry) {
                        $code = $itemEntry['code'] ?? null;
                        $body = $itemEntry['body'] ?? [];
                        if ($code === 200 && is_array($body) && filled($body['id'] ?? null)) {
                            $itemsInfo[(string) $body['id']] = $body;
                        } elseif (is_array($body) && filled($body['id'] ?? null)) {
                            $itemsInfo[(string) $body['id']] = [
                                'id' => $body['id'],
                                'status' => 'error_'.$code,
                                'shipping' => [],
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    $this->warn("Error al consultar lote de items [{$idsParam}]: {$e->getMessage()}");
                }
            }
        }

        $tableRows = [];
        $fullLinksToDeactivate = [];
        $totalAnalyzed = 0;
        $totalFull = 0;

        foreach ($links as $link) {
            $totalAnalyzed++;
            $mlm = (string) ($link->external_listing_id ?? '');
            $item = $itemsInfo[$mlm] ?? null;

            $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];
            $logisticType = strtolower(trim((string) ($shipping['logistic_type'] ?? '')));
            $tags = is_array($shipping['tags'] ?? null) ? $shipping['tags'] : [];
            $status = (string) ($item['status'] ?? 'desconocido');

            $isFull = ($logisticType === 'fulfillment');
            if (! $isFull) {
                foreach ($tags as $tag) {
                    $tagLower = strtolower(trim((string) $tag));
                    if (str_contains($tagLower, 'fulfillment') || str_contains($tagLower, 'fbm')) {
                        $isFull = true;
                        break;
                    }
                }
            }

            if ($isFull) {
                $totalFull++;
            }

            $isCurrentlyActive = (bool) $link->is_active;
            $isSyncEnabled = (bool) $link->stock_sync_enabled;
            $needsAction = $isFull && ($isCurrentlyActive || $isSyncEnabled);

            if ($needsAction) {
                $fullLinksToDeactivate[] = [
                    'link' => $link,
                    'logistic_type' => $logisticType ?: 'fulfillment',
                ];
            }

            $actionText = 'OK';
            if ($isFull) {
                if ($needsAction) {
                    $actionText = $apply ? 'DADO DE BAJA' : 'DAR DE BAJA';
                } else {
                    $actionText = 'YA INACTIVO';
                }
            }

            $currentStatusText = ($isCurrentlyActive ? 'Activo' : 'Inactivo')
                .' | '.($isSyncEnabled ? 'Sync: ON' : 'Sync: OFF');

            if ($isFull || $showAll) {
                $tableRows[] = [
                    'id' => $link->id,
                    'sku' => $link->product?->sku ?? '—',
                    'producto' => Str::limit($link->product?->name ?? '—', 30),
                    'mlm' => $mlm ?: '—',
                    'logistica' => $logisticType ?: '—',
                    'status_meli' => $status,
                    'es_full' => $isFull ? 'SÍ (FULL)' : 'NO',
                    'estado_vinculo' => $currentStatusText,
                    'accion' => $actionText,
                ];
            }
        }

        $this->newLine();
        $this->table(
            ['ID', 'SKU', 'Producto', 'MLM', 'Logística', 'Estado MeLi', '¿Es Full?', 'Estado Vínculo', 'Acción'],
            $tableRows
        );

        $this->newLine();
        $this->info("=== Resumen ===");
        $this->line("Vínculos analizados: {$totalAnalyzed}");
        $this->line("Vínculos detectados como Full: {$totalFull}");
        $this->line("Vínculos Full que requieren baja: ".count($fullLinksToDeactivate));

        if (empty($fullLinksToDeactivate)) {
            $this->info('No hay vínculos Full activos pendientes de dar de baja.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Modo Dry-run (previsualización): no se modificó ningún vínculo en la base de datos.');
            $this->line('Para aplicar las bajas automáticamente, ejecuta el comando con <info>--apply</info>:');
            $this->line('  <comment>php artisan inventory:meli-check-full --apply</comment>');
            if ($syncOnly) {
                $this->line('  (o con --sync-only para mantener el vínculo activo y solo apagar la sincronización de stock)');
            }

            return self::SUCCESS;
        }

        // Aplicar la baja
        $deactivatedCount = 0;
        foreach ($fullLinksToDeactivate as $entry) {
            /** @var InventoryChannelLink $link */
            $link = $entry['link'];
            $logisticType = $entry['logistic_type'];

            $link->stock_sync_enabled = false;
            if (! $syncOnly) {
                $link->is_active = false;
            }

            $metadata = (array) ($link->metadata ?? []);
            $metadata['deactivated_due_to_full'] = true;
            $metadata['full_detected_at'] = now()->toIso8601String();
            $metadata['detected_logistic_type'] = $logisticType;
            $link->metadata = $metadata;
            $link->save();

            $deactivatedCount++;
        }

        $this->newLine();
        $this->info("Bajas aplicadas correctamente: {$deactivatedCount} vínculo(s) actualizado(s).");
        if ($syncOnly) {
            $this->line('Se desactivó la sincronización de stock (stock_sync_enabled=0) en los vínculos Full.');
        } else {
            $this->line('Se desactivó el vínculo por completo (is_active=0) y se apagó la sincronización de stock.');
        }

        return self::SUCCESS;
    }
}
