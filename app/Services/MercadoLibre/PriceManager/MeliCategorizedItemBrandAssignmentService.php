<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeliCategorizedItemBrandAssignmentService
{
    public function __construct(private readonly MeliItemClassificationActionService $classificationActions) {}

    public function assign(MeliPriceManagerItem $item, MeliBrandGroup $brand, int $userId): bool
    {
        if ((int) $item->brand_group_id === (int) $brand->id) {
            return false;
        }

        $this->classificationActions->assignBrand($item, $brand, $userId);

        return true;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{changed: int, unchanged: int, brand: MeliBrandGroup}
     */
    public function bulk(int $accountId, array $itemIds, int $brandId, int $userId): array
    {
        return DB::transaction(function () use ($accountId, $itemIds, $brandId, $userId): array {
            $account = MeliAccount::query()
                ->whereKey($accountId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first(['id']);

            if (! $account) {
                throw ValidationException::withMessages([
                    'meli_account_id' => 'La cuenta seleccionada no está disponible.',
                ]);
            }

            $brand = MeliBrandGroup::query()
                ->whereKey($brandId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $brand) {
                throw ValidationException::withMessages([
                    'brand_group_id' => 'Selecciona una marca interna activa.',
                ]);
            }

            $items = MeliPriceManagerItem::query()
                ->focusedCatalog()
                ->where('meli_account_id', $accountId)
                ->where('classification_status', 'categorized')
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'La selección cambió o contiene publicaciones no disponibles para esta operación.',
                ]);
            }

            $changed = 0;
            foreach ($items as $item) {
                if ($this->assign($item, $brand, $userId)) {
                    $changed++;
                }
            }

            return [
                'changed' => $changed,
                'unchanged' => $items->count() - $changed,
                'brand' => $brand,
            ];
        });
    }
}
