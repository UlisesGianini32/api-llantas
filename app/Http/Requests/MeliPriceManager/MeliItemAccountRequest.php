<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliPriceManagerItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class MeliItemAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function accountRules(): array
    {
        return [
            'meli_account_id' => [
                'required',
                'integer',
                Rule::exists('meli_accounts', 'id')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $item = $this->item();

            if ($item !== null && $item->meli_account_id !== $this->integer('meli_account_id')) {
                $validator->errors()->add('item', 'La publicación no pertenece a la cuenta seleccionada.');
            }

            if ($item !== null && ! MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists()) {
                $validator->errors()->add('item', 'La publicación está fuera del catálogo enfocado de Meli Price Manager.');
            }
        }];
    }

    protected function item(): ?MeliPriceManagerItem
    {
        $item = $this->route('item');

        return $item instanceof MeliPriceManagerItem ? $item : null;
    }

    /** @param list<string> $statuses */
    protected function validateStatus(Validator $validator, array $statuses, string $message): void
    {
        $item = $this->item();
        if ($item !== null && ! in_array($item->classification_status, $statuses, true)) {
            $validator->errors()->add('item', $message);
        }
    }
}
