<?php

namespace App\Console\Commands;

use App\Models\SyscomProduct;
use App\Services\SyscomApiService;
use App\Support\SyscomHermosilloStock;
use Illuminate\Console\Command;

class SyscomInspectExistenciaCommand extends Command
{
    protected $signature = 'syscom:inspect-existencia
                            {--id= : syscom_producto_id (si no se da, toma los primeros)}
                            {--branch= : Nombre de sucursal (default: config syscom.sucursal_nombre)}
                            {--limit=5 : Cuántos productos inspeccionar si no hay --id}
                            {--with-stock : Solo productos con total_existencia>0}
                            {--show-raw : Vuelca también raw_detail (puede ser largo)}
                            {--live : Consulta la API de SYSCOM en vivo (muestra existencia real por sucursal)}';

    protected $description = 'Muestra la estructura JSON del bloque existencia y el stock que el parser extrae para la sucursal';

    public function handle(SyscomApiService $api): int
    {
        $branchName = trim((string) ($this->option('branch') ?: config('syscom.sucursal_nombre', 'hermosillo')));
        $limit = max(1, (int) $this->option('limit'));
        $id = $this->option('id');

        if ($this->option('live')) {
            return $this->handleLive($api, $branchName, $id);
        }

        $q = SyscomProduct::query();
        if ($id) {
            $q->where('syscom_producto_id', (int) $id);
        } else {
            if ($this->option('with-stock')) {
                $q->where('total_existencia', '>', 0);
            }
            $q->orderByDesc('id')->limit($limit);
        }

        $products = $q->get();
        if ($products->isEmpty()) {
            $this->warn('Sin productos en BD que coincidan.');

            $totalCount = SyscomProduct::query()->count();
            $withStockCount = SyscomProduct::query()->where('total_existencia', '>', 0)->count();
            $withExistencia = SyscomProduct::query()->whereNotNull('existencia')->where('existencia', '!=', '[]')->where('existencia', '!=', '{}')->count();
            $this->line('');
            $this->line('Resumen BD:');
            $this->line("  syscom_products totales:           {$totalCount}");
            $this->line("  con total_existencia>0:            {$withStockCount}");
            $this->line("  con existencia poblada (no vacío): {$withExistencia}");

            return self::SUCCESS;
        }

        foreach ($products as $p) {
            $this->line('');
            $this->line(str_repeat('=', 78));
            $this->line(sprintf(
                '#%d  syscom_id=%d  marca=%s  total_existencia=%d  stock_hermosillo=%d  last_synced=%s',
                $p->id,
                $p->syscom_producto_id,
                (string) $p->marca,
                (int) $p->total_existencia,
                (int) $p->stock_hermosillo,
                (string) ($p->last_synced_at ?? '—')
            ));
            $this->line('Título: '.mb_substr((string) $p->titulo, 0, 100));
            $existencia = is_array($p->existencia) ? $p->existencia : [];

            $this->line('existencia top keys: '.($existencia === [] ? '(vacío)' : implode(', ', array_map('strval', array_keys($existencia)))));

            if ($existencia !== []) {
                $this->line('Estructura (3 niveles):');
                $this->dumpStruct($existencia, 1, 3);
            } elseif ($this->option('show-raw')) {
                $rawDetail = is_array($p->raw_detail) ? $p->raw_detail : [];
                if ($rawDetail === []) {
                    $this->line('raw_detail también está vacío → este producto se sincronizó sin --with-detail.');
                } else {
                    $this->line('raw_detail keys: '.implode(', ', array_map('strval', array_keys($rawDetail))));
                    if (isset($rawDetail['existencia']) && is_array($rawDetail['existencia'])) {
                        $this->line('raw_detail.existencia:');
                        $this->dumpStruct($rawDetail['existencia'], 1, 3);
                    }
                }
            } else {
                $this->line('(usá --show-raw para ver el raw_detail)');
            }

            $stock = SyscomHermosilloStock::forBranch($existencia, '', $branchName);
            $this->line(sprintf('=> Parser (branch=%s): %d', $branchName, $stock));
        }

        return self::SUCCESS;
    }

    private function handleLive(SyscomApiService $api, string $branchName, mixed $id): int
    {
        if (! $id) {
            $this->error('Para --live necesitás --id=SYSCOM_PRODUCTO_ID.');

            return self::FAILURE;
        }

        try {
            $token = $api->getAccessToken();
        } catch (\Throwable $e) {
            $this->error('No se pudo autenticar con SYSCOM: '.$e->getMessage());

            return self::FAILURE;
        }

        $branches = $api->getBranches($token);
        $this->line('Sucursales en tu cuenta SYSCOM ('.count($branches).'):');
        foreach ($branches as $b) {
            if (! is_array($b)) {
                continue;
            }
            $this->line(sprintf(
                '   codigo=%-8s nombre=%s',
                (string) ($b['codigo'] ?? '—'),
                (string) ($b['nombre_sucursal'] ?? $b['nombre'] ?? '—')
            ));
        }

        $branchCode = $api->resolveBranchCodeByName($token, $branchName);
        $this->line('');
        $this->line('Sucursal buscada:  '.$branchName);
        $this->line('Código resuelto:   '.($branchCode ?? '(no encontrado en /carrito/sucursales)'));

        if (! $branchCode) {
            $this->warn('⚠ SYSCOM no tiene una sucursal que coincida con "'.$branchName.'".');
            $this->warn('  Si Hermosillo no existe en tu cuenta, el stock SIEMPRE debe ser 0 y la publicación pausada.');
        } elseif (! is_numeric(trim($branchCode))) {
            $this->warn('⚠ El código resuelto "'.$branchCode.'" NO es numérico: SYSCOM no puede filtrar por esa sucursal.');
            $this->warn('  El detalle del producto devuelve inventario NACIONAL, no de Hermosillo → stock real = 0.');
        }

        // 1) Detalle SIN filtro de sucursal (suele ser inventario nacional).
        $this->line('');
        $this->line(str_repeat('-', 60));
        $this->line('A) GET /productos/'.$id.'  (SIN ?sucursal)');
        try {
            $national = $api->getProductRaw($token, (int) $id, null);
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
        $natExist = is_array($national['existencia'] ?? null) ? $national['existencia'] : [];
        $natTotal = (int) ($national['total_existencia'] ?? 0);
        $this->line('total_existencia: '.$natTotal);
        $this->line('existencia keys: '.($natExist === [] ? '(vacío)' : implode(', ', array_map('strval', array_keys($natExist)))));
        if ($natExist !== []) {
            $this->dumpStruct($natExist, 1, 3);
        }

        // 2) Detalle CON ?sucursal=<code> (lo que importa para Hermosillo).
        $this->line('');
        $this->line(str_repeat('-', 60));
        $this->line('B) GET /productos/'.$id.'?sucursal='.($branchCode ?: '(sin código)'));
        $scopedExist = [];
        $scopedTotal = 0;
        if ($branchCode) {
            try {
                $scoped = $api->getProductRaw($token, (int) $id, $branchCode);
            } catch (\Throwable $e) {
                $this->error('Error: '.$e->getMessage());
                $scoped = [];
            }
            $scopedExist = is_array($scoped['existencia'] ?? null) ? $scoped['existencia'] : [];
            $scopedTotal = (int) ($scoped['total_existencia'] ?? 0);
            $this->line('total_existencia: '.$scopedTotal);
            $this->line('existencia keys: '.($scopedExist === [] ? '(vacío)' : implode(', ', array_map('strval', array_keys($scopedExist)))));
            if ($scopedExist !== []) {
                $this->dumpStruct($scopedExist, 1, 3);
            }
        } else {
            $this->warn('Sin código de sucursal, no se puede filtrar.');
        }

        // 3) Buscador filtrado por sucursal (sucursal=hermosillo&stock=1) — método correcto.
        $this->line('');
        $this->line(str_repeat('-', 60));
        $modelo = '';
        $dbProduct = SyscomProduct::query()->where('syscom_producto_id', (int) $id)->first();
        if ($dbProduct) {
            $modelo = trim((string) ($dbProduct->modelo ?: $dbProduct->titulo));
        }
        $this->line('C) GET /productos?sucursal='.($branchCode ?: '—').'&stock=1&busqueda='.$modelo);
        $searchFound = false;
        $searchStock = 0;
        if ($branchCode && $modelo !== '') {
            $hit = $api->findProductInBranchSearch($token, $branchCode, (int) $id, $modelo);
            if ($hit !== null) {
                $searchFound = (bool) $hit['found'];
                $searchStock = (int) $hit['total_existencia'];
                $this->line('¿Aparece en sucursal '.$branchCode.' con stock>0?  '.($searchFound ? 'SÍ' : 'NO'));
                $this->line('total_existencia en ese resultado: '.$searchStock);
                $existHit = is_array($hit['existencia']) ? $hit['existencia'] : [];
                $this->line('existencia keys: '.($existHit === [] ? '(vacío)' : implode(', ', array_map('strval', array_keys($existHit)))));
                if ($existHit !== []) {
                    $this->dumpStruct($existHit, 1, 3);
                }
            }
        } else {
            $this->warn('Falta código de sucursal o modelo en BD para probar el buscador.');
        }

        // Interpretación: si B) difiere de A), el filtro por sucursal SÍ funciona.
        $stockScopedTrusted = SyscomHermosilloStock::forBranch($scopedExist, (string) $branchCode, $branchName, true);
        $stockByName = SyscomHermosilloStock::forBranch($natExist, (string) $branchCode, $branchName);

        $this->line('');
        $this->line(str_repeat('=', 60));
        $this->line('DIAGNÓSTICO:');
        $detailWorks = $branchCode && ($scopedTotal !== $natTotal || $scopedExist !== $natExist);
        if ($detailWorks) {
            $this->info('  [Detalle] El filtro ?sucursal='.$branchCode.' SÍ cambia la respuesta.');
            $this->info('  => Stock Hermosillo (detalle filtrado): '.$stockScopedTrusted);
        } else {
            $this->warn('  [Detalle] ?sucursal NO cambia la respuesta (devuelve nacional). No sirve para stock por sucursal.');
        }
        $this->line('');
        if ($branchCode && $modelo !== '') {
            if ($searchFound) {
                $this->info('  [Buscador] El producto SÍ aparece en '.$branchCode.' con stock>0.');
                $this->info('  => Stock por buscador: '.$searchStock.($searchStock === $natTotal ? '  (¡ojo! = nacional, total_existencia no es por sucursal)' : '  (parece stock real de la sucursal)'));
            } else {
                $this->info('  [Buscador] El producto NO aparece en '.$branchCode.' con stock>0 → stock Hermosillo = 0 → PAUSAR.');
            }
        }
        $this->line(str_repeat('=', 60));

        return self::SUCCESS;
    }

    private function dumpStruct(array $data, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth) {
            $this->line(str_repeat('  ', $depth).'… (truncado)');
            return;
        }

        foreach ($data as $k => $v) {
            $prefix = str_repeat('  ', $depth);
            if (is_array($v)) {
                if ($v === []) {
                    $this->line($prefix.$k.': []');
                } else {
                    $this->line($prefix.$k.': {');
                    $this->dumpStruct($v, $depth + 1, $maxDepth);
                    $this->line($prefix.'}');
                }
            } else {
                $val = is_scalar($v) ? (string) $v : gettype($v);
                $this->line($prefix.$k.': '.$val);
            }
        }
    }
}
