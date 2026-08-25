<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReconcileAutomotivePartMeliPublicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['resolution' => ['required', Rule::in(['item_found', 'not_created'])],
        'meli_item_id' => ['nullable', 'required_if:resolution,item_found', 'regex:/^MLM[0-9]+$/'],
        'note' => ['required', 'string', 'max:2000']]; }
}
