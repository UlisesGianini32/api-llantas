<?php

namespace App\Services;

use App\Models\PriceRule;
use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Support\SyscomPrecioExtractor;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SyscomProductPricingService
{
    public function __construct(
        protected FormulaEngine $engine,
        protected SyscomApiService $api
    ) {}

    /**
     * Convierte un costo USD de SYSCOM a MXN con IVA según config (tc_kind, iva_pct).
     */
    public function usdToMxnWithIva(float $usd): float
    {
        if ($usd <= 0) {
            return 0.0;
        }

        $tc = $this->api->getTipoCambioMxn();
        $iva = max(0.0, (float) config('syscom.iva_pct', 16));

        return round($usd * $tc * (1 + $iva / 100), 4);
    }

    public function baseCosto(SyscomProduct $p): float
    {
        $mode = (string) config('syscom.costo_base', 'min');

        $resolved = $this->resolveUsdPrecios($p);
        $lista = $resolved['precio_lista'];
        $especial = $resolved['precio_especial'];
        $desc = $resolved['precio_descuento'];

        return match ($mode) {
            'lista' => $lista,
            'especial' => $especial > 0 ? $especial : $this->firstPositive($lista, $desc),
            'descuento' => $desc,
            'min' => $this->minReasonable($lista, $especial, $desc),
            default => $this->minReasonable($lista, $especial, $desc),
        };
    }

    /**
     * “Precio en sitio / ML” (sin min): primero el especial, si viene vacío el que siga.
     */
    private function firstPositive(float ...$v): float
    {
        foreach ($v as $x) {
            if ((float) $x > 0) {
                return (float) $x;
            }
        }

        return 0.0;
    }

    /**
     * Mínimo de precios, ignorando cifras de prueba (1, 10) si hay otras mucho mayores
     * (p. ej. un campo malo en la API que baja el min a 1200 frente a 14’000 reales).
     */
    private function minReasonable(float $lista, float $especial, float $desc): float
    {
        $p = array_values(array_filter([$lista, $especial, $desc], static fn (float $x) => $x > 0));
        if ($p === []) {
            return 0.0;
        }
        sort($p);
        while (count($p) >= 2) {
            $lo = $p[0];
            $hi = $p[count($p) - 1];
            if ($lo > 0.0 && $hi / $lo > 10.0 && $lo < 5_000.0) {
                array_shift($p);
                continue;
            }
            break;
        }

        return (float) min($p);
    }

    /**
     * Ajusta precio de lista MXN después de la fórmula: piso fijo opcional + mínimo sobre costo.
     */
    protected function clampMercadoLibrePublicMx(float $formulaPriceMx, float $costoMx): float
    {
        $candidates = [$formulaPriceMx];

        $floor = (float) config('syscom.meli_public_price_floor_mxn', 0);
        if ($floor > 0) {
            $candidates[] = $floor;
        }

        $overCost = (float) config('syscom.meli_public_price_min_above_cost_mxn', 0);
        if ($overCost > 0 && $costoMx > 0) {
            $candidates[] = $costoMx + $overCost;
        }

        $out = max($candidates);
        $out = round($out, 2);

        if ($out > $formulaPriceMx + 0.005) {
            Log::debug('SYSCOM priceFor: precio subido por piso ML', [
                'antes' => $formulaPriceMx,
                'después' => $out,
                'costo_mx' => $costoMx,
                'floor_config' => $floor,
                'min_above_cost' => $overCost,
            ]);
        }

        return $out;
    }

    public function recibesEstimateConfigured(): bool
    {
        $c = config('syscom.meli_recibes_estimate', []);

        return ((float) ($c['fee_sale_pct'] ?? 0) > 0
            || (float) ($c['tax_retention_pct'] ?? 0) > 0
            || (float) ($c['shipping_absorb_mxn'] ?? 0) > 0
            || (float) ($c['financing_max_mxn'] ?? 0) > 0);
    }

    /**
     * Aprox.: precio − % comisión (cargo por venta) − % impuestos (retenciones en simulador) − envío − financiamiento.
     */
    public function estimateRecibesMercadoLibreMx(float $precioListaMxn): ?float
    {
        if ($precioListaMxn <= 0 || ! $this->recibesEstimateConfigured()) {
            return null;
        }

        $c = config('syscom.meli_recibes_estimate', []);
        $feePct = (float) ($c['fee_sale_pct'] ?? 0);
        $taxPct = (float) ($c['tax_retention_pct'] ?? 0);
        $ship = (float) ($c['shipping_absorb_mxn'] ?? 0);
        $fin = (float) ($c['financing_max_mxn'] ?? 0);

        $feeAmt = $feePct > 0 ? round($precioListaMxn * $feePct / 100, 2) : 0.0;
        $taxAmt = $taxPct > 0 ? round($precioListaMxn * $taxPct / 100, 2) : 0.0;

        return round($precioListaMxn - $feeAmt - $taxAmt - $ship - $fin, 2);
    }

    /**
     * Precio de lista mínimo para que Recibes ~ (estimado) >= costo + margen neto deseado.
     * Recibes ~ = precio×(1−fee%−tax%) − shipping − financing (igual que estimateRecibesMercadoLibreMx).
     */
    protected function minimumListPriceForEstimatedNetProfitMx(float $costoMx): ?float
    {
        $minProfit = (float) config('syscom.meli_min_estimated_net_profit_mxn', 0);
        if ($minProfit <= 0 || $costoMx <= 0 || ! $this->recibesEstimateConfigured()) {
            return null;
        }

        $c = config('syscom.meli_recibes_estimate', []);
        $feePct = (float) ($c['fee_sale_pct'] ?? 0);
        $taxPct = (float) ($c['tax_retention_pct'] ?? 0);
        $ship = (float) ($c['shipping_absorb_mxn'] ?? 0);
        $fin = (float) ($c['financing_max_mxn'] ?? 0);

        $netFrac = 1.0 - (($feePct + $taxPct) / 100.0);
        $neededRecibes = $costoMx + $minProfit;

        if ($netFrac <= 0.001) {
            Log::warning('SYSCOM min net profit: fee%+tax% >= 100, no se puede despejar precio; sólo subo por costo+margen bruto', [
                'fee_pct' => $feePct,
                'tax_pct' => $taxPct,
            ]);

            return round($neededRecibes + $ship + $fin, 2);
        }

        $minPrecio = ($neededRecibes + $ship + $fin) / $netFrac;

        return round($minPrecio, 2);
    }

    /**
     * Tras piso de lista / sobre costo: garantiza margen neto estimado (Recibes ~ − costo).
     */
    protected function clampMercadoLibreMinimumNetProfitMx(float $priceMx, float $costoMx): float
    {
        $minList = $this->minimumListPriceForEstimatedNetProfitMx($costoMx);
        if ($minList === null || $minList <= $priceMx + 0.005) {
            return round($priceMx, 2);
        }

        Log::debug('SYSCOM priceFor: precio subido por ganancia neta mínima estimada', [
            'antes' => $priceMx,
            'después' => $minList,
            'costo_mx' => $costoMx,
            'min_net_profit_config' => (float) config('syscom.meli_min_estimated_net_profit_mxn', 0),
        ]);

        return $minList;
    }

    /**
     * Costo en MXN (descuento USD → TC + IVA) igual base que usa la fórmula antes de aplicar marca arriba.
     */
    public function costoMxParaFormula(SyscomProduct $p): float
    {
        $resolved = $this->resolveUsdPrecios($p);
        $costoUsd = $resolved['precio_descuento'];
        if ($costoUsd <= 0) {
            $costoUsd = $this->baseCosto($p);
        }

        return $this->usdToMxnWithIva($costoUsd);
    }

    /**
     * Precios USD: columnas en BD; si vienen en 0, intenta raw_detail / raw_list del último sync.
     *
     * @return array{precio_lista: float, precio_especial: float, precio_descuento: float}
     */
    public function resolveUsdPrecios(SyscomProduct $p): array
    {
        $fromDb = [
            'precio_lista' => (float) ($p->precio_lista ?? 0),
            'precio_especial' => (float) ($p->precio_especial ?? 0),
            'precio_descuento' => (float) ($p->precio_descuento ?? 0),
        ];

        $item = is_array($p->raw_list) ? $p->raw_list : [];
        $detail = is_array($p->raw_detail) ? $p->raw_detail : [];

        if ($item === [] && $detail === []) {
            return $fromDb;
        }

        $fromRaw = SyscomPrecioExtractor::fromProductLike($item, $detail);

        // Si un sync pisó columnas con 0/null pero el JSON aún trae precios, usamos el mejor de ambos.
        return [
            'precio_lista' => $this->pickUsdPrecio($fromDb['precio_lista'], $fromRaw['precio_lista'], 'max'),
            'precio_especial' => $this->pickUsdPrecio($fromDb['precio_especial'], $fromRaw['precio_especial'], 'max'),
            'precio_descuento' => $this->pickUsdPrecio($fromDb['precio_descuento'], $fromRaw['precio_descuento'], 'min'),
        ];
    }

    /**
     * @param  'min'|'max'  $strategy  descuento/costo → min; lista/venta → max
     */
    private function pickUsdPrecio(float $db, float $raw, string $strategy): float
    {
        $candidates = array_values(array_filter([$db, $raw], static fn (float $v): bool => $v > 0));
        if ($candidates === []) {
            return 0.0;
        }

        return $strategy === 'min' ? (float) min($candidates) : (float) max($candidates);
    }

    /**
     * Precio de venta ML usando fórmulas con rule_set syscom.
     *
     * Costo input para la fórmula: `precio_descuento` (USD) → MXN aplicando tipo de cambio (TC) y
     * el IVA configurado (default 16%). El sitio web de SYSCOM hace el mismo cálculo.
     *
     * Si no existe regla (o el usuario nunca entró a /price-rules), se autogeneran los defaults
     * para que la fórmula se aplique siempre.
     */
    public function priceFor(SyscomProduct $p, string $scope = 'llanta', ?SyscomMeliQueue $queue = null): float
    {
        if ($queue !== null && strtolower((string) ($queue->price_mode ?? 'auto')) === 'manual') {
            $manual = (float) ($queue->desired_price ?? 0);
            if ($manual > 0) {
                return round($manual, 2);
            }

            // En MANUAL nunca volver a la fórmula en silencio (evita que meli:sync-stock suba el precio calculado).
            return 0.0;
        }

        $rule = $this->resolveRule($scope);

        $costo = $this->costoMxParaFormula($p);

        if ($costo <= 0) {
            return 0.0;
        }

        if (! $rule) {
            $p = $this->clampMercadoLibrePublicMx(round($costo, 2), $costo);

            return $this->clampMercadoLibreMinimumNetProfitMx($p, $costo);
        }

        $piezas = match ($scope) {
            'par' => 2,
            'juego4' => 4,
            default => 1,
        };

        try {
            $list = round($this->engine->evaluate($rule->formula, [
                'costo' => $costo,
                'piezas' => (float) $piezas,
            ]), 2);

            $p = $this->clampMercadoLibrePublicMx($list, $costo);

            return $this->clampMercadoLibreMinimumNetProfitMx($p, $costo);
        } catch (InvalidArgumentException) {
            $p = $this->clampMercadoLibrePublicMx(round($costo, 2), $costo);

            return $this->clampMercadoLibreMinimumNetProfitMx($p, $costo);
        }
    }

    private function resolveRule(string $scope): ?PriceRule
    {
        $defaults = [
            'llanta' => 'costo * 1.12',
            'par' => '(costo * 2) * 1.12',
            'juego4' => '(costo * 4) * 1.10',
        ];

        $rule = PriceRule::query()
            ->where('rule_set', 'syscom')
            ->where('scope', $scope)
            ->first();

        if (! $rule) {
            try {
                $rule = PriceRule::query()->create([
                    'rule_set' => 'syscom',
                    'scope' => $scope,
                    'formula' => $defaults[$scope] ?? 'costo * 1.12',
                    'active' => true,
                ]);
            } catch (\Throwable) {
                $rule = PriceRule::query()
                    ->where('rule_set', 'syscom')
                    ->where('scope', $scope)
                    ->first();
            }
        } elseif (! $rule->active) {
            // Si existe pero quedó inactiva por un seed o migración previa, la activamos.
            // En SYSCOM la fórmula siempre aplica; para no aplicar fórmula el usuario debe
            // editar `formula` a `costo` (identidad) en /price-rules.
            $rule->active = true;
            try {
                $rule->save();
            } catch (\Throwable) {
                // Si no se puede actualizar, igual la usamos esta vez.
            }
        }

        return $rule;
    }
}
