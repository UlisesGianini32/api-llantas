<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\IndexAutomotivePartRequest;
use App\Http\Requests\Autopartes\StoreAutomotivePartImportRequest;
use App\Jobs\ProcessAutomotivePartImportJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AutomotivePartController extends Controller
{
    public function index(IndexAutomotivePartRequest $request): Response
    {
        $query = AutomotivePart::query();

        if ($request->filled('item_number')) {
            $query->where('item_number', 'like', '%'.$request->input('item_number').'%');
        }

        if ($request->filled('manufacturer_part_number')) {
            $query->where('manufacturer_part_number', 'like', '%'.$request->input('manufacturer_part_number').'%');
        }

        if ($request->filled('vendor')) {
            $query->where('vendor', 'like', '%'.$request->input('vendor').'%');
        }

        if ($request->filled('category')) {
            $query->where('category', 'like', '%'.$request->input('category').'%');
        }

        if ($request->filled('subcategory')) {
            $query->where('subcategory', 'like', '%'.$request->input('subcategory').'%');
        }

        if ($request->filled('status')) {
            $query->where('data_status', $request->input('status'));
        }

        if ($request->filled('stock')) {
            $stock = (int) $request->input('stock');
            $query->where('quantity', $stock);
        }

        $sort = $request->input('sort', 'last_imported_at');
        $direction = $request->input('direction', 'desc');
        $sortableFields = ['item_number', 'manufacturer_part_number', 'vendor', 'category', 'subcategory', 'quantity', 'retail_price_original', 'last_imported_at'];

        if (! in_array($sort, $sortableFields, true)) {
            $sort = 'last_imported_at';
        }

        $parts = $query
            ->orderBy($sort, $direction)
            ->paginate((int) $request->input('per_page', 25))
            ->withQueryString();

        return Inertia::render('Autopartes/Index', [
            'parts' => $parts,
            'filters' => $request->validated(),
            'summary' => [
                'count' => AutomotivePart::query()->count(),
                'incomplete' => AutomotivePart::query()->where('data_status', 'incomplete')->count(),
                'duplicate' => AutomotivePart::query()->whereNotNull('source_key')->count(),
            ],
        ]);
    }

    public function imports(): Response
    {
        $imports = AutomotivePartImport::query()
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Autopartes/Importaciones', [
            'imports' => $imports,
        ]);
    }

    public function detail(AutomotivePart $automotivePart): Response
    {
        $automotivePart->load(['lastImport', 'stockMovements' => fn ($query) => $query->latest()->take(20)]);

        return Inertia::render('Autopartes/Detalle', [
            'part' => $automotivePart,
            'stockMovements' => $automotivePart->stockMovements,
        ]);
    }

    public function importDetail(AutomotivePartImport $import): Response
    {
        $import->load(['rows' => fn ($query) => $query->latest()->limit(25)]);

        return Inertia::render('Autopartes/ImportDetail', [
            'import' => $import,
            'rows' => $import->rows,
        ]);
    }

    public function uploadForm(): Response
    {
        return Inertia::render('Autopartes/Subir', [
            'canUpload' => true,
        ]);
    }

    public function store(StoreAutomotivePartImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $storedName = 'automotive-parts/'.now()->format('Y_m_d_H_i_s').'-'.$file->getClientOriginalName();
        $path = $file->storeAs('automotive-parts', now()->format('Y_m_d_H_i_s').'-'.$file->getClientOriginalName(), 'local');

        $hash = hash_file('sha256', $file->getRealPath());

        $import = AutomotivePartImport::query()->create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $path,
            'file_hash' => $hash,
            'status' => 'pending',
            'total_rows' => 0,
            'imported_rows' => 0,
            'updated_rows' => 0,
            'duplicate_rows' => 0,
            'invalid_rows' => 0,
            'missing_compatibility_rows' => 0,
            'started_at' => now(),
            'metadata' => [
                'source_path' => $path,
            ],
        ]);

        ProcessAutomotivePartImportJob::dispatch($import->id, storage_path('app/'.$path));

        return redirect()->route('autopartes.imports.show', $import->id)
            ->with('success', 'La importación fue recibida y se está procesando.');
    }
}
