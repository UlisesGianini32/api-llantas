<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class NoteAutomotivePartMeliPublicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['note' => ['required', 'string', 'max:2000']]; }
}
