<?php

namespace App\Console\Commands;

use App\Jobs\MapAutomotivePartToMeliCategoriesJob;
use App\Models\AutomotivePart;
use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySuggestionService;
use App\Services\Autopartes\Meli\AutomotivePartMeliConfiguration;
use App\Services\Autopartes\Meli\AutomotivePartMeliException;
use App\Services\Autopartes\Meli\AutomotivePartMeliRequestBudget;
use App\Services\Autopartes\Meli\AutomotivePartMeliTokenProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MapAutomotivePartsToMeliCommand extends Command
{
    protected $signature = 'autopartes:meli-map
        {--limit= : Cantidad máxima de autopartes}
        {--part-id= : ID de una autoparte}
        {--review-id= : ID de una revisión de enriquecimiento}
        {--internal-category= : Categoría interna exacta}
        {--dry-run : Mostrar consultas sin llamar Mercado Libre}
        {--refresh-metadata : Ignorar caché de metadatos}
        {--force : Procesar aunque ya exista una categoría aprobada}';

    protected $description = 'Prepara candidatos de categorías de Mercado Libre para Autopartes sin publicar';

    public function handle(
        AutomotivePartMeliCategorySuggestionService $suggestions,
        AutomotivePartMeliConfiguration $configuration,
        AutomotivePartMeliTokenProvider $tokens,
        AutomotivePartMeliRequestBudget $budget,
    ): int {
        $maxBatch = max(1, (int) config('autopartes_meli.max_batch', 10));
        $limit = $this->positiveIntegerOption('limit') ?? $maxBatch;
        $partId = $this->positiveIntegerOption('part-id');
        $reviewId = $this->positiveIntegerOption('review-id');

        if ($limit === false || $partId === false || $reviewId === false) {
            return self::FAILURE;
        }
        if ($limit > $maxBatch) {
            $this->error("--limit no puede superar AUTOPARTES_MELI_MAX_BATCH ({$maxBatch}).");

            return self::FAILURE;
        }

        $query = AutomotivePart::query()
            ->with('enrichmentReview')
            ->when($partId !== null, fn (Builder $builder) => $builder->whereKey($partId))
            ->when($reviewId !== null, fn (Builder $builder) => $builder->whereHas('enrichmentReview', fn ($review) => $review->whereKey($reviewId)))
            ->when(filled($this->option('internal-category')), fn (Builder $builder) => $builder->where('category', $this->option('internal-category')))
            ->when(! $this->option('force'), fn (Builder $builder) => $builder->whereDoesntHave('meliCategoryCandidates', fn ($candidate) => $candidate->where('status', 'approved')))
            ->orderBy('id')
            ->limit($limit);
        $parts = $query->get();

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry-run: no se llamó Mercado Libre, no se crearon candidatos y no se encolaron jobs.');
            $this->table(
                ['Autoparte', 'Item', 'Categoría interna', 'Consulta', 'Fuente', 'Regla', 'Versión'],
                $parts->map(function (AutomotivePart $part) use ($suggestions) {
                    $preview = $suggestions->preview($part);

                    return [
                        $part->id,
                        $part->item_number ?? '—',
                        trim(($part->category ?? '').' / '.($part->subcategory ?? ''), ' /'),
                        $preview['query'],
                        $preview['query_source'],
                        $preview['deterministic_rule']['category_id'] ?? '—',
                        $preview['rules_version'],
                    ];
                })->all(),
            );

            return self::SUCCESS;
        }

        try {
            $configuration->assertReady();
            $tokens->token();
        } catch (AutomotivePartMeliException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($parts as $part) {
            if ($budget->remaining() < 1) {
                $skipped++;

                continue;
            }

            $preview = $suggestions->preview($part);
            $fingerprint = hash('sha256', json_encode([
                'part_id' => $part->id,
                'part_updated_at' => $part->updated_at?->toJSON(),
                'review_updated_at' => $part->enrichmentReview?->updated_at?->toJSON(),
                'query' => $preview['query'],
                'rules_version' => $preview['rules_version'],
            ], JSON_THROW_ON_ERROR));
            MapAutomotivePartToMeliCategoriesJob::dispatch(
                $part->id,
                $fingerprint,
                (bool) $this->option('refresh-metadata'),
                (bool) $this->option('force'),
            );
            $queued++;
        }

        $this->table(['Métrica', 'Total'], [
            ['Candidatos evaluados', $parts->count()],
            ['Jobs encolados', $queued],
            ['Omitidos', $skipped],
            ['Solicitudes HTTP realizadas en este comando', 0],
            ['Cache hits en este comando', 0],
            ['Candidatos persistidos en este comando', 0],
            ['Errores de despacho', 0],
            ['Solicitudes disponibles hoy', $budget->remaining()],
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
