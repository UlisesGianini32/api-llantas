<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeliItemPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'simulation_token' => ['required', 'string', 'size:64'],
            'price' => ['nullable', 'numeric', 'gt:0', 'max:999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'simulation_token.required' => 'Primero debes calcular los cargos y confirmar una simulación vigente.',
            'simulation_token.size' => 'El token de simulación no es válido.',
            'price.numeric' => 'El precio debe ser numérico.',
            'price.gt' => 'El precio debe ser mayor que cero.',
            'price.max' => 'El precio excede el máximo permitido.',
        ];
    }
}
