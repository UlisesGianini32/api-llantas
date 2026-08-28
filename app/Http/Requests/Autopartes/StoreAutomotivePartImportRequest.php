<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class StoreAutomotivePartImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xls,xlsx'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => 'archivo Excel',
        ];
    }
}
