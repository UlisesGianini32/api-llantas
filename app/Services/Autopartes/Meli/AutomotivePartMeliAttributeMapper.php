<?php

namespace App\Services\Autopartes\Meli;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliAttributeRequirement;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AutomotivePartMeliAttributeMapper
{
    public function map(AutomotivePart $part, Collection $requirements): array
    {
        $proposed = [];
        $missingRequired = [];
        $missingConditional = [];
        $warnings = [];

        foreach ($requirements as $requirement) {
            $mapped = $this->mapRequirement($part, $requirement, $warnings);
            if ($mapped !== null) {
                $proposed[] = $mapped;

                continue;
            }

            $missing = ['attribute_id' => $requirement->attribute_id, 'name' => $requirement->name];
            if ($requirement->is_conditional_required) {
                $missingConditional[] = $missing;
            } elseif ($requirement->is_required || $requirement->is_catalog_required) {
                $missingRequired[] = $missing;
            }
        }

        return compact('proposed', 'missingRequired', 'missingConditional', 'warnings');
    }

    private function mapRequirement(
        AutomotivePart $part,
        AutomotivePartMeliAttributeRequirement $requirement,
        array &$warnings,
    ): ?array {
        $id = strtoupper($requirement->attribute_id);
        $name = mb_strtolower(Str::ascii($requirement->name));

        if (str_contains($id, 'GTIN') || in_array($id, ['EAN', 'UPC'], true)) {
            $warnings[] = "{$requirement->attribute_id}: GTIN no se infiere ni se sustituye con Item #.";

            return null;
        }

        if (in_array($id, ['SELLER_SKU'], true)) {
            return $this->proposal($requirement, $part->item_number, null, 'item_number', 'identity', 1.0);
        }

        if (in_array($id, ['MPN', 'PART_NUMBER', 'MANUFACTURER_PART_NUMBER'], true)) {
            return $this->proposal($requirement, $part->manufacturer_part_number, null, 'manufacturer_part_number', 'identity', 1.0);
        }

        if ($id === 'BRAND') {
            return $this->allowedValueProposal($requirement, $part->vendor, 'vendor', $warnings);
        }

        if (str_contains($name, 'empaque') || str_contains($name, 'embalaje') || str_contains($name, 'paquete')) {
            $warnings[] = "{$requirement->attribute_id}: no se usaron dimensiones de origen como dimensiones de empaque.";

            return null;
        }

        $dimension = match (true) {
            str_contains($name, 'largo del producto'), str_contains($name, 'longitud de la pieza') => ['length_cm', $part->length_cm],
            str_contains($name, 'ancho del producto'), str_contains($name, 'ancho de la pieza') => ['width_cm', $part->width_cm],
            str_contains($name, 'altura del producto'), str_contains($name, 'alto de la pieza') => ['height_cm', $part->height_cm],
            default => null,
        };
        if ($dimension !== null) {
            return $this->proposal($requirement, $dimension[1], 'cm', $dimension[0], 'normalized_unit_preserved', 1.0);
        }

        if (str_contains($name, 'peso del producto') || str_contains($name, 'peso de la pieza')) {
            return $this->proposal($requirement, $part->weight_kg, 'kg', 'weight_kg', 'normalized_unit_preserved', 1.0);
        }

        if (in_array($id, ['MODEL', 'VEHICLE_YEAR', 'YEAR', 'COMPATIBILITIES'], true)) {
            $warnings[] = "{$requirement->attribute_id}: no se convirtió texto ambiguo en modelo, año o compatibilidad estructurada.";
        }

        return null;
    }

    private function allowedValueProposal(
        AutomotivePartMeliAttributeRequirement $requirement,
        mixed $value,
        string $sourceField,
        array &$warnings,
    ): ?array {
        if (blank($value)) {
            return null;
        }

        $allowedValues = collect($requirement->allowed_values ?? []);
        if ($allowedValues->isEmpty()) {
            return $this->proposal($requirement, $value, null, $sourceField, 'identity', 1.0);
        }

        $normalized = mb_strtolower(trim(Str::ascii((string) $value)));
        $match = $allowedValues->first(fn ($allowed) => is_array($allowed)
            && mb_strtolower(trim(Str::ascii((string) ($allowed['name'] ?? '')))) === $normalized);
        if ($match === null) {
            $warnings[] = "{$requirement->attribute_id}: no se seleccionó allowed_value por parecido débil.";

            return null;
        }

        return $this->proposal($requirement, $match['name'], null, $sourceField, 'exact_allowed_value', 1.0, $match['id'] ?? null);
    }

    private function proposal(
        AutomotivePartMeliAttributeRequirement $requirement,
        mixed $value,
        ?string $unit,
        string $sourceField,
        string $transformation,
        ?float $confidence,
        mixed $valueId = null,
    ): ?array {
        if (blank($value)) {
            return null;
        }

        return [
            'attribute_id' => $requirement->attribute_id,
            'value' => (string) $value,
            'value_id' => $valueId === null ? null : (string) $valueId,
            'unit' => $unit,
            'source_field' => $sourceField,
            'transformation' => $transformation,
            'confidence' => $confidence,
            'warnings' => [],
        ];
    }
}
