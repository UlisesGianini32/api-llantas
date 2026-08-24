<?php

namespace App\Console\Commands;

use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySyncService;
use App\Services\Autopartes\Meli\AutomotivePartMeliException;
use Illuminate\Console\Command;

class SyncAutomotivePartMeliCategoryCommand extends Command
{
    protected $signature = 'autopartes:meli-sync-category {category_id} {--refresh : Ignorar caché vigente}';

    protected $description = 'Sincroniza detalle y atributos oficiales de una categoría MLM sin publicar';

    public function handle(AutomotivePartMeliCategorySyncService $service): int
    {
        $categoryId = strtoupper(trim((string) $this->argument('category_id')));
        if (! preg_match('/^MLM\d+$/', $categoryId)) {
            $this->error('category_id debe usar el formato MLM seguido de dígitos.');

            return self::FAILURE;
        }

        try {
            $category = $service->syncAttributes($categoryId, (bool) $this->option('refresh'));
        } catch (AutomotivePartMeliException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Categoría {$category->category_id} sincronizada sin publicar.");
        $this->table(['Nombre', 'Dominio', 'Atributos'], [[
            $category->name,
            $category->domain_id ?? '—',
            $category->attributeRequirements->count(),
        ]]);

        return self::SUCCESS;
    }
}
