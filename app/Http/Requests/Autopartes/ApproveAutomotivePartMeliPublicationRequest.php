<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAutomotivePartMeliPublicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return [
        'note' => ['required', 'string', 'max:2000'], 'confirm_account_id' => ['required', 'integer'],
        'confirm_title' => ['required', 'string', 'max:255'], 'confirm_price' => ['required', 'numeric', 'gt:0'],
        'confirm_stock' => ['required', 'integer', 'gt:0'], 'confirm_category_id' => ['required', 'string', 'regex:/^MLM[0-9]+$/'],
        'confirm_fingerprint_suffix' => ['required', 'string', 'size:8'],
    ]; }
}
