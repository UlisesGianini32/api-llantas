<?php

namespace App\Http\Requests\Inventory\Dispatches;

use App\Models\InventoryDispatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class LinkProvisionalItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('linkProvisional', InventoryDispatch::class);
    }

    public function rules(): array
    {
        return [
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'link_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
