<?php

namespace App\Http\Requests\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MergeProvisionalItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('mergeProvisional', InventoryDispatch::class);
    }

    public function rules(): array
    {
        return [
            'target_provisional_item_id' => ['required', 'integer', 'exists:inventory_provisional_items,id'],
            'merge_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
