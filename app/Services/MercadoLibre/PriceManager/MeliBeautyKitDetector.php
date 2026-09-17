<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliPriceManagerItem;
use Illuminate\Support\Str;

class MeliBeautyKitDetector
{
    public function isKit(MeliPriceManagerItem $item): bool
    {
        $structured = $this->structuredValue($item);
        if ($structured !== null) {
            return $structured;
        }

        $text = Str::ascii(Str::lower(trim((string) $item->title.' '.(string) $item->sku)));

        return preg_match('/\b(?:kit|combo|pack|set|duo|trio|juego)\b|\b[2-9]\s*(?:pzas?|piezas?|unidades?|uds?)\b/', $text) === 1;
    }

    private function structuredValue(MeliPriceManagerItem $item): ?bool
    {
        $payloads = [
            (array) $item->classification_metadata,
            (array) $item->raw_item,
            (array) $item->raw_attributes,
        ];

        foreach ($payloads as $payload) {
            $attributeValue = $this->attributeValue($payload);
            if ($attributeValue !== null) {
                return $attributeValue;
            }
            foreach ($this->flatten($payload) as $key => $value) {
                $key = strtoupper((string) $key);
                if (in_array($key, ['IS_KIT', 'KIT'], true)) {
                    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
                }
                if (in_array($key, ['UNITS_PER_PACK', 'PACK_QUANTITY', 'PACKAGE_QUANTITY'], true) && is_numeric($value)) {
                    return (float) $value > 1;
                }
                if ($key === 'SALE_FORMAT' && is_string($value)) {
                    return preg_match('/kit|combo|pack|set|duo|trio/i', $value) === 1;
                }
            }
        }

        return null;
    }

    private function attributeValue(array $payload): ?bool
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $id = strtoupper(trim((string) ($value['id'] ?? '')));
                $attribute = $value['value_name'] ?? $value['value'] ?? null;
                if (in_array($id, ['IS_KIT', 'KIT'], true)) {
                    return filter_var($attribute, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
                }
                if (in_array($id, ['UNITS_PER_PACK', 'PACK_QUANTITY', 'PACKAGE_QUANTITY'], true) && is_numeric($attribute)) {
                    return (float) $attribute > 1;
                }
                if ($id === 'SALE_FORMAT' && is_string($attribute)) {
                    return preg_match('/kit|combo|pack|set|duo|trio/i', $attribute) === 1;
                }

                $nested = $this->attributeValue($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function flatten(array $payload): array
    {
        $values = [];
        array_walk_recursive($payload, static function (mixed $value, mixed $key) use (&$values): void {
            $values[(string) $key] = $value;
        });

        return $values;
    }
}
