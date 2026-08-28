<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class PreviewAutomotivePartPriceRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['automotive_part_id' => ['required', 'integer', 'exists:automotive_parts,id']]; }
}
