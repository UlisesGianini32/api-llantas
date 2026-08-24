<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\IndexAutomotivePartMeliCategoryRequest;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliCategory;
use App\Services\Autopartes\Meli\AutomotivePartMeliConfiguration;
use App\Services\Autopartes\Meli\AutomotivePartMeliRequestBudget;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class AutomotivePartMeliCategoryController extends Controller
{
    public function index(
        IndexAutomotivePartMeliCategoryRequest $request,
        AutomotivePartMeliConfiguration $configuration,
        AutomotivePartMeliRequestBudget $budget,
    ): Response {
        $query = AutomotivePart::query()->with([
            'enrichmentReview',
            'meliReadiness.approvedCategoryCandidate',
            'meliCategoryCandidates' => fn ($candidate) => $candidate->latest()->limit(5),
        ]);

        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(fn (Builder $builder) => $builder
                ->where('item_number', 'like', $search)
                ->orWhere('manufacturer_part_number', 'like', $search)
                ->orWhere('description_original', 'like', $search));
        }
        if ($request->filled('internal_category')) {
            $query->where('category', $request->input('internal_category'));
        }
        if ($request->filled('status')) {
            $query->whereHas('meliReadiness', fn ($readiness) => $readiness->where('status', $request->input('status')));
        }

        return Inertia::render('Autopartes/CategoriasMeli', [
            'parts' => $query->orderBy('id')->paginate((int) $request->input('per_page', 25))->withQueryString(),
            'filters' => $request->validated(),
            'internalCategories' => AutomotivePart::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'meli' => array_merge($configuration->publicSettings(), ['daily_remaining' => $budget->remaining()]),
        ]);
    }

    public function show(
        AutomotivePart $automotivePart,
        AutomotivePartMeliConfiguration $configuration,
        AutomotivePartMeliRequestBudget $budget,
    ): Response {
        $automotivePart->load([
            'enrichmentReview',
            'meliCategoryCandidates' => fn ($query) => $query->with('reviewer')->latest(),
            'meliReadiness.approvedCategoryCandidate',
            'meliReadiness.reviewer',
        ]);
        $categoryIds = $automotivePart->meliCategoryCandidates->pluck('category_id')->unique();
        $categories = AutomotivePartMeliCategory::query()
            ->with('attributeRequirements')
            ->whereIn('category_id', $categoryIds)
            ->get()
            ->keyBy('category_id');

        return Inertia::render('Autopartes/CategoriaMeliDetalle', [
            'part' => $automotivePart,
            'categories' => $categories,
            'meli' => array_merge($configuration->publicSettings(), ['daily_remaining' => $budget->remaining()]),
        ]);
    }
}
