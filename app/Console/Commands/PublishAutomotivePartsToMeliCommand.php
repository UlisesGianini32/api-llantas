<?php

namespace App\Console\Commands;

use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliPublication;
use App\Models\MeliAccount;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPictureUploadService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublicationPreflight;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublicationWorkflow;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherConfiguration;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherException;
use App\Services\Autopartes\Publisher\AutomotivePartMeliRemoteValidationService;
use Illuminate\Console\Command;

class PublishAutomotivePartsToMeliCommand extends Command
{
    protected $signature = 'autopartes:meli-publish
        {--draft-id= : ID del borrador aprobado}
        {--publication-id= : ID del preflight persistido}
        {--limit=1 : Debe ser exactamente 1}
        {--dry-run : Preflight en memoria, sin persistencia ni HTTP}
        {--validate-only : Ejecutar únicamente POST /items/validate}
        {--upload-images : Cargar únicamente las imágenes privadas}
        {--live : Encolar una publicación real aprobada}
        {--force : Regenerar preflight sin omitir controles}';
    protected $description = 'Prepara, valida o encola una sola publicación controlada de Autopartes en Mercado Libre';

    public function handle(
        AutomotivePartMeliPublisherConfiguration $configuration,
        AutomotivePartMeliPublicationPreflight $preflight,
        AutomotivePartMeliPictureUploadService $pictures,
        AutomotivePartMeliRemoteValidationService $validation,
        AutomotivePartMeliPublicationWorkflow $workflow,
    ): int {
        if ((string) $this->option('limit') !== '1') { $this->error('--limit debe ser exactamente 1.'); return self::FAILURE; }
        $modes = collect(['dry-run', 'validate-only', 'upload-images', 'live'])->filter(fn ($mode) => (bool) $this->option($mode));
        if ($modes->count() > 1) { $this->error('Selecciona un solo modo de operación.'); return self::FAILURE; }
        try {
            if ($this->option('dry-run')) {
                $draft = $this->draft(); $account = $this->account($configuration);
                $preview = $preflight->preview($draft, $account);
                $this->table(['Elegible', 'Fingerprint', 'Errores', 'HTTP', 'Persistencia'], [[
                    $preview['eligible'] ? 'sí' : 'no', $preview['fingerprint'], collect($preview['errors'])->pluck('code')->implode(', '), 0, 0,
                ]]);
                return $preview['eligible'] ? self::SUCCESS : self::FAILURE;
            }
            if ($this->option('validate-only')) { $publication = $validation->validate($this->publication()); $this->info("Validación remota aprobada para #{$publication->id}; no se publicó."); return self::SUCCESS; }
            if ($this->option('upload-images')) { $publication = $pictures->upload($this->publication()); $this->info("Imágenes cargadas para #{$publication->id}; no se validó ni publicó."); return self::SUCCESS; }
            if ($this->option('live')) {
                if (! $this->option('publication-id') || $this->option('draft-id')) throw new AutomotivePartMeliPublisherException('--live exige un único --publication-id.', 'live_requires_publication');
                $publication = $workflow->enqueue($this->publication());
                $this->warn("Publicación #{$publication->id} encolada. El worker puede crear un artículo real."); return self::SUCCESS;
            }
            $publication = $preflight->create($this->draft(), $this->account($configuration));
            $this->info("Preflight #{$publication->id}: {$publication->status}. No se realizó HTTP.");
            if ($this->option('force')) $this->comment('--force regeneró el preflight, pero no omitió ningún control.');
            return $publication->status === 'local_valid' ? self::SUCCESS : self::FAILURE;
        } catch (AutomotivePartMeliPublisherException|\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->error($exception->getMessage()); return self::FAILURE;
        }
    }

    private function draft(): AutomotivePartMeliDraft
    {
        $id = filter_var($this->option('draft-id'), FILTER_VALIDATE_INT);
        if (! $id) throw new AutomotivePartMeliPublisherException('Indica --draft-id.', 'missing_draft_id');
        return AutomotivePartMeliDraft::query()->findOrFail($id);
    }
    private function publication(): AutomotivePartMeliPublication
    {
        $id = filter_var($this->option('publication-id'), FILTER_VALIDATE_INT);
        if (! $id) throw new AutomotivePartMeliPublisherException('Indica --publication-id.', 'missing_publication_id');
        return AutomotivePartMeliPublication::query()->findOrFail($id);
    }
    private function account(AutomotivePartMeliPublisherConfiguration $configuration): MeliAccount
    {
        $id = $configuration->configuredAccountId();
        if ($id === null) throw new AutomotivePartMeliPublisherException('Configura explícitamente AUTOPARTES_MELI_PUBLISHER_ACCOUNT_ID.', 'account_not_selected');
        return MeliAccount::query()->findOrFail($id);
    }
}
