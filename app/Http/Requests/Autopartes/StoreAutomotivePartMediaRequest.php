<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomotivePartMediaRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'max:'.max(1, (int) config('autopartes_media_pricing.media_max_file_kb', 5120))],
            'provenance_type' => ['required', Rule::in(AutomotivePartMedia::PROVENANCE_TYPES)],
            'provenance_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
