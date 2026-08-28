<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;

class SimulateMeliItemPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'gt:0', 'max:999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'price.required' => 'Escribe el precio que deseas simular.',
            'price.numeric' => 'El precio debe ser numérico.',
            'price.gt' => 'El precio debe ser mayor que cero.',
            'price.max' => 'El precio excede el máximo permitido para la simulación.',
        ];
    }
}
