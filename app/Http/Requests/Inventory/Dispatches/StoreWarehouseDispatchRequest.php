<?php

namespace App\Http\Requests\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryProvisionalItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreWarehouseDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('create', InventoryDispatch::class);
    }

    public function rules(): array
    {
        return [
            'create_idempotency_key' => ['nullable', 'string', 'max:100'],
            'destination_id' => ['required', 'integer', 'exists:inventory_dispatch_destinations,id'],
            'source_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'operation_type' => ['required', 'string', Rule::in(InventoryDispatch::OPERATION_TYPES)],
            'effective_at' => ['nullable', 'date'],
            'retroactive_reason' => ['nullable', 'string', 'max:1000'],
            'received_by_name' => ['nullable', 'string', 'max:150'],
            'received_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.kind' => ['required', 'string', Rule::in(['registered', 'provisional'])],
            'items.*.inventory_product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'items.*.provisional_item_id' => ['nullable', 'integer', 'exists:inventory_provisional_items,id'],
            'items.*.inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'items.*.capture_method' => ['nullable', 'string', Rule::in(InventoryDispatchItem::CAPTURE_METHODS)],
            'items.*.captured_barcode' => ['nullable', 'string', 'max:100'],
            'items.*.captured_sku' => ['nullable', 'string', 'max:100'],
            'items.*.captured_name' => ['nullable', 'string', 'max:255'],
            'items.*.captured_brand' => ['nullable', 'string', 'max:100'],
            'items.*.captured_line' => ['nullable', 'string', 'max:100'],
            'items.*.captured_tone' => ['nullable', 'string', 'max:50'],
            'items.*.captured_presentation' => ['nullable', 'string', 'max:100'],
            'items.*.captured_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.captured_unit_of_measure' => ['nullable', 'string', Rule::in(InventoryProvisionalItem::UNITS_OF_MEASURE)],
            'items.*.unit_conversion_factor' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.is_ambiguous' => ['nullable', 'boolean'],
            'items.*.line_notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
