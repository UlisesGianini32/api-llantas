<?php

namespace App\Console\Commands;

use App\Models\SyscomProduct;
use App\Models\User;
use App\Services\MeliPublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SyscomMeliAuditCategoriesCommand extends Command
{
    protected $signature = 'syscom:meli-audit-categories
        {--syscom-id=* : Auditar únicamente estos IDs de categoría SYSCOM}
        {--limit=25 : Máximo de categorías a procesar}
        {--samples=20 : Productos distribuidos a probar por categoría}
        {--min-products=1 : Mínimo de productos que debe tener la categoría}
        {--sleep-ms=300 : Pausa entre llamadas al predictor ML}
        {--dry-run : Analizar sin guardar candidatos}
        {--reaudit : Volver a procesar categorías que ya tienen auditoría}
        {--force : Permitir reemplazar candidatos manuales no aprobados}';

    protected $description =
        'Audita categorías SYSCOM contra el predictor de categorías de Mercado Libre y guarda candidatos sin aprobarlos.';

    public function handle(MeliPublishService $meli): int
    {
        $user = User::query()
            ->whereNotNull('access_token')
            ->first();

        if (! $user) {
            $this->error(
                'No encontré un usuario con access_token de Mercado Libre.'
            );

            return self::FAILURE;
        }

        $limit = max(
            1,
            min(1000, (int) $this->option('limit'))
        );

        $samples = max(
            1,
            min(1000, (int) $this->option('samples'))
        );

        $minProducts = max(
            1,
            (int) $this->option('min-products')
        );

        $sleepMs = max(
            0,
            min(5000, (int) $this->option('sleep-ms'))
        );

        $dryRun = (bool) $this->option('dry-run');
        $reaudit = (bool) $this->option('reaudit');
        $force = (bool) $this->option('force');

        $requestedSyscomIds = collect(
            (array) $this->option('syscom-id')
        )
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $query = DB::table('syscom_products as p')
            ->join(
                'syscom_categories as c',
                'c.id',
                '=',
                'p.syscom_primary_category_id'
            )
            ->leftJoin(
                'syscom_meli_category_maps as m',
                'm.syscom_category_id',
                '=',
                'c.id'
            )
            ->leftJoin(
                'syscom_meli_category_audits as a',
                'a.syscom_category_id',
                '=',
                'c.id'
            )
            ->select(
                'c.id',
                'c.syscom_category_id',
                'c.name',
                'c.path',
                DB::raw('COUNT(p.id) AS productos')
            )
            ->where(function ($q) {
                $q->whereNull('m.id')
                    ->orWhere('m.approved', false);
            })
            ->when(
                ! $reaudit,
                fn ($q) => $q->whereNull('a.id')
            )
            ->groupBy(
                'c.id',
                'c.syscom_category_id',
                'c.name',
                'c.path'
            )
            ->havingRaw(
                'COUNT(p.id) >= ?',
                [$minProducts]
            )
            ->orderByDesc('productos');

        if ($requestedSyscomIds->isNotEmpty()) {
            $query->whereIn(
                'c.syscom_category_id',
                $requestedSyscomIds->all()
            );
        }

        $categories = $query
            ->limit($limit)
            ->get();

        if ($categories->isEmpty()) {
            $this->info(
                'No hay categorías pendientes que coincidan con los filtros.'
            );

            return self::SUCCESS;
        }

        $this->newLine();

        $this->info(
            'Auditoría SYSCOM -> Mercado Libre'
        );

        $this->line(
            'Categorías: '.$categories->count()
            .' | muestras: '.$samples
            .' | pausa: '.$sleepMs.' ms'
            .' | modo: '.($dryRun ? 'DRY-RUN' : 'GUARDAR')
        );

        $this->newLine();

        $results = [];

        foreach ($categories as $index => $category) {
            $number = $index + 1;

            $this->line(
                sprintf(
                    '[%d/%d] %s - %s (%d productos)',
                    $number,
                    $categories->count(),
                    $category->syscom_category_id,
                    $category->name,
                    $category->productos
                )
            );

            $existing = DB::table(
                'syscom_meli_category_maps'
            )
                ->where(
                    'syscom_category_id',
                    $category->id
                )
                ->first();

            /*
             * Seguridad adicional. Aunque el query superior
             * ya excluye mappings aprobados, nunca se modifica
             * uno aprobado desde este comando.
             */
            if ($existing && (bool) $existing->approved) {
                $this->warn(
                    '  SKIP: mapping ya aprobado.'
                );

                continue;
            }

            $allIds = SyscomProduct::query()
                ->where(
                    'syscom_primary_category_id',
                    $category->id
                )
                ->orderBy('id')
                ->pluck('id')
                ->values();

            if ($allIds->isEmpty()) {
                $this->warn(
                    '  SKIP: categoría sin productos.'
                );

                continue;
            }

            $sampleIds = $this->uniformSample(
                $allIds,
                min($samples, $allIds->count())
            );

            $productsById = SyscomProduct::query()
                ->whereIn('id', $sampleIds->all())
                ->get()
                ->keyBy('id');

            $products = $sampleIds
                ->map(
                    fn ($id) => $productsById->get($id)
                )
                ->filter()
                ->values();

            $distribution = [];
            $names = [];

            $productPredictions = [];
            $errors = [];
            $noPrediction = 0;

            foreach ($products as $product) {
                $title = Str::limit(
                    trim(
                        strip_tags(
                            (string) $product->titulo
                        )
                    ),
                    120,
                    ''
                );

                if ($title === '') {
                    $noPrediction++;

                    $productPredictions[] = [
                        'product_id' =>
                            (int) $product->id,

                        'syscom_producto_id' =>
                            $product->syscom_producto_id,

                        'modelo' =>
                            (string) $product->modelo,

                        'titulo' =>
                            '',

                        'category_id' =>
                            null,

                        'category_name' =>
                            null,

                        'top' =>
                            [],

                        'status' =>
                            'EMPTY_TITLE',

                        'error' =>
                            null,
                    ];

                    continue;
                }

                $prediction = $this->predictFirstCategory(
                    $meli,
                    $user,
                    $title
                );

                $predictionStatus =
                    $prediction['error'] !== null
                        ? 'ERROR'
                        : (
                            $prediction['category_id'] === ''
                                ? 'NO_PREDICTION'
                                : 'OK'
                        );

                $productPredictions[] = [
                    'product_id' =>
                        (int) $product->id,

                    'syscom_producto_id' =>
                        $product->syscom_producto_id,

                    'modelo' =>
                        (string) $product->modelo,

                    'titulo' =>
                        $title,

                    'category_id' =>
                        $prediction['category_id'] !== ''
                            ? $prediction['category_id']
                            : null,

                    'category_name' =>
                        $prediction['name'] !== ''
                            ? $prediction['name']
                            : null,

                    'top' =>
                        $prediction['top'] ?? [],

                    'status' =>
                        $predictionStatus,

                    'error' =>
                        $prediction['error'],
                ];

                if ($prediction['error'] !== null) {
                    $errors[] = [
                        'modelo' => $product->modelo,
                        'error' => $prediction['error'],
                    ];
                } elseif ($prediction['category_id'] === '') {
                    $noPrediction++;
                } else {
                    $mlm = $prediction['category_id'];

                    $distribution[$mlm] =
                        ($distribution[$mlm] ?? 0) + 1;

                    if ($prediction['name'] !== '') {
                        $names[$mlm] = $prediction['name'];
                    }
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            arsort($distribution);

            $dominantMlm = array_key_first(
                $distribution
            );

            $validPredictions = array_sum(
                $distribution
            );

            $sampleCount = $products->count();

            $dominantCount = $dominantMlm !== null
                ? ($distribution[$dominantMlm] ?? 0)
                : 0;

            $consensus = $validPredictions > 0
                ? round(
                    ($dominantCount / $validPredictions) * 100,
                    1
                )
                : 0.0;

            $coverage = $sampleCount > 0
                ? round(
                    ($validPredictions / $sampleCount) * 100,
                    1
                )
                : 0.0;

            /*
             * Confidence conservador:
             *
             * 20/20 mismo resultado = 100.
             * 1/20 mismo resultado no puede convertirse
             * artificialmente en confidence 100.
             */
            $confidence = (int) round(
                min($consensus, $coverage)
            );

            $status = $this->auditStatus(
                $consensus,
                $coverage,
                $validPredictions
            );

            $meliName = $dominantMlm !== null
                ? ($names[$dominantMlm] ?? '')
                : '';

            $meliPath = null;
            $structurallyValid = false;
            $metaError = null;

            /*
             * Una sugerencia fuerte no basta.
             *
             * Validamos además que Mercado Libre reconozca
             * el MLM, permita publicar y que sea categoría final.
             */
            if ($dominantMlm !== null) {
                try {
                    $meta = $meli->getCategory(
                        $user,
                        $dominantMlm
                    );

                    $metaName = trim(
                        (string) (
                            $meta['name'] ?? ''
                        )
                    );

                    if ($metaName !== '') {
                        $meliName = $metaName;
                    }

                    $meliPath = collect(
                        $meta['path_from_root'] ?? []
                    )
                        ->pluck('name')
                        ->filter()
                        ->implode(' > ');

                    $listingAllowed = ! array_key_exists(
                        'listing_allowed',
                        $meta['settings'] ?? []
                    )
                        || (
                            $meta['settings']['listing_allowed']
                            === true
                        );

                    $children = $meta[
                        'children_categories'
                    ] ?? [];

                    $isLeaf = is_array($children)
                        && $children === [];

                    $structurallyValid =
                        $listingAllowed && $isLeaf;

                    if (! $structurallyValid) {
                        $status = 'INVALID_ML_CATEGORY';
                    }
                } catch (Throwable $e) {
                    $metaError = $e->getMessage();
                    $status = 'ML_META_ERROR';
                }
            }

            /*
             * La auditoría y el mapping son conceptos distintos.
             *
             * Auditoría:
             *   siempre se guarda cuando NO estamos en dry-run.
             *
             * Mapping:
             *   sólo se conserva para AUTO_CANDIDATE o REVIEW.
             *   MIXED y errores quedan exclusivamente en audits.
             */
            if (! $dryRun) {

                $auditKey = [
                    'syscom_category_id' =>
                        $category->id,
                ];

                $auditExists = DB::table(
                    'syscom_meli_category_audits'
                )
                    ->where(
                        'syscom_category_id',
                        $category->id
                    )
                    ->exists();

                $auditData = [
                    'sample_count' =>
                        $sampleCount,

                    'valid_predictions' =>
                        $validPredictions,

                    'dominant_meli_category_id' =>
                        $dominantMlm,

                    'dominant_meli_category_name' =>
                        $meliName !== ''
                            ? $meliName
                            : null,

                    'dominant_meli_category_path' =>
                        $meliPath !== ''
                            ? $meliPath
                            : null,

                    'consensus' =>
                        $consensus,

                    'coverage' =>
                        $coverage,

                    'score' =>
                        $confidence,

                    'status' =>
                        $status,

                    'distribution' =>
                        $distribution !== []
                            ? json_encode(
                                $distribution,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            )
                            : null,

                    'product_predictions' =>
                        $productPredictions !== []
                            ? json_encode(
                                $productPredictions,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            )
                            : null,

                    'errors' =>
                        $errors !== []
                            ? json_encode(
                                $errors,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            )
                            : null,

                    'audited_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ];

                /*
                 * created_at solamente al crear.
                 * --reaudit conserva la fecha original.
                 */
                if (! $auditExists) {
                    $auditData['created_at'] = now();
                }

                DB::table(
                    'syscom_meli_category_audits'
                )->updateOrInsert(
                    $auditKey,
                    $auditData
                );
            }

            $dbAction = $dryRun
                ? 'DRY_RUN'
                : 'AUDIT_ONLY';

            $candidateStatus = in_array(
                $status,
                [
                    'AUTO_CANDIDATE',
                    'REVIEW',
                ],
                true
            );

            $canPersistCandidate =
                ! $dryRun
                && $candidateStatus
                && $dominantMlm !== null
                && $structurallyValid;

            if ($canPersistCandidate) {

                $manualProtected = $existing
                    && ! $force
                    && str_starts_with(
                        strtolower(
                            trim(
                                (string) (
                                    $existing->source ?? ''
                                )
                            )
                        ),
                        'manual'
                    );

                if ($manualProtected) {

                    $dbAction =
                        'CANDIDATO_MANUAL_PROTEGIDO';

                } else {

                    $data = [
                        'meli_category_id' =>
                            $dominantMlm,

                        'meli_category_name' =>
                            $meliName !== ''
                                ? $meliName
                                : null,

                        'meli_category_path' =>
                            $meliPath !== ''
                                ? $meliPath
                                : null,

                        'confidence' =>
                            $confidence,

                        /*
                         * El auditor nunca aprueba.
                         */
                        'approved' =>
                            false,

                        'source' =>
                            $this->sourceForStatus(
                                $status
                            ),

                        'updated_at' =>
                            now(),
                    ];

                    if ($existing) {

                        DB::table(
                            'syscom_meli_category_maps'
                        )
                            ->where(
                                'id',
                                $existing->id
                            )
                            ->update($data);

                        $dbAction =
                            'CANDIDATO_ACTUALIZADO';

                    } else {

                        DB::table(
                            'syscom_meli_category_maps'
                        )
                            ->insert(
                                array_merge(
                                    [
                                        'syscom_category_id' =>
                                            $category->id,

                                        'created_at' =>
                                            now(),
                                    ],
                                    $data
                                )
                            );

                        $dbAction =
                            'CANDIDATO_CREADO';
                    }
                }

            } elseif (! $dryRun) {

                /*
                 * Si una auditoría anterior había creado un
                 * candidato automático y ahora la categoría
                 * resulta MIXED/INVALID/etc., eliminamos sólo
                 * candidatos generados por el auditor.
                 *
                 * Nunca tocamos mappings manuales.
                 */
                $auditGeneratedExisting =
                    $existing
                    && ! (bool) $existing->approved
                    && str_starts_with(
                        strtolower(
                            trim(
                                (string) (
                                    $existing->source ?? ''
                                )
                            )
                        ),
                        'audit_'
                    );

                if ($auditGeneratedExisting) {

                    DB::table(
                        'syscom_meli_category_maps'
                    )
                        ->where(
                            'id',
                            $existing->id
                        )
                        ->delete();

                    $dbAction =
                        'AUDIT_ONLY_MAP_ELIMINADO';
                }
            }

            if ($this->output->isVerbose()) {
                $this->line(
                    '  distribución: '
                    .json_encode(
                        $distribution,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    )
                );

                if ($errors !== []) {
                    $this->warn(
                        '  errores: '
                        .json_encode(
                            array_slice($errors, 0, 5),
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        )
                    );
                }

                if ($metaError !== null) {
                    $this->warn(
                        '  metadata ML: '.$metaError
                    );
                }
            }

            $results[] = [
                'syscom' =>
                    (string) $category->syscom_category_id,

                'categoria' =>
                    Str::limit(
                        (string) $category->name,
                        30,
                        ''
                    ),

                'prod' =>
                    (int) $category->productos,

                'muestra' =>
                    $sampleCount,

                'validas' =>
                    $validPredictions,

                'cobertura' =>
                    number_format(
                        $coverage,
                        1
                    ).'%',

                'dominante' =>
                    $dominantMlm ?? '-',

                'consenso' =>
                    number_format(
                        $consensus,
                        1
                    ).'%',

                'score' =>
                    $confidence,

                'estado' =>
                    $status,

                'db' =>
                    $dbAction,
            ];
        }

        $this->newLine();

        if ($results !== []) {
            $this->table(
                [
                    'SYSCOM',
                    'Categoría',
                    'Prod.',
                    'Muestra',
                    'Válidas',
                    'Cobertura',
                    'ML dominante',
                    'Consenso',
                    'Score',
                    'Estado',
                    'DB',
                ],
                $results
            );

            $statusCounts = collect($results)
                ->countBy('estado')
                ->sortKeys();

            $this->newLine();

            $this->info('Resumen:');

            foreach ($statusCounts as $status => $count) {
                $this->line(
                    '  '.$status.': '.$count
                );
            }
        }

        $this->newLine();

        $this->comment(
            'Ningún mapping fue aprobado automáticamente.'
        );

        if ($dryRun) {
            $this->comment(
                'DRY-RUN: no se modificó la base de datos.'
            );
        }

        return self::SUCCESS;
    }

    private function uniformSample(
        Collection $ids,
        int $wanted
    ): Collection {
        $total = $ids->count();

        if ($total === 0 || $wanted <= 0) {
            return collect();
        }

        if ($wanted >= $total) {
            return $ids->values();
        }

        if ($wanted === 1) {
            return collect([
                $ids->first(),
            ]);
        }

        $indexes = [];

        for ($i = 0; $i < $wanted; $i++) {
            $index = (int) round(
                $i
                * ($total - 1)
                / ($wanted - 1)
            );

            $indexes[$index] = true;
        }

        return collect(
            array_keys($indexes)
        )
            ->map(
                fn ($index) => $ids[$index]
            )
            ->values();
    }

    /**
     * @return array{
     *     category_id: string,
     *     name: string,
     *     top: array<int, array{
     *         id: string,
     *         name: string
     *     }>,
     *     error: ?string
     * }
     */
    private function predictFirstCategory(
        MeliPublishService $meli,
        User $user,
        string $title
    ): array {
        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $suggestions = $meli->suggestCategories(
                    $user,
                    $title,
                    4
                );

                if ($suggestions instanceof Collection) {
                    $suggestions = $suggestions->all();
                }

                $suggestions = is_array($suggestions)
                    ? $suggestions
                    : [];

                /*
                 * Guardamos las alternativas devueltas por
                 * esta misma llamada al predictor.
                 */
                $top = collect($suggestions)
                    ->filter(
                        fn ($suggestion) =>
                            is_array($suggestion)
                    )
                    ->take(4)
                    ->map(function ($suggestion) {

                        $id = strtoupper(
                            trim(
                                (string) (
                                    $suggestion['id']
                                    ?? $suggestion['category_id']
                                    ?? ''
                                )
                            )
                        );

                        $name = trim(
                            (string) (
                                $suggestion['name']
                                ?? $suggestion['category_name']
                                ?? ''
                            )
                        );

                        return [
                            'id' => $id,
                            'name' => $name,
                        ];
                    })
                    ->filter(
                        fn ($suggestion) =>
                            preg_match(
                                '/^MLM\d+$/',
                                $suggestion['id']
                            ) === 1
                    )
                    ->values()
                    ->all();

                $first = $suggestions[0] ?? null;

                if (! is_array($first)) {
                    return [
                        'category_id' => '',
                        'name' => '',
                        'top' => $top,
                        'error' => null,
                    ];
                }

                $categoryId = strtoupper(
                    trim(
                        (string) (
                            $first['id']
                            ?? $first['category_id']
                            ?? ''
                        )
                    )
                );

                $name = trim(
                    (string) (
                        $first['name']
                        ?? $first['category_name']
                        ?? ''
                    )
                );

                if (
                    $categoryId !== ''
                    && ! preg_match(
                        '/^MLM\d+$/',
                        $categoryId
                    )
                ) {
                    return [
                        'category_id' => '',
                        'name' => '',
                        'top' => $top,
                        'error' =>
                            'Predictor devolvió ID inválido: '
                            .$categoryId,
                    ];
                }

                return [
                    'category_id' =>
                        $categoryId,

                    'name' =>
                        $name,

                    'top' =>
                        $top,

                    'error' =>
                        null,
                ];

            } catch (Throwable $e) {

                $lastError = $e->getMessage();

                if (
                    $attempt >= 3
                    || ! $this->isRetryableError(
                        $lastError
                    )
                ) {
                    break;
                }

                sleep($attempt);
            }
        }

        return [
            'category_id' =>
                '',

            'name' =>
                '',

            'top' =>
                [],

            'error' =>
                $lastError
                ?? 'Error desconocido del predictor.',
        ];
    }

    private function isRetryableError(
        string $message
    ): bool {
        $message = strtolower($message);

        foreach ([
            '429',
            'too many requests',
            '500',
            '502',
            '503',
            '504',
            'timeout',
            'timed out',
            'temporarily unavailable',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function auditStatus(
        float $consensus,
        float $coverage,
        int $validPredictions
    ): string {
        if ($validPredictions === 0) {
            return 'NO_PREDICTION';
        }

        if (
            $consensus >= 95.0
            && $coverage >= 80.0
        ) {
            return 'AUTO_CANDIDATE';
        }

        if (
            $consensus >= 70.0
            && $coverage >= 60.0
        ) {
            return 'REVIEW';
        }

        return 'MIXED';
    }

    private function sourceForStatus(
        string $status
    ): string {
        return match ($status) {
            'AUTO_CANDIDATE' =>
                'audit_auto_candidate',

            'REVIEW' =>
                'audit_review',

            'MIXED' =>
                'audit_mixed',

            default =>
                'audit_'.$this->normalizeSource(
                    $status
                ),
        };
    }

    private function normalizeSource(
        string $value
    ): string {
        $value = strtolower($value);

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $value
        ) ?? '';

        return trim($value, '_');
    }
}
