<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class ReorderAutomotivePartMediaRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['media_ids' => ['required', 'array', 'max:100'], 'media_ids.*' => ['required', 'integer', 'distinct', 'min:1']]; }
}
