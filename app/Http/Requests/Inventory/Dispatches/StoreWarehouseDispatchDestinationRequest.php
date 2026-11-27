<?php

namespace App\Http\Requests\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchDestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreWarehouseDispatchDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('create', InventoryDispatchDestination::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('inventory_dispatch_destinations', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'destination_type' => ['required', 'string', Rule::in(InventoryDispatchDestination::TYPES)],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'default_operation_type' => ['nullable', 'string', Rule::in(InventoryDispatch::OPERATION_TYPES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
