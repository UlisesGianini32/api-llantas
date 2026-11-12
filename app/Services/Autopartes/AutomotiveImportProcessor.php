<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveCatalogRow;
use App\Models\AutomotiveImportRun;
use App\Models\AutomotivePart;
use App\Models\AutopartesModel;
use App\Support\AutopartesUserDirectory;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AutomotiveImportProcessor
{
    /** Returns true only when another bounded batch is needed. */
    public function processBatch(int $runId): bool
    {
        $token = (string) Str::uuid();
        $claimed = AutomotiveImportRun::whereKey($runId)->whereIn('status', ['queued', 'processing'])
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))
            ->update(['status' => 'processing', 'lock_token' => $token, 'lease_expires_at' => now()->addMinutes(5)]);
        if (! $claimed) {
            return false;
        }
        try {
            $run = AutomotiveImportRun::findOrFail($runId);
            $actor = AutopartesUserDirectory::find((int) $run->requested_by);
            if (! $actor) {
                throw new RuntimeException('El usuario solicitante de la importación ya no existe.');
            }
            $branch = AutomotiveBranch::findOrFail($run->branch_id);
            app(BranchCatalogAccess::class)->authorize($actor, $branch, BranchCatalogAccess::IMPORT);
            app(AutomotiveOriginalStore::class)->verifiedPath($run->file);
            $plan = $run->preview['plan'];

            foreach (array_slice($plan, $run->processed_rows, config('autopartes.batch_size')) as $entry) {
                AutopartesModel::transaction(function () use ($runId, $token, $entry): void {
                    // The immutable plan can be large; never reload it for every part.
                    $run = AutomotiveImportRun::select([
                        'id', 'lock_token', 'lease_expires_at', 'processed_rows',
                        'counters', 'requested_by', 'started_at',
                    ])->lockForUpdate()->findOrFail($runId);
                    if ($run->lock_token !== $token || $run->lease_expires_at->isPast()) {
                        throw new RuntimeException('La ejecución perdió su reserva; se requiere reintento.');
                    }

                    $row = AutomotiveCatalogRow::lockForUpdate()->findOrFail($entry['row_id']);
                    $decision = $row->decisions()->latest('id')->first();
                    $partId = $decision?->automotive_part_id ?? ($entry['automotive_part_id'] ?? null);
                    $created = false;

                    if (! $partId) {
                        $part = AutomotivePart::create([
                            'code' => 'AP-'.Str::uuid(),
                            'source_row_id' => $row->id,
                            'identity_status' => ($entry['ambiguous'] ?? false)
                                ? AutomotivePart::STATUS_AMBIGUOUS
                                : AutomotivePart::STATUS_PROVISIONAL,
                            'item_number' => $row->item_number,
                            'manufacturer_part_number' => $row->manufacturer_part_number,
                            'vendor_name' => $row->vendor_name,
                            'category' => $row->category,
                            'subcategory' => $row->subcategory,
                            'description' => $row->description,
                            'applicable_models' => $row->cells[14]['value'] ?? null,
                            'allow_receipt' => true,
                            'is_active' => false,
                        ]);
                        $partId = (int) $part->getKey();
                        $created = true;
                    } elseif (! AutomotivePart::whereKey($partId)->exists()) {
                        throw new RuntimeException('La asociación previa no corresponde a una autoparte válida.');
                    }

                    if (! $decision) {
                        $row->decisions()->create([
                            'automotive_part_id' => $partId,
                            'decision' => 'linked',
                            'decided_by' => $run->requested_by,
                            'reason' => 'Importación '.$run->id.': '.$entry['kind'].'. Identidad provisional; sin autorización de venta ni stock automático.',
                        ]);
                    } elseif (! $decision->automotive_part_id) {
                        // An explicit review decision must not be silently superseded by an import.
                        throw new RuntimeException('La fila tiene una decisión de revisión pendiente; no se sobrescribirá.');
                    }

                    $counters = $run->counters;
                    $counters[$created ? 'created' : 'reused']++;
                    $run->update([
                        'processed_rows' => $run->processed_rows + 1,
                        'counters' => $counters,
                        'started_at' => $run->started_at ?? now(),
                        'lease_expires_at' => now()->addMinutes(5),
                    ]);
                });
            }

            $run = AutomotiveImportRun::findOrFail($runId);
            $more = $run->processed_rows < count($plan);
            AutomotiveImportRun::whereKey($runId)->where('lock_token', $token)->update([
                'status' => $more ? 'queued' : 'completed',
                'lock_token' => null,
                'lease_expires_at' => null,
                'finished_at' => $more ? null : now(),
            ]);

            return $more;
        } catch (Throwable $exception) {
            $failed = AutomotiveImportRun::whereKey($runId)->where('lock_token', $token)->update([
                'status' => 'failed',
                'lock_token' => null,
                'lease_expires_at' => null,
                // Do not put SQL, storage paths or credentials in the UI.
                'error_message' => 'El lote se detuvo. Las filas confirmadas se conservan; revise el incidente y reintente.',
            ]);
            if ($failed) {
                $run = AutomotiveImportRun::findOrFail($runId);
                $run->issues()->create([
                    'severity' => 'error',
                    'code' => 'batch_failed',
                    'source_row_id' => $run->preview['plan'][$run->processed_rows]['row_id'] ?? null,
                    'message' => 'Lote interrumpido; se conserva el punto de reanudación.',
                    'context' => ['processed_rows' => $run->processed_rows, 'exception_type' => $exception::class],
                ]);
            }
            throw $exception;
        }
    }
}
