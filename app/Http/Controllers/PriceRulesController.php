<?php

namespace App\Http\Controllers;

use App\Models\PriceRule;
use App\Services\FormulaEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PriceRulesController extends Controller
{
    public function index(): Response
    {
        $defaultsLlantas = [
            'llanta' => 'costo * 1.5',
            'par' => '(costo * 2) * 1.5',
            'juego4' => '(costo * 4) * 1.45',
        ];

        $defaultsSyscom = [
            'llanta' => 'costo * 1.12',
            'par' => '(costo * 2) * 1.12',
            'juego4' => '(costo * 4) * 1.10',
        ];

        foreach ($defaultsLlantas as $scope => $formula) {
            PriceRule::firstOrCreate(
                ['rule_set' => 'llantas', 'scope' => $scope],
                ['formula' => $formula, 'active' => true]
            );
        }
        foreach ($defaultsSyscom as $scope => $formula) {
            PriceRule::firstOrCreate(
                ['rule_set' => 'syscom', 'scope' => $scope],
                ['formula' => $formula, 'active' => true]
            );
        }

        $rules = PriceRule::orderBy('rule_set')
            ->orderByRaw("
            CASE scope
                WHEN 'juego4' THEN 1
                WHEN 'llanta' THEN 2
                WHEN 'par' THEN 3
                ELSE 99
            END
        ")->get();

        return Inertia::render('PriceRules/Index', [
            'rules' => $rules->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'rule_set' => $rule->rule_set ?? 'llantas',
                    'scope' => $rule->scope,
                    'formula' => $rule->formula,
                    'active' => (bool) $rule->active,
                ];
            })->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'rules' => 'required|array',
            'rules.*.rule_set' => 'required|string|in:llantas,syscom',
            'rules.*.scope' => 'required|string|in:llanta,par,juego4',
            'rules.*.formula' => 'required|string|min:1|max:255',
            'rules.*.active' => 'nullable|boolean',
        ]);

        foreach ($request->rules as $r) {
            PriceRule::updateOrCreate(
                [
                    'rule_set' => $r['rule_set'],
                    'scope' => $r['scope'],
                ],
                [
                    'formula' => $r['formula'],
                    'active' => (bool) ($r['active'] ?? false),
                ]
            );
        }

        return back()->with('ok', 'Fórmulas actualizadas.');
    }

    public function test(Request $request, FormulaEngine $engine): RedirectResponse
    {
        $request->validate([
            'rule_set' => 'required|in:llantas,syscom',
            'scope' => 'required|in:llanta,par,juego4',
            'costo' => 'required|numeric|min:0',
        ]);

        $rule = PriceRule::where('rule_set', $request->rule_set)
            ->where('scope', $request->scope)
            ->first();

        if (! $rule) {
            return back()->with('err', 'No existe regla para ese conjunto y alcance.');
        }

        $piezas = match ($request->scope) {
            'par' => 2,
            'juego4' => 4,
            default => 1,
        };

        try {
            $result = $engine->evaluate($rule->formula, [
                'costo' => (float) $request->costo,
                'piezas' => $piezas,
            ]);
        } catch (\Throwable $e) {
            return back()->with('err', 'Error en fórmula: '.$e->getMessage());
        }

        return back()->with('ok', 'Resultado: '.number_format($result, 2));
    }
}
