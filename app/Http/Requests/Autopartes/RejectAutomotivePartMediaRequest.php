<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class RejectAutomotivePartMediaRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['notes' => ['required', 'string', 'max:2000']]; }
}
