<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliPriceManagerItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'price' => ['required', 'numeric', 'gt:0', 'max:999999999.99'],
            'listing_type_id' => ['required', 'string', Rule::in(MeliPriceManagerItem::SUPPORTED_LISTING_TYPE_IDS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'simulation_token.required' => 'Primero debes calcular el resultado y confirmar una proyección vigente.',
            'simulation_token.size' => 'El token de proyección no es válido.',
            'price.numeric' => 'El precio debe ser numérico.',
            'price.gt' => 'El precio debe ser mayor que cero.',
            'price.max' => 'El precio excede el máximo permitido.',
            'listing_type_id.required' => 'Selecciona el tipo de publicación confirmado en la proyección.',
            'listing_type_id.in' => 'El tipo de publicación seleccionado no es compatible. Usa Clásica o Premium.',
        ];
    }
}
