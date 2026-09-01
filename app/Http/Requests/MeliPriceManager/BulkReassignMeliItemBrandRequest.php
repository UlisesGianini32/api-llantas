<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliPriceManagerItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkReassignMeliItemBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meli_account_id' => [
                'required',
                'integer',
                Rule::exists('meli_accounts', 'id')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
            'brand_group_id' => [
                'required',
                'integer',
                Rule::exists('meli_brand_groups', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'confirm' => ['required', 'accepted'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['meli_account_id', 'item_ids', 'item_ids.*'])) {
                return;
            }

            $ids = array_map('intval', $this->input('item_ids', []));
            $items = MeliPriceManagerItem::query()
                ->focusedCatalog()
                ->where('meli_account_id', $this->integer('meli_account_id'))
                ->whereIn('id', $ids)
                ->get(['id', 'classification_status']);

            if ($items->count() !== count($ids)
                || $items->contains(fn (MeliPriceManagerItem $item): bool => $item->classification_status !== 'categorized')) {
                $validator->errors()->add(
                    'item_ids',
                    'La selección contiene publicaciones no disponibles para esta operación.',
                );
            }
        }];
    }
}
