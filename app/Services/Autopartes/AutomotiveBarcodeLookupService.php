<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartIdentifier;
use App\Models\AutomotiveSourceProductDecision;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class AutomotiveBarcodeLookupService
{
    public const MATCH_EXACT_VERIFIED = 'exact_verified';

    public const MATCH_PROVISIONAL_SINGLE = 'provisional_single';

    public const MATCH_AMBIGUOUS = 'ambiguous';

    public const MATCH_NOT_FOUND = 'not_found';

    public function __construct(
        private BranchCatalogAccess $access,
        private AutomotiveStockService $stock,
    ) {}

    public function validateCode(mixed $rawCode, string $field = 'code'): string
    {
        if (! is_string($rawCode) && ! is_numeric($rawCode)) {
            throw ValidationException::withMessages([$field => 'El código escaneado debe ser texto válido.']);
        }

        $raw = (string) $rawCode;
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            throw ValidationException::withMessages([$field => 'El código contiene caracteres de control no permitidos.']);
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw ValidationException::withMessages([$field => 'El código escaneado no puede estar vacío.']);
        }

        if (mb_strlen($trimmed) > 191) {
            throw ValidationException::withMessages([$field => 'El código escaneado excede la longitud máxima permitida (191 caracteres).']);
        }

        return $trimmed;
    }

    /**
     * Search a scanned barcode or identifier in the independent automotive catalog for a branch.
     * Never auto-selects when multiple candidates match or when identity_status is ambiguous.
     *
     * @return array<string, mixed>
     */
    public function lookup(User $user, AutomotiveBranch $branch, mixed $rawCode): array
    {
        $this->ensureCanLookup($user, $branch);
        $code = $this->validateCode($rawCode, 'code');
        $upper = mb_strtoupper($code);

        $baseQuery = AutomotivePart::query()
            ->with([
                'sourceRow.sheet.file',
                'identifiers' => fn ($q) => $q->where('is_active', true),
            ]);

        // 1. Check exact match (preserving leading zeros and exact characters, case-insensitive)
        $exactMatches = (clone $baseQuery)
            ->where(function ($query) use ($upper): void {
                $query->whereRaw('UPPER(TRIM(code)) = ?', [$upper])
                    ->orWhereRaw('UPPER(TRIM(verified_barcode)) = ?', [$upper])
                    ->orWhereRaw('UPPER(TRIM(item_number)) = ?', [$upper])
                    ->orWhereRaw('UPPER(TRIM(manufacturer_part_number)) = ?', [$upper])
                    ->orWhereHas('identifiers', function ($idQuery) use ($upper): void {
                        $idQuery->where('is_active', true)
                            ->whereRaw('UPPER(TRIM(identifier_value)) = ?', [$upper]);
                    });
            })
            ->orderBy('id')
            ->get();

        // Prefer verified identifiers (code, verified_barcode, or active verified alias) over unverified catalog collisions
        // only when the scanned string uniquely matches those verified fields.
        $verifiedExact = $exactMatches->filter(function (AutomotivePart $part) use ($upper): bool {
            if (mb_strtoupper(trim((string) $part->code)) === $upper) {
                return true;
            }
            if ($part->verified_barcode !== null && mb_strtoupper(trim($part->verified_barcode)) === $upper) {
                return true;
            }

            return $part->identifiers->contains(
                fn (AutomotivePartIdentifier $id): bool => $id->is_active && mb_strtoupper(trim($id->identifier_value)) === $upper
            );
        })->values();

        $candidates = $verifiedExact->isNotEmpty() ? $verifiedExact : $exactMatches;

        // 2. Fallback to normalized identifier comparison in automotive_part_identifiers if no exact match was found
        if ($candidates->isEmpty()) {
            $normalized = AutomotivePart::normalizeCode($code);
            if ($normalized !== null && $normalized !== $upper) {
                $candidates = (clone $baseQuery)
                    ->whereHas('identifiers', function ($idQuery) use ($normalized): void {
                        $idQuery->where('is_active', true)
                            ->where('identifier_normalized', $normalized);
                    })
                    ->orderBy('id')
                    ->get();
            }
        }

        if ($candidates->isEmpty()) {
            return [
                'scanned_code' => $code,
                'match_status' => self::MATCH_NOT_FOUND,
                'requires_disambiguation' => false,
                'warning' => 'No se encontró ninguna autoparte con el código escaneado. Puede usar la búsqueda manual asistida.',
                'candidate' => null,
                'candidates' => [],
            ];
        }

        $formatted = $this->formatCandidates($candidates, $branch, $code);

        if ($formatted->count() > 1) {
            return [
                'scanned_code' => $code,
                'match_status' => self::MATCH_AMBIGUOUS,
                'requires_disambiguation' => true,
                'warning' => "El código '{$code}' coincide con {$formatted->count()} registros del catálogo. Seleccione o verifique explícitamente la autoparte correcta; el sistema nunca selecciona la primera automáticamente.",
                'candidate' => null,
                'candidates' => $formatted->all(),
            ];
        }

        $single = $formatted->first();
        if (($single['identity_status'] ?? null) === AutomotivePart::STATUS_AMBIGUOUS || ! ($single['is_receivable'] ?? false)) {
            return [
                'scanned_code' => $code,
                'match_status' => self::MATCH_AMBIGUOUS,
                'requires_disambiguation' => true,
                'warning' => 'Este registro tiene identidad ambigua o recepción restringida. Debe verificar y resolver su identidad antes de recibir existencias físicas.',
                'candidate' => null,
                'candidates' => [$single],
            ];
        }

        $isVerifiedMatch = $verifiedExact->count() === 1 || ($single['identity_status'] ?? null) === AutomotivePart::STATUS_VERIFIED;

        return [
            'scanned_code' => $code,
            'match_status' => $isVerifiedMatch ? self::MATCH_EXACT_VERIFIED : self::MATCH_PROVISIONAL_SINGLE,
            'requires_disambiguation' => false,
            'warning' => $isVerifiedMatch
                ? null
                : 'Coincidencia única por identificador provisional del catálogo (etiqueta física aún no verificada).',
            'candidate' => $single,
            'candidates' => [$single],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manualSearch(User $user, AutomotiveBranch $branch, string $query, int $limit = 30): array
    {
        $this->ensureCanLookup($user, $branch);
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $lower = mb_strtolower($trimmed);
        $upper = mb_strtoupper($trimmed);
        $like = '%'.addcslashes($trimmed, '%_\\').'%';

        $parts = AutomotivePart::query()
            ->with([
                'sourceRow.sheet.file',
                'identifiers' => fn ($q) => $q->where('is_active', true),
            ])
            ->where(function ($partQuery) use ($like, $lower, $upper): void {
                $partQuery->where('code', 'like', $like)
                    ->orWhere('item_number', 'like', $like)
                    ->orWhere('manufacturer_part_number', 'like', $like)
                    ->orWhere('verified_barcode', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('vendor_name', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('subcategory', 'like', $like)
                    ->orWhereHas('sourceRow', fn ($rq) => $rq->where('search_text', 'like', "%{$lower}%"))
                    ->orWhereHas('identifiers', fn ($iq) => $iq->where('is_active', true)->whereRaw('UPPER(identifier_value) LIKE ?', ["%{$upper}%"]));
            })
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->get();

        return $this->formatCandidates($parts, $branch, $trimmed)->all();
    }

    /**
     * Explicitly verify/resolve the identity of an AutomotivePart with evidence and append-only decision trail.
     *
     * @param array{
     *     reason: string,
     *     verified_barcode?: ?string,
     *     identifier_value?: ?string,
     *     identifier_type?: ?string,
     *     allow_receipt?: bool
     * } $data
     */
    public function resolveIdentity(User $user, AutomotiveBranch $branch, AutomotivePart $part, array $data): AutomotivePart
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::IDENTITY_RESOLVE);

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe registrar un motivo o evidencia de verificación de al menos 5 caracteres.',
            ]);
        }
        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'La evidencia de verificación no puede exceder 2000 caracteres.',
            ]);
        }

        $verifiedBarcode = isset($data['verified_barcode']) && trim((string) $data['verified_barcode']) !== ''
            ? $this->validateCode($data['verified_barcode'], 'verified_barcode')
            : null;

        $identifierValue = isset($data['identifier_value']) && trim((string) $data['identifier_value']) !== ''
            ? $this->validateCode($data['identifier_value'], 'identifier_value')
            : $verifiedBarcode;

        $identifierType = (string) ($data['identifier_type'] ?? AutomotivePartIdentifier::TYPE_VERIFIED_BARCODE);
        if (! in_array($identifierType, AutomotivePartIdentifier::TYPES, true)) {
            throw ValidationException::withMessages(['identifier_type' => 'Tipo de identificador inválido.']);
        }

        return AutopartesModel::transaction(function () use ($user, $branch, $part, $reason, $verifiedBarcode, $identifierValue, $identifierType, $data): AutomotivePart {
            $lockedPart = AutomotivePart::query()->lockForUpdate()->findOrFail($part->getKey());

            if ($verifiedBarcode !== null) {
                $upperBarcode = mb_strtoupper($verifiedBarcode);
                $collisionPart = AutomotivePart::query()
                    ->whereKeyNot($lockedPart->getKey())
                    ->whereRaw('UPPER(TRIM(verified_barcode)) = ?', [$upperBarcode])
                    ->exists();
                $collisionIdentifier = AutomotivePartIdentifier::query()
                    ->where('automotive_part_id', '!=', $lockedPart->getKey())
                    ->whereRaw('UPPER(TRIM(identifier_value)) = ?', [$upperBarcode])
                    ->exists();

                if ($collisionPart || $collisionIdentifier) {
                    throw ValidationException::withMessages([
                        'verified_barcode' => 'El código de barras verificado ya está asignado a otra autoparte.',
                    ]);
                }
            }

            if ($identifierValue !== null) {
                $upperId = mb_strtoupper($identifierValue);
                $collisionOther = AutomotivePartIdentifier::query()
                    ->where('automotive_part_id', '!=', $lockedPart->getKey())
                    ->whereRaw('UPPER(TRIM(identifier_value)) = ?', [$upperId])
                    ->exists();
                $collisionVerified = AutomotivePart::query()
                    ->whereKeyNot($lockedPart->getKey())
                    ->whereRaw('UPPER(TRIM(verified_barcode)) = ?', [$upperId])
                    ->exists();

                if ($collisionOther || $collisionVerified) {
                    throw ValidationException::withMessages([
                        'identifier_value' => 'El identificador autorizado ya está asignado a otra autoparte.',
                    ]);
                }
            }

            $lockedPart->update([
                'identity_status' => AutomotivePart::STATUS_VERIFIED,
                'allow_receipt' => array_key_exists('allow_receipt', $data) ? (bool) $data['allow_receipt'] : true,
                'verified_barcode' => $verifiedBarcode ?? $lockedPart->verified_barcode,
                'verified_by' => $user->getKey(),
                'verified_at' => now(),
                'verification_evidence' => $reason,
            ]);

            if ($identifierValue !== null) {
                AutomotivePartIdentifier::query()->updateOrCreate(
                    [
                        'automotive_part_id' => $lockedPart->getKey(),
                        'identifier_value' => $identifierValue,
                    ],
                    [
                        'branch_id' => $branch->getKey(),
                        'identifier_type' => $identifierType,
                        'identifier_normalized' => AutomotivePart::normalizeCode($identifierValue) ?? mb_strtoupper($identifierValue),
                        'is_verified' => true,
                        'is_active' => true,
                        'evidence' => $reason,
                        'verified_by' => $user->getKey(),
                        'verified_at' => now(),
                    ]
                );
            }

            if ($lockedPart->source_row_id !== null) {
                $latestDecision = AutomotiveSourceProductDecision::query()
                    ->where('source_row_id', $lockedPart->source_row_id)
                    ->latest('id')
                    ->first();

                AutomotiveSourceProductDecision::create([
                    'source_row_id' => $lockedPart->source_row_id,
                    'automotive_part_id' => $lockedPart->getKey(),
                    'supersedes_id' => $latestDecision?->getKey(),
                    'decision' => 'linked',
                    'reason' => "Verificación de identidad ({$branch->code}): {$reason}",
                    'decided_by' => $user->getKey(),
                ]);
            }

            return $lockedPart->fresh(['sourceRow.sheet.file', 'identifiers']);
        });
    }

    /**
     * @param  Collection<int, AutomotivePart>  $parts
     * @return Collection<int, array<string, mixed>>
     */
    private function formatCandidates(Collection $parts, AutomotiveBranch $branch, ?string $scannedCode = null): Collection
    {
        $partIds = $parts->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $stockSummary = $this->stock->stockSummaryForPartIds($branch, $partIds);
        $verifierNames = AutopartesUserDirectory::mapByIds($parts->pluck('verified_by')->all());

        return $parts->map(function (AutomotivePart $part) use ($stockSummary, $verifierNames, $scannedCode): array {
            $row = $part->sourceRow;
            $cells = is_array($row?->cells) ? $row->cells : [];
            $excelQty = $cells[6]['display'] ?? ($cells[6]['value'] ?? '0');
            $retail = $cells[7]['display'] ?? ($cells[7]['value'] ?? null);
            $extRetail = $cells[8]['display'] ?? ($cells[8]['value'] ?? null);
            $verifier = $part->verified_by ? $verifierNames->get((int) $part->verified_by) : null;
            $branchStock = (int) ($stockSummary[(int) $part->getKey()]['physical'] ?? 0);

            return [
                'automotive_part_id' => (int) $part->getKey(),
                'code' => (string) $part->code,
                'is_active' => (bool) $part->is_active,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'verified_barcode' => $part->verified_barcode,
                'vendor_name' => $part->vendor_name,
                'category' => $part->category,
                'subcategory' => $part->subcategory,
                'description' => $part->description,
                'applicable_models' => $part->applicable_models,
                'identity_status' => $part->identity_status ?? AutomotivePart::STATUS_PROVISIONAL,
                'allow_receipt' => (bool) ($part->allow_receipt ?? true),
                'is_receivable' => $part->isReceivable(),
                'verification_evidence' => $part->verification_evidence,
                'verified_at' => $part->verified_at?->toIso8601String(),
                'verified_by_name' => $verifier?->name,
                'identifiers' => $part->identifiers->map(fn (AutomotivePartIdentifier $id) => [
                    'id' => $id->id,
                    'type' => $id->identifier_type,
                    'value' => $id->identifier_value,
                    'is_verified' => (bool) $id->is_verified,
                ])->values()->all(),
                'excel_qty' => (string) $excelQty,
                'excel_retail' => $retail !== null ? (string) $retail : null,
                'excel_ext_retail' => $extRetail !== null ? (string) $extRetail : null,
                'branch_stock' => $branchStock,
                'source_row_id' => $row?->getKey(),
                'source_row_number' => $row?->row_number,
                'source_sheet_name' => $row?->sheet?->name,
                'source_file_name' => $row?->sheet?->file?->original_name,
                'scanned_code' => $scannedCode,
            ];
        })->values();
    }

    private function ensureCanLookup(User $user, AutomotiveBranch $branch): void
    {
        $allowed = $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::CATALOG_VIEW)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::STOCK_VIEW)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::RECEIPT_DRAFT)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::RECEIPT_CONFIRM)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::ADJUSTMENT_CREATE)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL);

        if (! $allowed) {
            throw new AuthorizationException('No cuenta con permisos para consultar autopartes en esta sucursal.');
        }
    }
}
