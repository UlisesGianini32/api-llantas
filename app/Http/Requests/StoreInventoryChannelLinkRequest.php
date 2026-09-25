<?php

namespace App\Http\Requests;

use App\Models\InventoryChannelLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryChannelLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'channel' => ['required', 'string', Rule::in(InventoryChannelLink::CHANNELS)],
            'account_key' => ['nullable', 'string', 'max:255'],
            'external_product_id' => ['nullable', 'string', 'max:255'],
            'external_variant_id' => ['nullable', 'string', 'max:255'],
            'external_listing_id' => ['nullable', 'string', 'max:255'],
            'external_url' => ['nullable', 'url:http,https', 'max:2048'],
            'remote_status' => ['nullable', 'string', 'max:64'],
            'remote_price' => ['nullable', 'numeric', 'min:0'],
            'remote_currency' => ['nullable', 'string', 'max:8'],
            'last_synced_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'channel' => strtolower(trim((string) $this->input('channel', ''))),
            'account_key' => $this->nullableTrim('account_key'),
            'external_product_id' => $this->nullableTrim('external_product_id'),
            'external_variant_id' => $this->nullableTrim('external_variant_id'),
            'external_listing_id' => $this->nullableTrim('external_listing_id'),
            'external_url' => $this->nullableTrim('external_url'),
            'remote_currency' => $this->nullableTrimUpper('remote_currency'),
        ]);
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $channel = (string) $this->input('channel');
            if ($channel === InventoryChannelLink::MERCADO_LIBRE && blank($this->input('external_listing_id'))) {
                $validator->errors()->add('external_listing_id', 'Mercado Libre requiere el ID de publicación.');
            }
            if ($channel === InventoryChannelLink::AMAZON
                && blank($this->input('external_product_id'))
                && blank($this->input('external_listing_id'))) {
                $validator->errors()->add('external_product_id', 'Amazon requiere el ID de producto o de publicación.');
            }
            if ($channel === InventoryChannelLink::SHOPIFY && blank($this->input('external_variant_id'))) {
                $validator->errors()->add('external_variant_id', 'Shopify requiere el ID de variante.');
            }
        });
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }

    private function nullableTrimUpper(string $key): ?string
    {
        $value = $this->nullableTrim($key);

        return $value === null ? null : strtoupper($value);
    }
}
