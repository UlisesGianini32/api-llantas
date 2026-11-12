<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveCatalogRow;
use App\Models\AutomotiveImportRun;
use App\Services\Autopartes\AutomotiveImportService;
use App\Services\Autopartes\AutomotiveOriginalStore;
use App\Services\Autopartes\BranchCatalogAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function __construct(private BranchCatalogAccess $access, private AutomotiveImportService $imports) {}

    public function index(Request $request, AutomotiveBranch $branch)
    {
        $this->authorizeImport($request, $branch);

        return Inertia::render('Autopartes/Imports', ['branch' => $branch->only('code', 'name'),
            'runs' => AutomotiveImportRun::where('branch_id', $branch->id)->latest('id')->paginate(25)
                ->through(fn ($run) => $this->summary($run)),
            'capabilities' => $this->access->capabilities($request->user(), $branch)]);
    }

    public function store(Request $request, AutomotiveBranch $branch)
    {
        $this->authorizeImport($request, $branch);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:xls,xlsx']]);
        $upload = $request->file('file');
        $run = $this->imports->preview($request->user(), $branch, $upload->getRealPath(), $upload->getClientOriginalName());

        return to_route('autopartes.imports.show', [$branch->code, $run->id]);
    }

    public function show(Request $request, AutomotiveBranch $branch, AutomotiveImportRun $run)
    {
        $this->authorizeImport($request, $branch, $run);

        return Inertia::render('Autopartes/ImportShow', ['branch' => $branch->only('code', 'name'),
            'run' => $this->summary($run) + ['summary' => $run->preview['summary'] ?? [], 'error' => $run->error_message,
                'sha256' => $run->file->sha256, 'reference_file_id' => $run->reference_file_id],
            'samples' => AutomotiveCatalogRow::whereHas('sheet', fn ($q) => $q->where('file_id', $run->file_id))
                ->orderBy('sheet_id')->orderBy('row_number')->limit(5)->get(['id', 'row_number', 'item_number', 'manufacturer_part_number', 'description', 'vendor_name']),
            'issues' => $run->issues()->with('sourceRow')->latest('id')->paginate(50)->through(fn ($issue) =>
                $issue->only('id', 'source_row_id', 'severity', 'code', 'message', 'context') + [
                    'row_number' => $issue->sourceRow?->row_number, 'item_number' => $issue->sourceRow?->item_number,
                ]),
            'capabilities' => $this->access->capabilities($request->user(), $branch)]);
    }

    public function confirm(Request $request, AutomotiveBranch $branch, AutomotiveImportRun $run)
    {
        $this->authorizeImport($request, $branch, $run);
        $request->validate(['confirmed' => 'required|accepted']);
        $this->imports->confirm($request->user(), $branch, $run);

        return back();
    }

    public function retry(Request $request, AutomotiveBranch $branch, AutomotiveImportRun $run)
    {
        $this->authorizeImport($request, $branch, $run);
        $this->imports->confirm($request->user(), $branch, $run, true);

        return back();
    }

    public function download(Request $request, AutomotiveBranch $branch, AutomotiveImportRun $run, AutomotiveOriginalStore $store)
    {
        $this->authorizeImport($request, $branch, $run);

        return response()->download($store->verifiedPath($run->file), $run->file->original_name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function authorizeImport(Request $request, AutomotiveBranch $branch, ?AutomotiveImportRun $run = null): void
    {
        $this->access->authorize($request->user(), $branch, BranchCatalogAccess::IMPORT);
        abort_if($run && (int) $run->branch_id !== (int) $branch->id, 404);
    }

    private function summary(AutomotiveImportRun $run): array
    {
        return $run->only('id', 'uploaded_name', 'status', 'processed_rows', 'counters', 'created_at', 'finished_at')
            + ['total_rows' => $run->preview['summary']['rows'] ?? 0];
    }
}
