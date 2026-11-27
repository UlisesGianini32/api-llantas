<?php

namespace App\Http\Requests\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CancelConfirmedWarehouseDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var InventoryDispatch|null $dispatch */
        $dispatch = $this->route('inventoryDispatch');

        return $this->user() !== null
            && $dispatch !== null
            && Gate::forUser($this->user())->allows('cancelConfirmedWithoutLedger', $dispatch);
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'min:5', 'max:1000'],
            'physical_situation' => ['required', 'string', Rule::in([
                InventoryDispatch::CANCEL_SITUATION_NEVER_DELIVERED,
                'DELIVERED_AND_RETURNED',
            ])],
        ];
    }
}
