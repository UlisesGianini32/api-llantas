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
        return [
            'message' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf', 'extensions:jpg,jpeg,png,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'attachments.max' => 'Puedes adjuntar como máximo 5 archivos por mensaje.',
            'attachments.*.max' => 'Cada archivo debe pesar como máximo 5 MB.',
            'attachments.*.mimetypes' => 'Solo puedes adjuntar archivos JPG, PNG o PDF.',
            'attachments.*.extensions' => 'Solo puedes adjuntar archivos JPG, PNG o PDF.',
        ];
    }
}
