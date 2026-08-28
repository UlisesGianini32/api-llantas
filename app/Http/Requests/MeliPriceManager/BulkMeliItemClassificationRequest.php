<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliPriceManagerItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkMeliItemClassificationRequest extends FormRequest
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
            'item_ids' => ['required', 'array', 'min:1', 'max:200'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
            'action' => ['required', Rule::in(['assign', 'accept_suggestions', 'ignore', 'restore'])],
            'brand_group_id' => [
                'nullable',
                'required_if:action,assign',
                'integer',
                Rule::exists('meli_brand_groups', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['meli_account_id', 'item_ids', 'item_ids.*', 'action'])) {
                return;
            }

            $ids = array_map('intval', $this->input('item_ids', []));
            $items = MeliPriceManagerItem::query()
                ->focusedCatalog()
                ->whereIn('id', $ids)
                ->where('meli_account_id', $this->integer('meli_account_id'))
                ->get(['id', 'classification_status', 'suggested_brand_group_id']);

            if ($items->count() !== count($ids)) {
                $validator->errors()->add('item_ids', 'La selección contiene publicaciones inexistentes, excluidas del Price Manager o de otra cuenta.');

                return;
            }

            if ($this->input('action') === 'accept_suggestions'
                && $items->contains(fn (MeliPriceManagerItem $item) => $item->classification_status !== 'suggested' || $item->suggested_brand_group_id === null)) {
                $validator->errors()->add('item_ids', 'Todas las publicaciones deben tener una sugerencia válida.');
            }

            if ($this->input('action') === 'restore'
                && $items->contains(fn (MeliPriceManagerItem $item) => $item->classification_status !== 'ignored')) {
                $validator->errors()->add('item_ids', 'Solo se pueden restaurar publicaciones ignoradas.');
            }

            if (in_array($this->input('action'), ['assign', 'ignore'], true)
                && $items->contains(fn (MeliPriceManagerItem $item) => ! in_array($item->classification_status, ['uncategorized', 'suggested', 'ignored'], true))) {
                $validator->errors()->add('item_ids', 'La selección contiene publicaciones fuera de esta bandeja.');
            }
        }];
    }
}
