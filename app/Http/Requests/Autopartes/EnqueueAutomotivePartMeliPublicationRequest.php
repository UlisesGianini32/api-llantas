<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class EnqueueAutomotivePartMeliPublicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['confirm_live_publication' => ['required', 'accepted']]; }
}
