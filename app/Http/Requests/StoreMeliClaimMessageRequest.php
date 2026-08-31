<?php

namespace App\Http\Requests;

use App\Models\MeliClaim;
use Illuminate\Foundation\Http\FormRequest;

class StoreMeliClaimMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $claim = $this->route('claim');
        if (! $claim instanceof MeliClaim || ! $this->user()?->meliAccounts()->whereKey($claim->meli_account_id)->exists()) {
            abort(404);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['message' => trim((string) $this->input('message', ''))]);
    }

    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:2000']];
    }
}
