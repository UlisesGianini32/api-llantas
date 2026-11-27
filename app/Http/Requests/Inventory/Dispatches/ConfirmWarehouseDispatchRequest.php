<?php

namespace App\Http\Requests\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ConfirmWarehouseDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var InventoryDispatch|null $dispatch */
        $dispatch = $this->route('inventoryDispatch');

        return $this->user() !== null
            && $dispatch !== null
            && Gate::forUser($this->user())->allows('confirm', $dispatch);
    }

    public function rules(): array
    {
        return [
            'confirm_idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'expected_draft_version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
