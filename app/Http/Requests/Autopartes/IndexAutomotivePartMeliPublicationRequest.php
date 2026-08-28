<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliPublication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAutomotivePartMeliPublicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['q' => ['nullable', 'string', 'max:120'],
        'status' => ['nullable', Rule::in(AutomotivePartMeliPublication::STATUSES)],
        'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]; }
}
