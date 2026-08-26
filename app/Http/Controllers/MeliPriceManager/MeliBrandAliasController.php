<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\DeleteMeliBrandAliasRequest;
use App\Http\Requests\MeliPriceManager\StoreMeliBrandAliasRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBrandAliasRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBrandAliasStatusRequest;
use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use Illuminate\Http\RedirectResponse;

class MeliBrandAliasController extends Controller
{
    public function store(StoreMeliBrandAliasRequest $request, MeliBrandGroup $brand): RedirectResponse
    {
        $data = $request->validated();
        $alias = $brand->aliases()->create([
            'alias' => $data['alias'],
            'normalized_alias' => $data['normalized_alias'],
            'match_type' => $data['match_type'],
            'priority' => $data['priority'],
            'active' => $data['active'],
        ]);

        return back()->with('success', $this->successMessage('Alias agregado correctamente.', $alias));
    }

    public function update(UpdateMeliBrandAliasRequest $request, MeliBrandAlias $alias): RedirectResponse
    {
        $data = $request->validated();
        $alias->forceFill([
            'alias' => $data['alias'],
            'normalized_alias' => $data['normalized_alias'],
            'match_type' => $data['match_type'],
            'priority' => $data['priority'],
            'active' => $data['active'],
        ])->save();

        return back()->with('success', $this->successMessage('Alias actualizado.', $alias));
    }

    public function status(UpdateMeliBrandAliasStatusRequest $request, MeliBrandAlias $alias): RedirectResponse
    {
        $alias->forceFill(['active' => $request->boolean('active')])->save();

        return back()->with('success', $alias->active ? 'Alias activado.' : 'Alias desactivado.');
    }

    public function destroy(DeleteMeliBrandAliasRequest $request, MeliBrandAlias $alias): RedirectResponse
    {
        $matchedItems = $alias->matchedItems()->count();
        $alias->delete();

        $message = 'Alias eliminado. Ninguna publicación fue eliminada.';
        if ($matchedItems > 0) {
            $message .= " {$matchedItems} referencias históricas conservaron sus metadatos y dejaron la FK en null.";
        }

        return back()->with('success', $message);
    }

    private function successMessage(string $message, MeliBrandAlias $alias): string
    {
        $conflictingBrands = MeliBrandAlias::query()
            ->where('normalized_alias', $alias->normalized_alias)
            ->where('brand_group_id', '!=', $alias->brand_group_id)
            ->with('brandGroup:id,name')
            ->get()
            ->pluck('brandGroup.name')
            ->filter()
            ->unique()
            ->values();

        if ($conflictingBrands->isEmpty()) {
            return $message.' No se reclasificaron publicaciones.';
        }

        return $message.' Advertencia: este alias también existe en '.$conflictingBrands->join(', ').
            ' y puede provocar clasificaciones ambiguas. No se reclasificaron publicaciones.';
    }
}
