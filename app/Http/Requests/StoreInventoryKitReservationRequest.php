<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryKitReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
            'external_key' => ['nullable', 'string', 'max:191'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
