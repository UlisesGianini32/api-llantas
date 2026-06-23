<?php

namespace App\Console\Commands;

use App\Models\SyscomProduct;
use App\Services\SyscomApiService;
use App\Services\SyscomProductPricingService;
use Illuminate\Console\Command;

class SyscomInspectPreciosCommand extends Command
{
    protected $signature = 'syscom:inspect-precios
                            {--id= : syscom_producto_id (obligatorio si --live)}
                            {--live : Llama a SYSCOM al vuelo en lugar de leer desde BD}
                            {--limit=3 : Cuántos productos mostrar si no hay --id}';

    protected $description = 'Vuelca los precios crudos de SYSCOM (BD o API en vivo) y compara con la fórmula configurada.';

    public function handle(SyscomApiService $api, SyscomProductPricingService $pricing): int
    {
        $live = (bool) $this->option('live');
        $id = $this->option('id');
        $limit = max(1, (int) $this->option('limit'));

        $products = collect();
        if ($id) {
            $products = SyscomProduct::query()->where('syscom_producto_id', (int) $id)->limit(1)->get();
        } else {
            $products = SyscomProduct::query()->orderByDesc('id')->limit($limit)->get();
        }

        if ($products->isEmpty()) {
            $this->warn('Sin productos en BD. Probá --id=12345 después de un sync.');
            return self::SUCCESS;
        }

        $token = null;
        if ($live) {
            try {
                $token = $api->getAccessToken();
            } catch (\Throwable $e) {
                $this->error('No se pudo obtener token SYSCOM: '.$e->getMessage());
                return self::FAILURE;
            }
        }

        foreach ($products as $p) {
            $this->line('');
            $this->line(str_repeat('=', 78));
            $this->line(sprintf(
                '#%d  syscom_id=%d  marca=%s',
                $p->id,
                $p->syscom_producto_id,
                (string) $p->marca
            ));
            $this->line('Título: '.mb_substr((string) $p->titulo, 0, 100));

            $this->line('');
            $this->line('-- BD --');
            $this->line(sprintf(
                '  precio_lista    = %s',
                $this->fmt($p->precio_lista)
            ));
            $this->line(sprintf(
                '  precio_especial = %s',
                $this->fmt($p->precio_especial)
            ));
            $this->line(sprintf(
                '  precio_descuento= %s',
                $this->fmt($p->precio_descuento)
            ));

            $rawDetail = is_array($p->raw_detail) ? $p->raw_detail : [];
            if (isset($rawDetail['precios']) && is_array($rawDetail['precios'])) {
                $this->line('  raw_detail.precios:');
                foreach ($rawDetail['precios'] as $k => $v) {
                    $this->line('    '.$k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
                }
            } else {
                $this->line('  raw_detail.precios: (vacío)');
            }
            foreach (['tipo_cambio', 'moneda', 'currency', 'tc', 'precio_especial_tc', 'precio_lista_tc'] as $extra) {
                if (isset($rawDetail[$extra])) {
                    $this->line('  raw_detail.'.$extra.' = '.(is_scalar($rawDetail[$extra]) ? (string) $rawDetail[$extra] : json_encode($rawDetail[$extra], JSON_UNESCAPED_UNICODE)));
                }
            }

            if ($live && $token) {
                $this->line('');
                $this->line('-- LIVE (API SYSCOM) --');
                try {
                    $detail = $api->getProduct($token, $p->syscom_producto_id);
                    if (! is_array($detail)) {
                        $this->warn('  Respuesta no es array.');
                    } else {
                        if (isset($detail['precios']) && is_array($detail['precios'])) {
                            $this->line('  precios:');
                            foreach ($detail['precios'] as $k => $v) {
                                $this->line('    '.$k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
                            }
                        }
                        foreach (['tipo_cambio', 'moneda', 'currency', 'tc'] as $extra) {
                            if (isset($detail[$extra])) {
                                $this->line('  '.$extra.' = '.(is_scalar($detail[$extra]) ? (string) $detail[$extra] : json_encode($detail[$extra], JSON_UNESCAPED_UNICODE)));
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn('  Falló getProduct: '.$e->getMessage());
                }

                $this->line('');
                $this->line('-- TIPO DE CAMBIO SYSCOM --');
                try {
                    $tc = $api->getTipoCambio($token);
                    foreach ($tc as $k => $v) {
                        $this->line('  '.$k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
                    }
                } catch (\Throwable $e) {
                    $this->warn('  Falló /tipocambio: '.$e->getMessage());
                }
            }

            $this->line('');
            $this->line('-- CONVERSIÓN A MXN --');
            try {
                $tc = $api->getTipoCambioMxn();
                $iva = (float) config('syscom.iva_pct', 16);
                $kind = (string) config('syscom.tc_kind', 'preferencial');
                $costoUsd = (float) ($p->precio_lista ?? 0);
                $costoMxn = $pricing->usdToMxnWithIva($costoUsd);

                $this->line(sprintf('  precio_lista (USD)  = %.4f', $costoUsd));
                $this->line(sprintf('  tipo_cambio (%s)    = %.4f', $kind, $tc));
                $this->line(sprintf('  iva_pct             = %.2f%%', $iva));
                $this->line(sprintf('  costo MXN c/IVA     = %.2f  (%.4f * %.4f * %.4f)', $costoMxn, $costoUsd, $tc, 1 + $iva / 100));
            } catch (\Throwable $e) {
                $this->warn('  conversión falló: '.$e->getMessage());
            }

            $this->line('');
            $this->line('-- FÓRMULA APLICADA --');
            foreach (['llanta', 'par', 'juego4'] as $scope) {
                try {
                    $price = $pricing->priceFor($p, $scope);
                    $this->line(sprintf(
                        '  priceFor(%-7s) = %.2f',
                        $scope,
                        $price
                    ));
                } catch (\Throwable $e) {
                    $this->warn('  priceFor('.$scope.') falló: '.$e->getMessage());
                }
            }
        }

        return self::SUCCESS;
    }

    private function fmt(mixed $v): string
    {
        if ($v === null) {
            return '(null)';
        }
        return is_numeric($v) ? number_format((float) $v, 2) : (string) $v;
    }
}
