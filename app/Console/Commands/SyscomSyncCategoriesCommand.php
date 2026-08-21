<?php

namespace App\Console\Commands;

use App\Services\SyscomApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SyscomSyncCategoriesCommand extends Command
{
    protected $signature = 'syscom:sync-categories
                            {--no-link : Descarga categorías pero no enlaza productos}';

    protected $description = 'Descarga el árbol oficial de categorías SYSCOM y lo vincula con syscom_products';

    public function handle(SyscomApiService $api): int
    {
        $this->info('Obteniendo token SYSCOM...');

        $token = $api->getAccessToken();

        $this->info('Descargando categorías raíz...');

        try {
            $roots = $this->getJson(
                'https://developers.syscom.mx/api/v1/categorias',
                $token
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($roots)) {
            $this->error('Respuesta inválida de SYSCOM.');

            return self::FAILURE;
        }

        $queue = [];

        foreach ($roots as $root) {
            if (! is_array($root)) {
                continue;
            }

            $id = trim((string) ($root['id'] ?? ''));

            if ($id !== '') {
                $queue[] = $id;
            }
        }

        $visited = [];
        $saved = 0;
        $failed = 0;

        while ($queue !== []) {
            $id = (string) array_shift($queue);

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;

            try {
                $detail = $this->getJson(
                    'https://developers.syscom.mx/api/v1/categorias/'.$id,
                    $token
                );
            } catch (Throwable $e) {
                $failed++;

                $this->warn(
                    "Categoría {$id}: ".$e->getMessage()
                );

                continue;
            }

            if (! is_array($detail)) {
                $failed++;
                continue;
            }

            $categoryId = trim((string) ($detail['id'] ?? $id));
            $name = trim((string) ($detail['nombre'] ?? ''));
            $level = (int) ($detail['nivel'] ?? 0);

            $origin = is_array($detail['origen'] ?? null)
                ? $detail['origen']
                : [];

            $parentId = null;
            $pathNames = [];

            foreach ($origin as $parent) {
                if (! is_array($parent)) {
                    continue;
                }

                $parentName = trim((string) ($parent['nombre'] ?? ''));

                if ($parentName !== '') {
                    $pathNames[] = $parentName;
                }

                $parentSyscomId = trim(
                    (string) ($parent['id'] ?? '')
                );

                if ($parentSyscomId !== '') {
                    $parentId = $parentSyscomId;
                }
            }

            if ($name !== '') {
                $pathNames[] = $name;
            }

            $path = implode(' > ', $pathNames);

            DB::table('syscom_categories')->updateOrInsert(
                [
                    'syscom_category_id' => $categoryId,
                ],
                [
                    'name' => $name !== '' ? $name : $categoryId,
                    'level' => $level > 0 ? $level : null,
                    'parent_syscom_category_id' => $parentId,
                    'path' => $path !== '' ? $path : null,
                    'raw' => json_encode(
                        $detail,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $saved++;

            $subs = is_array($detail['subcategorias'] ?? null)
                ? $detail['subcategorias']
                : [];

            foreach ($subs as $sub) {
                if (is_array($sub)) {
                    $subId = trim((string) ($sub['id'] ?? ''));
                } else {
                    $subId = trim((string) $sub);
                }

                if ($subId !== '' && ! isset($visited[$subId])) {
                    $queue[] = $subId;
                }
            }

            if ($saved % 50 === 0) {
                $this->line(
                    "  {$saved} categorías guardadas..."
                );
            }

            /*
             * Evita golpear demasiado rápido la API.
             */
            usleep(120000);
        }

        $this->newLine();

        $this->info("Categorías guardadas: {$saved}");

        if ($failed > 0) {
            $this->warn("Categorías con error: {$failed}");
        }

        if ($this->option('no-link')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Relacionando productos con categorías SYSCOM...');

        $result = $this->linkProducts();

        $this->newLine();
        $this->info(
            'Productos relacionados: '.$result['linked']
        );

        $this->line(
            'Relaciones producto/categoría: '.$result['relations']
        );

        $this->warn(
            'Productos sin categoría: '.$result['missing']
        );

        return self::SUCCESS;
    }

    private function getJson(string $url, string $token): array
    {
        $last = '';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($url);

            if ($response->successful()) {
                $json = $response->json();

                if (! is_array($json)) {
                    throw new RuntimeException(
                        'SYSCOM devolvió JSON inválido en '.$url
                    );
                }

                return $json;
            }

            $last = 'HTTP '.$response->status().' '.
                mb_substr($response->body(), 0, 300);

            if (
                $response->status() === 429
                || $response->status() >= 500
            ) {
                sleep($attempt);
                continue;
            }

            break;
        }

        throw new RuntimeException(
            'Error consultando SYSCOM: '.$last
        );
    }

    /**
     * @return array{linked:int,relations:int,missing:int}
     */
    private function linkProducts(): array
    {
        $categories = DB::table('syscom_categories')
            ->pluck('id', 'syscom_category_id')
            ->mapWithKeys(
                fn ($id, $syscomId) => [(string) $syscomId => (int) $id]
            )
            ->all();

        $linked = 0;
        $relations = 0;
        $missing = 0;

        DB::table('syscom_products')
            ->select(
                'id',
                'modelo',
                'categorias',
                'raw_list'
            )
            ->orderBy('id')
            ->chunkById(250, function ($products) use (
                &$categories,
                &$linked,
                &$relations,
                &$missing
            ) {
                foreach ($products as $product) {
                    $raw = $this->decode($product->raw_list ?? null);
                    $fallback = $this->decode($product->categorias ?? null);

                    $paths = $this->extractPaths($raw, $fallback);

                    if ($paths === []) {
                        $missing++;

                        DB::table('syscom_products')
                            ->where('id', $product->id)
                            ->update([
                                'syscom_primary_category_id' => null,
                            ]);

                        continue;
                    }

                    DB::table('syscom_product_category')
                        ->where('syscom_product_id', $product->id)
                        ->delete();

                    $primaryInternalId = null;
                    $seenLeafs = [];

                    foreach ($paths as $pathIndex => $path) {
                        $normalized = $this->normalizePath($path);

                        if ($normalized === []) {
                            continue;
                        }

                        $leaf = $normalized[count($normalized) - 1];

                        $syscomCategoryId = trim(
                            (string) ($leaf['id'] ?? '')
                        );

                        if ($syscomCategoryId === '') {
                            continue;
                        }

                        if (isset($seenLeafs[$syscomCategoryId])) {
                            continue;
                        }

                        $seenLeafs[$syscomCategoryId] = true;

                        $internalId = $categories[$syscomCategoryId] ?? null;

                        /*
                         * Si por alguna razón una categoría viene en un
                         * producto pero no apareció en el árbol oficial,
                         * la conservamos en nuestro catálogo.
                         */
                        if (! $internalId) {
                            $pathText = implode(
                                ' > ',
                                array_map(
                                    fn ($c) => (string) ($c['nombre'] ?? ''),
                                    $normalized
                                )
                            );

                            $parent = count($normalized) >= 2
                                ? $normalized[count($normalized) - 2]
                                : null;

                            $internalId = DB::table('syscom_categories')
                                ->insertGetId([
                                    'syscom_category_id' => $syscomCategoryId,
                                    'name' => trim(
                                        (string) ($leaf['nombre'] ?? $syscomCategoryId)
                                    ),
                                    'level' => (int) ($leaf['nivel'] ?? 0) ?: null,
                                    'parent_syscom_category_id' => is_array($parent)
                                        ? trim((string) ($parent['id'] ?? '')) ?: null
                                        : null,
                                    'path' => $pathText !== ''
                                        ? $pathText
                                        : null,
                                    'raw' => json_encode(
                                        $leaf,
                                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    ),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                            $categories[$syscomCategoryId] = $internalId;
                        }

                        $isPrimary = $primaryInternalId === null;

                        DB::table('syscom_product_category')
                            ->insertOrIgnore([
                                'syscom_product_id' => $product->id,
                                'syscom_category_id' => $internalId,
                                'is_primary' => $isPrimary,
                                'source' => 'syscom_raw',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                        $relations++;

                        if ($isPrimary) {
                            $primaryInternalId = $internalId;
                        }
                    }

                    if ($primaryInternalId !== null) {
                        DB::table('syscom_products')
                            ->where('id', $product->id)
                            ->update([
                                'syscom_primary_category_id' => $primaryInternalId,
                            ]);

                        $linked++;
                    } else {
                        $missing++;
                    }
                }
            });

        return [
            'linked' => $linked,
            'relations' => $relations,
            'missing' => $missing,
        ];
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? $decoded
            : [];
    }

    /**
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function extractPaths(array $raw, array $fallback): array
    {
        $paths = [];

        $all = $raw['categorias_producto_todas'] ?? null;

        if (is_array($all)) {
            foreach ($all as $path) {
                if (is_array($path) && $path !== []) {
                    $paths[] = $path;
                }
            }
        }

        if (
            $paths === []
            && isset($raw['categorias'])
            && is_array($raw['categorias'])
            && $raw['categorias'] !== []
        ) {
            $paths[] = $raw['categorias'];
        }

        if ($paths === [] && $fallback !== []) {
            /*
             * La columna puede contener directamente un path
             * o una lista de paths.
             */
            if (
                isset($fallback[0])
                && is_array($fallback[0])
                && isset($fallback[0][0])
                && is_array($fallback[0][0])
            ) {
                foreach ($fallback as $path) {
                    if (is_array($path)) {
                        $paths[] = $path;
                    }
                }
            } else {
                $paths[] = $fallback;
            }
        }

        return $paths;
    }

    /**
     * @param array<int,mixed> $path
     * @return array<int,array<string,mixed>>
     */
    private function normalizePath(array $path): array
    {
        $result = [];

        foreach ($path as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = trim((string) ($category['id'] ?? ''));
            $name = trim(
                (string) (
                    $category['nombre']
                    ?? $category['name']
                    ?? ''
                )
            );

            if ($id === '' && $name === '') {
                continue;
            }

            $result[] = [
                'id' => $id,
                'nombre' => $name,
                'nivel' => (int) ($category['nivel'] ?? 0),
            ];
        }

        usort(
            $result,
            fn ($a, $b) =>
                ((int) $a['nivel']) <=> ((int) $b['nivel'])
        );

        return $result;
    }
}
