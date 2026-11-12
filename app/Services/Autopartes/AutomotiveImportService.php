<?php

namespace App\Services\Autopartes;

use App\Jobs\ProcessAutomotiveImport;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveCatalogFile;
use App\Models\AutomotiveCatalogRow;
use App\Models\AutomotiveImportRun;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutomotiveSourceSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AutomotiveImportService
{
    public function __construct(
        private BranchCatalogAccess $access,
        private AutomotiveWorkbookReader $reader,
        private AutomotiveOriginalStore $store,
    ) {}

    public function preview(User $actor, AutomotiveBranch $branch, string $path, string $name): AutomotiveImportRun
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::IMPORT);
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xls', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Seleccione un archivo XLS o XLSX.']);
        }
        $document = $this->reader->read($path);
        $storedPath = $this->store->preserve($path, $document['sha256']);

        return AutopartesModel::transaction(function () use ($actor, $branch, $document, $storedPath, $name, $path): AutomotiveImportRun {
            $file = AutomotiveCatalogFile::where('sha256', $document['sha256'])->first();
            if (! $file) {
                $file = AutomotiveCatalogFile::create([
                    'sha256' => $document['sha256'],
                    'semantic_sha256' => $document['semantic_sha256'],
                    'original_name' => basename($name),
                    'disk' => 'automotive_originals',
                    'storage_path' => $storedPath,
                    'size_bytes' => filesize($path),
                    'uploaded_by' => $actor->id,
                ]);
                foreach ($document['sheets'] as $sourceSheet) {
                    $sheet = $file->sheets()->create([
                        'sheet_index' => $sourceSheet['sheet_index'],
                        'name' => $sourceSheet['name'],
                        'header_row_number' => 1,
                        'headers' => AutomotiveSourceSchema::HEADERS,
                        'header_cells' => $sourceSheet['header_cells'],
                        'column_count' => 21,
                        'data_row_count' => count($sourceSheet['rows']),
                    ]);
                    foreach ($sourceSheet['rows'] as $row) {
                        $v = array_column($row['cells'], 'value');
                        $sheet->rows()->create($row + [
                            'item_number' => $v[2],
                            'manufacturer_part_number' => $v[3],
                            'description' => $v[5],
                            'category' => $v[0],
                            'subcategory' => $v[1],
                            'vendor_name' => $v[4],
                            'search_text' => mb_strtolower(implode('\n', array_map(fn ($index) => (string) $v[$index], [2, 3, 5, 0, 1, 4]))),
                        ]);
                    }
                }
            }
            $rows = $this->rows($file->id);
            $referenceRun = AutomotiveImportRun::where('branch_id', $branch->id)->where('status', 'completed')->latest('id')->first();
            $reference = $referenceRun ? $this->rows($referenceRun->file_id) : collect();
            $equivalent = $referenceRun && $file->semantic_sha256 === $referenceRun->file->semantic_sha256
                && $this->equivalent($rows, $reference);
            $byHash = $reference->groupBy('semantic_hash');
            $byIdentity = $reference->groupBy('identity_key');
            $byId = $reference->keyBy('id');
            $incomingCounts = $rows->countBy('identity_key');
            $itemCounts = $rows->filter(fn ($row) => filled($row->item_number))->countBy('item_number');
            $plan = [];
            $usedReference = [];
            $counts = array_fill_keys(['new', 'existing', 'equivalent', 'unchanged', 'modified', 'ambiguous'], 0);

            foreach ($rows as $index => $row) {
                $match = null;
                $kind = 'new';
                $partId = $row->currentDecision?->automotive_part_id;
                if ($partId) {
                    $kind = 'existing';
                    $match = $byId->get($row->id);
                } elseif ($equivalent) {
                    $match = $reference[$index];
                    $kind = 'equivalent';
                } else {
                    $exact = $byHash->get($row->semantic_hash, collect());
                    $candidates = $byIdentity->get($row->identity_key, collect());
                    if ($exact->count() === 1 && ! isset($usedReference[$exact->first()->id])) {
                        $match = $exact->first();
                        // Hash lookup is only a candidate; verify every typed value and format.
                        if (AutomotiveWorkbookReader::semanticCells($row->cells) === AutomotiveWorkbookReader::semanticCells($match->cells)) {
                            $kind = 'unchanged';
                        } else {
                            $match = null;
                        }
                    } elseif ($candidates->count() === 1 && $incomingCounts[$row->identity_key] === 1 && ! isset($usedReference[$candidates->first()->id])) {
                        $match = $candidates->first();
                        $kind = 'modified';
                    } elseif ($candidates->isNotEmpty() || $incomingCounts[$row->identity_key] > 1) {
                        $kind = 'ambiguous';
                    }
                }
                if ($match) {
                    $usedReference[$match->id] = true;
                    $partId ??= $match->currentDecision?->automotive_part_id;
                }
                $ambiguous = ($itemCounts[$row->item_number] ?? 0) > 1 || $incomingCounts[$row->identity_key] > 1 || $kind === 'ambiguous';
                $counts[$kind]++;
                $plan[] = [
                    'row_id' => $row->id,
                    'kind' => $kind,
                    'automotive_part_id' => $partId,
                    'ambiguous' => $ambiguous,
                    'candidate_row_ids' => $byIdentity->get($row->identity_key, collect())->pluck('id')->all(),
                ];
            }
            $missing = $reference->reject(fn ($row) => isset($usedReference[$row->id]))->pluck('id')->all();
            $summary = $document['stats'] + [
                'classifications' => $counts,
                'equivalent_verified' => (bool) $equivalent,
                'missing_reference_rows' => count($missing),
                'pending_identity_review' => count(array_filter($plan, fn ($p) => $p['ambiguous'] || $p['kind'] === 'modified')),
            ];
            $run = AutomotiveImportRun::create([
                'file_id' => $file->id,
                'reference_file_id' => $referenceRun?->file_id,
                'branch_id' => $branch->id,
                'parser_version' => AutomotiveWorkbookReader::VERSION,
                'mapping_version' => 'autopartes-v2',
                'idempotency_key' => (string) Str::uuid(),
                'uploaded_name' => basename($name),
                'requested_by' => $actor->id,
                'status' => 'preview',
                'preview' => ['summary' => $summary, 'plan' => $plan],
                'counters' => ['created' => 0, 'reused' => 0, 'rejected' => 0],
            ]);
            foreach ($plan as $entry) {
                if ($entry['ambiguous'] || $entry['kind'] === 'modified') {
                    $run->issues()->create([
                        'source_row_id' => $entry['row_id'],
                        'severity' => 'warning',
                        'code' => $entry['ambiguous'] ? 'ambiguous_identity' : 'modified_source',
                        'message' => $entry['ambiguous']
                            ? 'Identificador compartido; no es un barcode verificado.'
                            : 'Origen modificado; se conserva la identidad previa sin sobrescribir datos comerciales.',
                        'context' => ['candidate_row_ids' => $entry['candidate_row_ids']],
                    ]);
                }
            }
            if ($missing !== []) {
                $run->issues()->create([
                    'severity' => 'warning',
                    'code' => 'missing_previous_rows',
                    'message' => 'Hay filas anteriores sin correspondencia. No se eliminarán autopartes ni existencias.',
                    'context' => ['row_ids' => $missing],
                ]);
            }

            return $run;
        });
    }

    public function confirm(User $actor, AutomotiveBranch $branch, AutomotiveImportRun $run, bool $retry = false, bool $dispatchQueue = true): void
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::IMPORT);
        abort_unless((int) $run->branch_id === (int) $branch->id, 404);
        if ($dispatchQueue && config('autopartes.queue_connection') === 'sync') {
            throw ValidationException::withMessages(['import' => 'Configure una cola asíncrona para autopartes.']);
        }
        $this->store->verifiedPath($run->file);
        $changed = AutopartesModel::transaction(function () use ($run, $retry): bool {
            $locked = AutomotiveImportRun::lockForUpdate()->findOrFail($run->id);
            $allowed = $locked->status === 'preview' || ($retry && ($locked->status === 'failed'
                || (in_array($locked->status, ['queued', 'processing'], true) && (! $locked->lease_expires_at || $locked->lease_expires_at->isPast()))));
            if (! $allowed) {
                return false;
            }
            $locked->update(['status' => 'queued', 'lock_token' => null, 'lease_expires_at' => null, 'error_message' => null]);

            return true;
        });
        if ($changed && $dispatchQueue) {
            ProcessAutomotiveImport::dispatch($run->id);
        }
    }

    private function rows(int $fileId)
    {
        return AutomotiveCatalogRow::whereHas('sheet', fn ($q) => $q->where('file_id', $fileId))
            ->with(['sheet', 'currentDecision'])->orderBy('sheet_id')->orderBy('row_number')->get();
    }

    private function equivalent($rows, $reference): bool
    {
        if ($rows->count() !== $reference->count()) {
            return false;
        }
        foreach ($rows as $index => $row) {
            $other = $reference[$index];
            if ($row->sheet->name !== $other->sheet->name || $row->sheet->sheet_index !== $other->sheet->sheet_index
                || AutomotiveWorkbookReader::semanticCells($row->cells) !== AutomotiveWorkbookReader::semanticCells($other->cells)
                || AutomotiveWorkbookReader::semanticCells($row->sheet->header_cells) !== AutomotiveWorkbookReader::semanticCells($other->sheet->header_cells)) {
                return false;
            }
        }

        return true;
    }
}
