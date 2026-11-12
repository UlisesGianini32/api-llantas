<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotivePosSale;
use App\Models\AutomotiveSeller;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class AutomotiveSellerService
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
    ) {}

    public function normalizeCode(string $code): string
    {
        $normalized = preg_replace('/[^A-Z0-9_-]+/', '', mb_strtoupper(trim($code)));

        return $normalized ?? '';
    }

    public function paginateSellers(AutomotiveBranch $branch, array $filters, User $actor, int $perPage = 25): LengthAwarePaginator
    {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::SELLERS_MANAGE)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::REPORTS_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::SELLERS_MANAGE);
        }

        $query = AutomotiveSeller::query()
            ->where('branch_id', $branch->getKey());

        if (($search = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('code', 'like', $like)
                    ->orWhere('full_name', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        if (isset($filters['active']) && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->orderByDesc('is_active')->orderBy('full_name')->paginate($perPage)->withQueryString();

        $sellerIds = $paginator->getCollection()->pluck('id')->all();
        $userIds = [];
        foreach ($paginator->getCollection() as $seller) {
            $userIds[] = $seller->user_id;
            $userIds[] = $seller->created_by;
            $userIds[] = $seller->updated_by;
        }
        $users = AutopartesUserDirectory::mapByIds($userIds);

        $statsBySeller = [];
        if (! empty($sellerIds)) {
            $statsRows = AutomotivePosSale::query()
                ->select('seller_id')
                ->selectRaw('COUNT(*) as sales_count')
                ->selectRaw('COALESCE(SUM(subtotal), 0) as gross_sales')
                ->selectRaw('COALESCE(SUM(discount_amount), 0) as total_discounts')
                ->selectRaw('COALESCE(SUM(total - refunded_amount), 0) as net_sales')
                ->where('branch_id', $branch->getKey())
                ->whereIn('seller_id', $sellerIds)
                ->where('status', '!=', AutomotivePosSale::STATUS_CANCELLED)
                ->groupBy('seller_id')
                ->get();

            foreach ($statsRows as $row) {
                $statsBySeller[(int) $row->seller_id] = [
                    'sales_count' => (int) $row->sales_count,
                    'gross_sales' => number_format((float) $row->gross_sales, 2, '.', ''),
                    'total_discounts' => number_format((float) $row->total_discounts, 2, '.', ''),
                    'net_sales' => number_format((float) $row->net_sales, 2, '.', ''),
                ];
            }
        }

        $paginator->getCollection()->transform(function (AutomotiveSeller $seller) use ($statsBySeller, $users): array {
            $stats = $statsBySeller[$seller->id] ?? [
                'sales_count' => 0,
                'gross_sales' => '0.00',
                'total_discounts' => '0.00',
                'net_sales' => '0.00',
            ];

            $linkedUser = $seller->user_id ? $users->get((int) $seller->user_id) : null;
            $creator = $seller->created_by ? $users->get((int) $seller->created_by) : null;
            $updater = $seller->updated_by ? $users->get((int) $seller->updated_by) : null;

            return [
                'id' => $seller->id,
                'branch_id' => $seller->branch_id,
                'code' => $seller->code,
                'full_name' => $seller->full_name,
                'phone' => $seller->phone,
                'notes' => $seller->notes,
                'is_active' => (bool) $seller->is_active,
                'user_id' => $seller->user_id,
                'user_name' => $linkedUser?->name,
                'user_email' => $linkedUser?->email,
                'created_by_name' => $creator?->name,
                'updated_by_name' => $updater?->name,
                'created_at' => $seller->created_at?->toIso8601String(),
                'stats' => $stats,
            ];
        });

        return $paginator;
    }

    /** @return Collection<int, AutomotiveSeller> */
    public function listActiveForPos(AutomotiveBranch $branch): Collection
    {
        return AutomotiveSeller::query()
            ->where('branch_id', $branch->getKey())
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get(['id', 'branch_id', 'user_id', 'code', 'full_name', 'is_active']);
    }

    public function createSeller(AutomotiveBranch $branch, array $data, User $actor): AutomotiveSeller
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::SELLERS_MANAGE);

        $code = $this->normalizeCode((string) ($data['code'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));

        if ($code === '' || mb_strlen($code) < 2 || mb_strlen($code) > 48) {
            throw ValidationException::withMessages([
                'code' => 'El código interno del vendedor debe tener entre 2 y 48 caracteres alfanuméricos.',
            ]);
        }

        if ($fullName === '' || mb_strlen($fullName) < 3) {
            throw ValidationException::withMessages([
                'full_name' => 'El nombre completo del vendedor es obligatorio (mínimo 3 caracteres).',
            ]);
        }

        $userId = ! empty($data['user_id']) ? (int) $data['user_id'] : null;
        if ($userId !== null && ! AutopartesUserDirectory::exists($userId)) {
            throw ValidationException::withMessages([
                'user_id' => 'La cuenta de usuario seleccionada no existe.',
            ]);
        }

        return AutopartesModel::transaction(function () use ($branch, $code, $fullName, $userId, $data, $actor): AutomotiveSeller {
            $exists = AutomotiveSeller::query()
                ->where('branch_id', $branch->getKey())
                ->where('code', $code)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'code' => "El código de vendedor [{$code}] ya está registrado en la sucursal {$branch->code}.",
                ]);
            }

            return AutomotiveSeller::create([
                'branch_id' => $branch->getKey(),
                'user_id' => $userId,
                'code' => $code,
                'full_name' => $fullName,
                'phone' => isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });
    }

    public function updateSeller(AutomotiveBranch $branch, AutomotiveSeller $seller, array $data, User $actor): AutomotiveSeller
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::SELLERS_MANAGE);

        if ((int) $seller->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'seller' => 'El vendedor no pertenece a esta sucursal.',
            ]);
        }

        return AutopartesModel::transaction(function () use ($branch, $seller, $data, $actor): AutomotiveSeller {
            $locked = AutomotiveSeller::query()->lockForUpdate()->findOrFail($seller->getKey());

            $code = array_key_exists('code', $data)
                ? $this->normalizeCode((string) $data['code'])
                : $locked->code;

            $fullName = array_key_exists('full_name', $data)
                ? trim((string) $data['full_name'])
                : $locked->full_name;

            if ($code === '' || mb_strlen($code) < 2 || mb_strlen($code) > 48) {
                throw ValidationException::withMessages([
                    'code' => 'El código interno del vendedor debe tener entre 2 y 48 caracteres alfanuméricos.',
                ]);
            }

            if ($fullName === '' || mb_strlen($fullName) < 3) {
                throw ValidationException::withMessages([
                    'full_name' => 'El nombre completo del vendedor es obligatorio (mínimo 3 caracteres).',
                ]);
            }

            $duplicate = AutomotiveSeller::query()
                ->where('branch_id', $branch->getKey())
                ->where('code', $code)
                ->whereKeyNot($locked->getKey())
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'code' => "El código de vendedor [{$code}] ya pertenece a otro vendedor en {$branch->code}.",
                ]);
            }

            $userId = array_key_exists('user_id', $data)
                ? (! empty($data['user_id']) ? (int) $data['user_id'] : null)
                : $locked->user_id;

            if ($userId !== null && ! AutopartesUserDirectory::exists($userId)) {
                throw ValidationException::withMessages([
                    'user_id' => 'La cuenta de usuario seleccionada no existe.',
                ]);
            }

            $locked->update([
                'code' => $code,
                'full_name' => $fullName,
                'user_id' => $userId,
                'phone' => array_key_exists('phone', $data)
                    ? (trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null)
                    : $locked->phone,
                'notes' => array_key_exists('notes', $data)
                    ? (trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null)
                    : $locked->notes,
                'is_active' => array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : $locked->is_active,
                'updated_by' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }

    public function validateActiveSellerForBranch(AutomotiveBranch $branch, int $sellerId): AutomotiveSeller
    {
        $seller = AutomotiveSeller::query()->find($sellerId);
        if (! $seller || (int) $seller->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'seller_id' => 'El vendedor seleccionado no pertenece a la sucursal '.$branch->code.'.',
            ]);
        }

        if (! $seller->is_active) {
            throw ValidationException::withMessages([
                'seller_id' => "El vendedor [{$seller->code} — {$seller->full_name}] se encuentra inactivo y no puede asignarse a ventas nuevas.",
            ]);
        }

        return $seller;
    }
}
