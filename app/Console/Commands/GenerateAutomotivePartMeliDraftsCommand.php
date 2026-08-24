<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAutomotivePartMeliDraftJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliDraft;
use App\Services\Autopartes\Drafts\AutomotivePartDraftBuilder;
use App\Services\Autopartes\Drafts\AutomotivePartDraftConfiguration;
use App\Services\Autopartes\Drafts\AutomotivePartDraftException;
use Illuminate\Console\Command;

class GenerateAutomotivePartMeliDraftsCommand extends Command
{
    protected $signature = 'autopartes:drafts-generate
        {--part-id= : ID de una autoparte}
        {--draft-id= : ID de un borrador que debe regenerarse}
        {--limit= : Cantidad máxima de autopartes}
        {--force : Solicitar regeneración explícita}
        {--dry-run : Mostrar elegibilidad sin persistir ni encolar}';

    protected $description = 'Genera borradores internos de Autopartes sin escribir en Mercado Libre';

    public function handle(
        AutomotivePartDraftBuilder $builder,
        AutomotivePartDraftConfiguration $configuration,
    ): int {
        $partId = $this->positiveIntegerOption('part-id');
        $draftId = $this->positiveIntegerOption('draft-id');
        $limit = $this->positiveIntegerOption('limit') ?? $configuration->maxBatch();
        if ($partId === false || $draftId === false || $limit === false) {
            return self::FAILURE;
        }
        if ($partId !== null && $draftId !== null) {
            $this->error('--part-id y --draft-id no pueden utilizarse juntos.');

            return self::FAILURE;
        }
        if ($limit > $configuration->maxBatch()) {
            $this->error("--limit no puede superar AUTOPARTES_DRAFT_MAX_BATCH ({$configuration->maxBatch()}).");

            return self::FAILURE;
        }

        if ($draftId !== null) {
            $draft = AutomotivePartMeliDraft::query()->find($draftId);
            if ($draft === null) {
                $this->error('No existe el borrador solicitado.');

                return self::FAILURE;
            }
            $partId = $draft->automotive_part_id;
        }
        $parts = AutomotivePart::query()
            ->when($partId !== null, fn ($query) => $query->whereKey($partId))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $previews = $parts->map(fn (AutomotivePart $part) => $builder->preview($part));

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry-run: no se persistieron borradores, no se encolaron jobs y no se realizaron solicitudes externas.');
            $this->table(
                ['Autoparte', 'Elegible', 'Estado previsto', 'Fingerprint', 'Errores'],
                $previews->map(fn (array $preview) => [
                    $preview['automotive_part_id'],
                    $preview['eligible'] ? 'sí' : 'no',
                    $preview['suggested_status'],
                    $preview['fingerprint'],
                    collect($preview['blocking_errors'])->pluck('code')->implode(', '),
                ])->all(),
            );

            return self::SUCCESS;
        }

        try {
            $configuration->assertEnabled();
        } catch (AutomotivePartDraftException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($previews as $preview) {
            GenerateAutomotivePartMeliDraftJob::dispatch(
                $preview['automotive_part_id'],
                $preview['fingerprint'],
                (bool) $this->option('force'),
            );
        }

        $this->table(['Métrica', 'Total'], [
            ['Autopartes evaluadas', $previews->count()],
            ['Jobs encolados', $previews->count()],
            ['Elegibles', $previews->where('eligible', true)->count()],
            ['Incompletas', $previews->where('eligible', false)->count()],
            ['Solicitudes HTTP', 0],
        ]);

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): int|false|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->error("--{$name} debe ser un entero mayor a cero.");

            return false;
        }

        return (int) $value;
    }
}
