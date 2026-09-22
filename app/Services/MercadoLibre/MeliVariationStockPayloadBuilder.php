<?php

namespace App\Services\MercadoLibre;

use RuntimeException;

class MeliVariationStockPayloadBuilder
{
    /**
     * Construye un PUT seguro para actualizar una sola variante,
     * preservando todos los IDs que Mercado Libre devolvio.
     *
     * @param  array<int, mixed>  $variations
     * @return array{variations: array<int, array<string, int|string>>}
     */
    public function build(
        array $variations,
        string $targetVariationId,
        int $stock,
    ): array {
        if ($variations === []) {
            throw new RuntimeException(
                'Mercado Libre no devolvio las variantes actuales de la publicacion.'
            );
        }

        $targetVariationId = trim($targetVariationId);
        $targetFound = false;
        $payloadVariations = [];

        foreach ($variations as $variation) {
            if (! is_array($variation)) {
                throw new RuntimeException(
                    'Mercado Libre devolvio una variante con formato invalido.'
                );
            }

            $id = $variation['id'] ?? null;

            if ($id === null || $id === '') {
                throw new RuntimeException(
                    'Mercado Libre devolvio una variante sin identificador.'
                );
            }

            $row = [
                'id' => is_numeric($id) ? (int) $id : (string) $id,
            ];

            if ((string) $id === $targetVariationId) {
                $row['available_quantity'] = max(0, $stock);
                $targetFound = true;
            }

            $payloadVariations[] = $row;
        }

        if (! $targetFound) {
            throw new RuntimeException(
                'La variante seleccionada ya no existe actualmente en Mercado Libre.'
            );
        }

        return [
            'variations' => $payloadVariations,
        ];
    }
}
