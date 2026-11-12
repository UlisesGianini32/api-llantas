<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class AutomotiveSourceSchema
{
    public const HEADERS = [
        'Category', 'Subcategory', 'Item #', 'MFG Part #', 'Vendor', 'Item Description',
        'Qty', 'Retail', 'Ext. Retail', 'Sku Life Cycle', 'Min Model Year', 'Avg Model Year',
        'Max Model Year', 'Most Prevalent Model', 'All Applicable Models', 'Length', 'Width',
        'Height', 'Cubic Inches', 'Weight', 'Ext. Weight',
    ];

    /** Numeric values are strings to avoid JSON float rounding; original bytes remain in the file. */
    public static function validateCells(mixed $cells): void
    {
        if (! is_array($cells) || ! array_is_list($cells) || count($cells) !== count(self::HEADERS)) {
            throw ValidationException::withMessages(['cells' => 'Se requieren las 21 celdas, incluidas las vacías.']);
        }

        foreach ($cells as $index => $cell) {
            $valid = is_array($cell)
                && ($cell['column'] ?? null) === chr(65 + $index)
                && isset($cell['present']) && is_bool($cell['present'])
                && array_key_exists('value', $cell)
                && isset($cell['type'])
                && match ($cell['type']) {
                    'null' => $cell['value'] === null,
                    'n' => is_string($cell['value']) && is_numeric($cell['value']),
                    'b' => is_bool($cell['value']),
                    's', 'str', 'inlineStr', 'f', 'e' => is_string($cell['value']),
                    default => false,
                };

            if (! $valid || (! $cell['present'] && $cell['type'] !== 'null')) {
                throw ValidationException::withMessages(['cells.'.$index => 'La celda debe conservar posición, presencia, tipo y valor original.']);
            }
        }
    }
}
