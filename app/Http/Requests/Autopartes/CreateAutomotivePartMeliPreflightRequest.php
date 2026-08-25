<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class CreateAutomotivePartMeliPreflightRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['draft_id' => ['required', 'integer', 'exists:automotive_part_meli_drafts,id'],
        'meli_account_id' => ['required', 'integer', 'exists:meli_accounts,id']]; }
}
