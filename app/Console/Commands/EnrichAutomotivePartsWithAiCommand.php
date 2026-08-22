<?php

namespace App\Console\Commands;

use App\Services\Autopartes\Ai\AutomotivePartAiConfiguration;
use App\Services\Autopartes\Ai\AutomotivePartAiDispatchService;
use App\Services\Autopartes\Ai\AutomotivePartAiException;
use App\Services\Autopartes\AutomotivePartEnrichmentAuditService;
use Illuminate\Console\Command;

class EnrichAutomotivePartsWithAiCommand extends Command
{
    protected $signature = 'autopartes:ai-enrich
        {--limit= : Cantidad máxima de revisiones a considerar}
        {--part-id= : ID de una autoparte específica}
        {--review-id= : ID de una revisión específica}
        {--issue= : Código de problema requerido}
        {--dry-run : Mostrar candidatos sin persistir ni llamar OpenAI}
        {--force : Reintentar ejecuciones fallidas de reglas o IA}';

    protected $description = 'Encola propuestas de enriquecimiento de Autopartes con OpenAI';

    public function handle(
        AutomotivePartAiDispatchService $dispatcher,
        AutomotivePartAiConfiguration $configuration,
    ): int {
        $maxBatch = max(1, (int) config('autopartes_ai.max_batch', 10));
        $limit = $this->positiveIntegerOption('limit') ?? $maxBatch;
        $partId = $this->positiveIntegerOption('part-id');
        $reviewId = $this->positiveIntegerOption('review-id');
        $issue = $this->option('issue');

        if ($limit === false || $partId === false || $reviewId === false) {
            return self::FAILURE;
        }

        if ($limit > $maxBatch) {
            $this->error("--limit no puede superar AUTOPARTES_AI_MAX_BATCH ({$maxBatch}).");

            return self::FAILURE;
        }

        if ($issue !== null && ! in_array($issue, AutomotivePartEnrichmentAuditService::ISSUE_CODES, true)) {
            $this->error('--issue no corresponde a un código de auditoría válido.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            if ($configuration->model() === '' || $configuration->promptVersion() === '') {
                $this->error('El modelo y la versión del prompt deben estar configurados para el dry-run.');

                return self::FAILURE;
            }

            $candidates = $dispatcher->preview($limit, $partId, $reviewId, $issue);
            $this->info('Dry-run: no se modificaron revisiones, no se crearon ejecuciones y no se llamó OpenAI.');
            $this->table(
                ['Review', 'Autoparte', 'Item', 'Modelo', 'Prompt', 'Fingerprint', 'Elegible', 'Motivo'],
                $candidates->map(fn (array $candidate) => [
                    $candidate['review_id'],
                    $candidate['automotive_part_id'],
                    $candidate['item_number'] ?? '—',
                    $candidate['model'],
                    $candidate['prompt_version'],
                    $candidate['fingerprint'],
                    $candidate['eligible'] ? 'sí' : 'no',
                    $candidate['reason'] ?? '—',
                ])->all(),
            );
            $this->line('Límite diario restante: '.$dispatcher->dailyRemaining());

            return self::SUCCESS;
        }

        try {
            $stats = $dispatcher->dispatchBatch(
                $limit,
                $partId,
                $reviewId,
                $issue,
                (bool) $this->option('force'),
            );
        } catch (AutomotivePartAiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Métrica', 'Total'], [
            ['Candidatos', $stats['candidates']],
            ['Encolados', $stats['queued']],
            ['Omitidos', $stats['skipped']],
            ['Errores', $stats['errors']],
            ['Límite diario restante', $dispatcher->dailyRemaining()],
        ]);

        if ($stats['details'] !== []) {
            $this->table(['Review', 'Run', 'Estado', 'Detalle'], array_map(fn (array $detail) => [
                $detail['review_id'] ?? '—',
                $detail['run_id'] ?? '—',
                $detail['status'],
                $detail['message'],
            ], $stats['details']));
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
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
