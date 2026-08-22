<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\ApproveAutomotivePartEnrichmentRequest;
use App\Http\Requests\Autopartes\IndexAutomotivePartEnrichmentRequest;
use App\Http\Requests\Autopartes\RejectAutomotivePartEnrichmentRequest;
use App\Http\Requests\Autopartes\RunAutomotivePartEnrichmentAuditRequest;
use App\Http\Requests\Autopartes\UpdateAutomotivePartEnrichmentRequest;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;
use App\Services\Autopartes\AutomotivePartEnrichmentAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomotivePartEnrichmentController extends Controller
{
    public function index(IndexAutomotivePartEnrichmentRequest $request): Response
    {
        $query = AutomotivePartEnrichmentReview::query()->with('automotivePart');

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('automotivePart', function ($partQuery) use ($search) {
                $partQuery->where(function ($searchQuery) use ($search) {
                    $like = '%'.$search.'%';
                    $searchQuery->where('item_number', 'like', $like)
                        ->orWhere('manufacturer_part_number', 'like', $like)
                        ->orWhere('vendor', 'like', $like)
                        ->orWhere('description_original', 'like', $like);
                });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('issue_code')) {
            $query->whereJsonContains('issue_codes', $request->input('issue_code'));
        }

        foreach (['category', 'vendor'] as $field) {
            if ($request->filled($field)) {
                $query->whereHas('automotivePart', fn ($partQuery) => $partQuery->where($field, $request->input($field)));
            }
        }

        $reviews = $query->latest('updated_at')
            ->paginate((int) $request->input('per_page', 25))
            ->withQueryString();

        $statusTotals = AutomotivePartEnrichmentReview::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Autopartes/Enriquecimiento', [
            'reviews' => $reviews,
            'filters' => $request->validated(),
            'statusTotals' => collect(AutomotivePartEnrichmentReview::STATUSES)
                ->mapWithKeys(fn ($status) => [$status => (int) ($statusTotals[$status] ?? 0)]),
            'issueCodes' => AutomotivePartEnrichmentAuditService::ISSUE_CODES,
            'categories' => AutomotivePart::query()->whereHas('enrichmentReview')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'vendors' => AutomotivePart::query()->whereHas('enrichmentReview')->whereNotNull('vendor')->distinct()->orderBy('vendor')->pluck('vendor'),
        ]);
    }

    public function show(AutomotivePartEnrichmentReview $review): Response
    {
        $review->load(['automotivePart', 'reviewer']);

        return Inertia::render('Autopartes/EnriquecimientoDetalle', [
            'review' => $review,
            'part' => $review->automotivePart,
        ]);
    }

    public function update(UpdateAutomotivePartEnrichmentRequest $request, AutomotivePartEnrichmentReview $review): RedirectResponse
    {
        $data = $request->validated();
        $data['proposed_compatibility'] = $this->decodeJson($data['proposed_compatibility'] ?? null);
        $data['proposed_attributes'] = $this->decodeJson($data['proposed_attributes'] ?? null);
        $data['status'] = 'in_review';
        $data['enrichment_source'] = 'manual';
        $data['reviewed_by'] = null;
        $data['reviewed_at'] = null;

        $review->update($data);

        return back()->with('success', 'La propuesta de enriquecimiento fue guardada.');
    }

    public function approve(ApproveAutomotivePartEnrichmentRequest $request, AutomotivePartEnrichmentReview $review): RedirectResponse
    {
        if (blank($review->proposed_title)) {
            throw ValidationException::withMessages([
                'proposed_title' => 'Se requiere un título propuesto antes de aprobar.',
            ]);
        }

        $review->update([
            'status' => 'approved',
            'reviewer_notes' => $request->validated('reviewer_notes') ?? $review->reviewer_notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'La propuesta fue aprobada sin modificar el catálogo original.');
    }

    public function reject(RejectAutomotivePartEnrichmentRequest $request, AutomotivePartEnrichmentReview $review): RedirectResponse
    {
        $review->update([
            'status' => 'rejected',
            'reviewer_notes' => $request->validated('reviewer_notes'),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'La propuesta fue rechazada.');
    }

    public function pending(Request $request, AutomotivePartEnrichmentReview $review): RedirectResponse
    {
        $review->update([
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        return back()->with('success', 'La revisión regresó a pendiente.');
    }

    public function audit(
        RunAutomotivePartEnrichmentAuditRequest $request,
        AutomotivePartEnrichmentAuditService $service,
    ): RedirectResponse {
        $data = $request->validated();
        $stats = $service->audit(
            isset($data['limit']) ? (int) $data['limit'] : 250,
            isset($data['part_id']) ? (int) $data['part_id'] : null,
            (bool) ($data['refresh_approved'] ?? false),
        );

        return back()->with('success', sprintf(
            'Auditoría terminada: %d revisados, %d creados, %d actualizados, %d errores.',
            $stats['reviewed'],
            $stats['created'],
            $stats['updated'],
            $stats['errors'],
        ));
    }

    private function decodeJson(?string $value): ?array
    {
        return $value === null || $value === '' ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
