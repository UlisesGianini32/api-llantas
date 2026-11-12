<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveInventoryMovement;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartBranchSetting;
use App\Models\AutomotiveReceipt;
use App\Models\AutomotiveReceiptItem;
use App\Models\AutopartesModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class AutomotiveReceiptService
{
    public function __construct(
        private BranchCatalogAccess $access,
        private AutomotiveBarcodeLookupService $lookup,
        private AutomotiveMovementService $movements,
    ) {}

    /**
     * Create a new draft reception session for a branch without generating any inventory movements.
     *
     * @param  array{automotive_location_id?: ?int, notes?: ?string}  $data
     */
    public function createDraft(User $user, AutomotiveBranch $branch, array $data = []): AutomotiveReceipt
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::RECEIPT_DRAFT);

        return AutopartesModel::transaction(function () use ($user, $branch, $data): AutomotiveReceipt {
            $location = isset($data['automotive_location_id']) && $data['automotive_location_id'] !== null
                ? AutomotiveLocation::query()->find($data['automotive_location_id'])
                : $branch->ensureDefaultReceptionLocation();

            if (! $location || (int) $location->branch_id !== (int) $branch->getKey()) {
                throw ValidationException::withMessages([
                    'automotive_location_id' => 'La ubicación seleccionada no pertenece a la sucursal autorizada.',
                ]);
            }

            if (! $location->is_active) {
                throw ValidationException::withMessages([
                    'automotive_location_id' => 'La ubicación de recepción seleccionada está inactiva.',
                ]);
            }

            $folio = $this->nextFolio($branch);

            return AutomotiveReceipt::create([
                'branch_id' => $branch->getKey(),
                'automotive_location_id' => $location->getKey(),
                'folio' => $folio,
                'status' => AutomotiveReceipt::STATUS_DRAFT,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                    ? mb_substr(trim((string) $data['notes']), 0, 2000)
                    : null,
                'created_by' => $user->getKey(),
            ]);
        });
    }

    /**
     * Add or consolidate an autopart in a draft reception via barcode scan or explicit selection.
     *
     * @param array{
     *     scan_code?: ?string,
     *     automotive_part_id?: ?int,
     *     quantity?: mixed,
     *     entry_mode?: ?string,
     *     notes?: ?string,
     *     strict?: bool
     * } $data
     * @return array{
     *     added: bool,
     *     item: ?AutomotiveReceiptItem,
     *     lookup: ?array,
     *     message: string
     * }
     */
    public function addItem(User $user, AutomotiveBranch $branch, AutomotiveReceipt $receipt, array $data): array
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::RECEIPT_DRAFT);
        $this->assertReceiptBelongsToBranch($receipt, $branch);

        if (! $receipt->isDraft()) {
            throw ValidationException::withMessages([
                'receipt' => 'No se pueden agregar ni modificar partidas en una recepción que ya fue confirmada o cancelada.',
            ]);
        }

        $quantity = $this->validatePositiveQuantity($data['quantity'] ?? 1, 'quantity');
        $scannedCode = isset($data['scan_code']) && trim((string) $data['scan_code']) !== ''
            ? $this->lookup->validateCode($data['scan_code'], 'scan_code')
            : null;
        $explicitPartId = isset($data['automotive_part_id']) && $data['automotive_part_id'] !== null && $data['automotive_part_id'] !== ''
            ? (int) $data['automotive_part_id']
            : null;

        $lookupResult = null;
        if ($explicitPartId === null) {
            if ($scannedCode === null) {
                throw ValidationException::withMessages([
                    'scan_code' => 'Debe escanear un código o seleccionar una autoparte del catálogo.',
                ]);
            }

            $lookupResult = $this->lookup->lookup($user, $branch, $scannedCode);

            if ($lookupResult['match_status'] === AutomotiveBarcodeLookupService::MATCH_NOT_FOUND) {
                throw ValidationException::withMessages([
                    'scan_code' => "No se encontró ninguna autoparte con el código '{$scannedCode}'.",
                ]);
            }

            if ($lookupResult['match_status'] === AutomotiveBarcodeLookupService::MATCH_AMBIGUOUS || empty($lookupResult['candidate'])) {
                if ($data['strict'] ?? false) {
                    throw ValidationException::withMessages([
                        'scan_code' => $lookupResult['warning'] ?? 'El código escaneado es ambiguo y requiere selección y verificación explícita.',
                    ]);
                }

                return [
                    'added' => false,
                    'item' => null,
                    'lookup' => $lookupResult,
                    'message' => $lookupResult['warning'] ?? 'Se encontraron múltiples coincidencias o identidad ambigua.',
                ];
            }

            $explicitPartId = (int) $lookupResult['candidate']['automotive_part_id'];
        }

        $entryMode = (string) ($data['entry_mode'] ?? ($scannedCode !== null ? AutomotiveReceiptItem::MODE_SCAN : AutomotiveReceiptItem::MODE_MANUAL));
        if (! in_array($entryMode, AutomotiveReceiptItem::MODES, true)) {
            $entryMode = AutomotiveReceiptItem::MODE_SCAN;
        }

        $notes = isset($data['notes']) && trim((string) $data['notes']) !== ''
            ? mb_substr(trim((string) $data['notes']), 0, 1000)
            : null;

        $item = AutopartesModel::transaction(function () use ($user, $branch, $receipt, $explicitPartId, $quantity, $scannedCode, $entryMode, $notes): AutomotiveReceiptItem {
            $lockedReceipt = AutomotiveReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            if (! $lockedReceipt->isDraft()) {
                throw ValidationException::withMessages([
                    'receipt' => 'La recepción ya fue confirmada y no admite nuevas partidas.',
                ]);
            }

            $part = AutomotivePart::query()->lockForUpdate()->find($explicitPartId);
            if (! $part) {
                throw ValidationException::withMessages([
                    'automotive_part_id' => 'La autoparte seleccionada no pertenece al catálogo exclusivo de Autopartes.',
                ]);
            }

            if (! $part->isReceivable()) {
                throw ValidationException::withMessages([
                    'automotive_part_id' => 'Esta autoparte tiene identidad ambigua o recepción restringida. Debe verificar y resolver su identidad antes de recibir stock físico.',
                ]);
            }

            $existing = AutomotiveReceiptItem::query()
                ->where('automotive_receipt_id', $lockedReceipt->getKey())
                ->where('automotive_part_id', $part->getKey())
                ->lockForUpdate()
                ->first();

            $maxQty = (int) config('autopartes.max_receipt_item_quantity', 5000);
            $newTotal = ($existing ? (int) $existing->quantity : 0) + $quantity;
            if ($newTotal > $maxQty) {
                throw ValidationException::withMessages([
                    'quantity' => "La cantidad acumulada ({$newTotal}) supera el límite máximo permitido por partida ({$maxQty}).",
                ]);
            }

            $event = [
                'at' => now()->toIso8601String(),
                'user_id' => $user->getKey(),
                'user_name' => $user->name,
                'entry_mode' => $entryMode,
                'scanned_code' => $scannedCode,
                'quantity' => $quantity,
                'notes' => $notes,
            ];

            if ($existing) {
                $detail = is_array($existing->count_detail) ? $existing->count_detail : [];
                $detail[] = $event;

                $existing->update([
                    'quantity' => $newTotal,
                    'scan_events_count' => (int) $existing->scan_events_count + 1,
                    'scanned_code' => $existing->scanned_code ?: $scannedCode,
                    'identity_status_snapshot' => $part->identity_status,
                    'count_detail' => $detail,
                    'notes' => $notes !== null
                        ? ($existing->notes ? "{$existing->notes} | {$notes}" : $notes)
                        : $existing->notes,
                ]);

                $savedItem = $existing->fresh(['part']);
            } else {
                $savedItem = AutomotiveReceiptItem::create([
                    'automotive_receipt_id' => $lockedReceipt->getKey(),
                    'automotive_part_id' => $part->getKey(),
                    'scanned_code' => $scannedCode,
                    'entry_mode' => $entryMode,
                    'identity_status_snapshot' => $part->identity_status,
                    'quantity' => $quantity,
                    'scan_events_count' => 1,
                    'count_detail' => [$event],
                    'notes' => $notes,
                ])->load(['part']);
            }

            AutomotivePartBranchSetting::query()->firstOrCreate(
                [
                    'automotive_part_id' => $part->getKey(),
                    'branch_id' => $branch->getKey(),
                ],
                [
                    'primary_location_id' => $lockedReceipt->automotive_location_id,
                    'is_sellable' => false,
                ]
            );

            return $savedItem;
        });

        return [
            'added' => true,
            'item' => $item,
            'lookup' => $lookupResult,
            'message' => 'Autoparte agregada al borrador de recepción.',
        ];
    }

    public function updateItem(
        User $user,
        AutomotiveBranch $branch,
        AutomotiveReceipt $receipt,
        AutomotiveReceiptItem $item,
        mixed $rawQuantity,
        ?string $notes = null,
    ): AutomotiveReceiptItem {
        $this->access->authorize($user, $branch, BranchCatalogAccess::RECEIPT_DRAFT);
        $this->assertReceiptBelongsToBranch($receipt, $branch);

        if ((int) $item->automotive_receipt_id !== (int) $receipt->getKey()) {
            throw ValidationException::withMessages(['item' => 'La partida no pertenece a esta recepción.']);
        }

        $quantity = $this->validatePositiveQuantity($rawQuantity, 'quantity');

        return AutopartesModel::transaction(function () use ($user, $receipt, $item, $quantity, $notes): AutomotiveReceiptItem {
            $lockedReceipt = AutomotiveReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            if (! $lockedReceipt->isDraft()) {
                throw ValidationException::withMessages([
                    'receipt' => 'No se pueden editar partidas de una recepción ya confirmada o cancelada.',
                ]);
            }

            $lockedItem = AutomotiveReceiptItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $detail = is_array($lockedItem->count_detail) ? $lockedItem->count_detail : [];
            $detail[] = [
                'at' => now()->toIso8601String(),
                'user_id' => $user->getKey(),
                'user_name' => $user->name,
                'entry_mode' => 'manual_edit',
                'previous_quantity' => (int) $lockedItem->quantity,
                'quantity' => $quantity,
                'notes' => $notes !== null ? trim($notes) : null,
            ];

            $lockedItem->update([
                'quantity' => $quantity,
                'count_detail' => $detail,
                'notes' => $notes !== null ? (trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null) : $lockedItem->notes,
            ]);

            return $lockedItem->fresh(['part']);
        });
    }

    public function removeItem(
        User $user,
        AutomotiveBranch $branch,
        AutomotiveReceipt $receipt,
        AutomotiveReceiptItem $item,
    ): void {
        $this->access->authorize($user, $branch, BranchCatalogAccess::RECEIPT_DRAFT);
        $this->assertReceiptBelongsToBranch($receipt, $branch);

        if ((int) $item->automotive_receipt_id !== (int) $receipt->getKey()) {
            throw ValidationException::withMessages(['item' => 'La partida no pertenece a esta recepción.']);
        }

        AutopartesModel::transaction(function () use ($receipt, $item): void {
            $lockedReceipt = AutomotiveReceipt::query()->lockForUpdate()->findOrFail($receipt->getKey());
            if (! $lockedReceipt->isDraft()) {
                throw ValidationException::withMessages([
                    'receipt' => 'No se pueden eliminar partidas de una recepción ya confirmada o cancelada.',
                ]);
            }

            AutomotiveReceiptItem::query()
                ->where('automotive_receipt_id', $lockedReceipt->getKey())
                ->whereKey($item->getKey())
                ->delete();
        });
    }

    /**
     * Confirm a draft reception transactionally and idempotently on the dedicated 'autopartes' database.
     */
    public function confirm(
        User $user,
        AutomotiveBranch $branch,
        AutomotiveReceipt $receipt,
        string $idempotencyKey,
        ?string $notes = null,
    ): AutomotiveReceipt {
        $this->access->authorize($user, $branch, BranchCatalogAccess::RECEIPT_CONFIRM);
        $this->assertReceiptBelongsToBranch($receipt, $branch);

        $cleanKey = trim($idempotencyKey);
        if ($cleanKey === '' || mb_strlen($cleanKey) > 191) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Se requiere una clave de idempotencia válida para confirmar la recepción.',
            ]);
        }

        return AutopartesModel::transaction(function () use ($user, $branch, $receipt, $cleanKey, $notes): AutomotiveReceipt {
            $lockedReceipt = AutomotiveReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertReceiptBelongsToBranch($lockedReceipt, $branch);

            /** @var Collection<int, AutomotiveReceiptItem> $items */
            $items = $lockedReceipt->items()
                ->with(['part'])
                ->orderBy('automotive_part_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $payloadHash = $this->computeConfirmationHash($lockedReceipt, $items);

            if ($lockedReceipt->isConfirmed()) {
                if ($lockedReceipt->idempotency_key === $cleanKey && $lockedReceipt->confirmation_hash === $payloadHash) {
                    return $lockedReceipt->fresh([
                        'location',
                        'items.part',
                        'items.movement',
                    ]);
                }

                throw ValidationException::withMessages([
                    'receipt' => 'Esta recepción ya fue confirmada previamente y no puede confirmarse dos veces.',
                ]);
            }

            if (! $lockedReceipt->isDraft()) {
                throw ValidationException::withMessages([
                    'receipt' => 'Solo se pueden confirmar recepciones en estado borrador.',
                ]);
            }

            $keyUsedElsewhere = AutomotiveReceipt::query()
                ->where('idempotency_key', $cleanKey)
                ->whereKeyNot($lockedReceipt->getKey())
                ->exists();

            if ($keyUsedElsewhere) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave de idempotencia ya fue utilizada en otra recepción.',
                ]);
            }

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'No se puede confirmar una recepción vacía sin partidas registradas.',
                ]);
            }

            $location = AutomotiveLocation::query()
                ->lockForUpdate()
                ->find($lockedReceipt->automotive_location_id);

            if (! $location || (int) $location->branch_id !== (int) $branch->getKey() || ! $location->is_active) {
                throw ValidationException::withMessages([
                    'automotive_location_id' => 'La ubicación de recepción no es válida o no pertenece a la sucursal.',
                ]);
            }

            $finalNotes = $notes !== null && trim($notes) !== ''
                ? mb_substr(trim($notes), 0, 2000)
                : $lockedReceipt->notes;

            $orderedPartIds = $items->pluck('automotive_part_id')
                ->filter()
                ->unique()
                ->sort()
                ->values();

            $lockedParts = $orderedPartIds->isEmpty()
                ? collect()
                : AutomotivePart::query()
                    ->whereIn('id', $orderedPartIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            $movementEntries = [];
            $itemParts = [];
            foreach ($items as $item) {
                /** @var AutomotivePart|null $part */
                $part = $lockedParts->get((int) $item->automotive_part_id);

                if (! $part) {
                    throw ValidationException::withMessages([
                        'items' => "La autoparte de la partida #{$item->id} no es válida.",
                    ]);
                }

                if (! $part->isReceivable()) {
                    $label = $part->description ?: $part->code;
                    throw ValidationException::withMessages([
                        'items' => "La autoparte '{$label}' tiene identidad ambigua pendiente de resolver y no puede ingresar al inventario físico.",
                    ]);
                }

                $qty = $this->validatePositiveQuantity($item->quantity, 'quantity');
                $itemParts[$item->getKey()] = $part;

                $movementEntries[] = [
                    'branch_id' => (int) $branch->getKey(),
                    'automotive_part_id' => (int) $part->getKey(),
                    'automotive_location_id' => (int) $location->getKey(),
                    'type' => AutomotiveInventoryMovement::RECEIPT,
                    'quantity' => $qty,
                    'reference_type' => 'automotive_receipt',
                    'reference_id' => (int) $lockedReceipt->getKey(),
                    'reference' => $lockedReceipt->folio,
                    'notes' => $item->notes ?: ($finalNotes ?: "Recepción física sucursal {$branch->code} ({$lockedReceipt->folio})"),
                    'metadata' => [
                        'automotive_branch_id' => (int) $branch->getKey(),
                        'branch_code' => $branch->code,
                        'receipt_id' => (int) $lockedReceipt->getKey(),
                        'receipt_folio' => $lockedReceipt->folio,
                        'receipt_item_id' => (int) $item->getKey(),
                        'scanned_code' => $item->scanned_code,
                        'entry_mode' => $item->entry_mode,
                        'scan_events_count' => (int) $item->scan_events_count,
                    ],
                    'external_key' => "automotive-receipt:{$lockedReceipt->getKey()}:item:{$item->getKey()}",
                ];
            }

            $movements = $this->movements->recordBatch($movementEntries, $user)->values();

            foreach ($items->values() as $idx => $item) {
                /** @var AutomotiveInventoryMovement $movement */
                $movement = $movements[$idx];
                /** @var AutomotivePart $part */
                $part = $itemParts[$item->getKey()];

                $item->update([
                    'identity_status_snapshot' => $part->identity_status,
                    'stock_before' => $movement->stock_before,
                    'stock_after' => $movement->stock_after,
                    'automotive_movement_id' => $movement->getKey(),
                ]);

                $branchSetting = AutomotivePartBranchSetting::query()->firstOrCreate(
                    [
                        'automotive_part_id' => $part->getKey(),
                        'branch_id' => $branch->getKey(),
                    ],
                    [
                        'primary_location_id' => $location->getKey(),
                        'is_sellable' => false,
                    ]
                );

                if ($branchSetting->primary_location_id === null) {
                    $branchSetting->update([
                        'primary_location_id' => $location->getKey(),
                    ]);
                }
            }

            $lockedReceipt->update([
                'status' => AutomotiveReceipt::STATUS_CONFIRMED,
                'idempotency_key' => $cleanKey,
                'confirmation_hash' => $payloadHash,
                'notes' => $finalNotes,
                'confirmed_by' => $user->getKey(),
                'confirmed_at' => now(),
            ]);

            return $lockedReceipt->fresh([
                'location',
                'items.part',
                'items.movement',
            ]);
        });
    }

    public function validatePositiveQuantity(mixed $value, string $field = 'quantity'): int
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw ValidationException::withMessages([$field => 'La cantidad debe ser un número entero mayor a cero.']);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! preg_match('/^-?\d+$/', $trimmed)) {
                throw ValidationException::withMessages([$field => 'La cantidad debe ser un número entero sin decimales.']);
            }
            $value = (int) $trimmed;
        } elseif (is_float($value)) {
            throw ValidationException::withMessages([$field => 'La cantidad no admite decimales; debe ser un número entero.']);
        }

        if (! is_int($value) || $value <= 0) {
            throw ValidationException::withMessages([$field => 'La cantidad debe ser mayor a cero.']);
        }

        $max = (int) config('autopartes.max_receipt_item_quantity', 5000);
        if ($value > $max) {
            throw ValidationException::withMessages([$field => "La cantidad no puede exceder {$max} unidades por operación."]);
        }

        return $value;
    }

    private function assertReceiptBelongsToBranch(AutomotiveReceipt $receipt, AutomotiveBranch $branch): void
    {
        if ((int) $receipt->branch_id !== (int) $branch->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  Collection<int, AutomotiveReceiptItem>  $items
     */
    private function computeConfirmationHash(AutomotiveReceipt $receipt, Collection $items): string
    {
        $payload = [
            'receipt_id' => (int) $receipt->getKey(),
            'branch_id' => (int) $receipt->branch_id,
            'location_id' => (int) $receipt->automotive_location_id,
            'items' => $items->map(fn (AutomotiveReceiptItem $item): array => [
                'id' => (int) $item->getKey(),
                'automotive_part_id' => (int) $item->automotive_part_id,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function nextFolio(AutomotiveBranch $branch): string
    {
        $datePrefix = now()->format('Ymd');
        $prefix = "REC-{$branch->code}-{$datePrefix}-";

        $count = AutomotiveReceipt::query()
            ->where('branch_id', $branch->getKey())
            ->where('folio', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->count();

        do {
            $count++;
            $folio = $prefix.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
        } while (AutomotiveReceipt::query()->where('folio', $folio)->exists());

        return $folio;
    }
}
