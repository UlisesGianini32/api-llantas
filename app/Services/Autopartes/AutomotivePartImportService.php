<?php

namespace App\Services\Autopartes;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartImport;
use App\Models\AutomotivePartImportRow;
use App\Models\AutomotivePartStockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutomotivePartImportService
{
    public function __construct(
        protected AutomotivePartNormalizer $normalizer,
    ) {}

    public function processRows(int $importId, Collection $rows): array
    {
        $import = AutomotivePartImport::query()->findOrFail($importId);
        $startIndex = $this->findDataStartIndex($rows);
        $dataRows = $rows->slice($startIndex);

        $stats = [
            'total_rows' => 0,
            'imported_rows' => 0,
            'updated_rows' => 0,
            'duplicate_rows' => 0,
            'invalid_rows' => 0,
            'missing_compatibility_rows' => 0,
        ];

        $seenSourceKeys = [];

        $dataRows->each(function ($row, $rowNumber) use ($import, &$stats, &$seenSourceKeys) {
            $values = $this->normalizeRowValues($row);

            if ($this->isEmptyRow($values)) {
                return;
            }

            $stats['total_rows']++;

            $payload = $this->buildNormalizedPayload($values);
            $sourceKey = $payload['source_key'];

            $rowRecord = AutomotivePartImportRow::query()->create([
                'automotive_part_import_id' => $import->id,
                'row_number' => $rowNumber + 1,
                'source_key' => $sourceKey,
                'category_raw' => $payload['category_raw'],
                'subcategory_raw' => $payload['subcategory_raw'],
                'item_number_raw' => $payload['item_number_raw'],
                'manufacturer_part_number_raw' => $payload['manufacturer_part_number_raw'],
                'vendor_raw' => $payload['vendor_raw'],
                'description_raw' => $payload['description_raw'],
                'quantity_raw' => $payload['quantity_raw'],
                'retail_raw' => $payload['retail_raw'],
                'extended_retail_raw' => $payload['extended_retail_raw'],
                'lifecycle_raw' => $payload['lifecycle_raw'],
                'min_model_year_raw' => $payload['min_model_year_raw'],
                'average_model_year_raw' => $payload['average_model_year_raw'],
                'max_model_year_raw' => $payload['max_model_year_raw'],
                'prevalent_model_raw' => $payload['prevalent_model_raw'],
                'applicable_models_raw' => $payload['applicable_models_raw'],
                'length_raw' => $payload['length_raw'],
                'width_raw' => $payload['width_raw'],
                'height_raw' => $payload['height_raw'],
                'cubic_inches_raw' => $payload['cubic_inches_raw'],
                'weight_raw' => $payload['weight_raw'],
                'extended_weight_raw' => $payload['extended_weight_raw'],
                'normalized_payload' => $payload,
                'validation_errors' => $payload['validation_errors'],
            ]);

            if (! empty($payload['validation_errors'])) {
                $stats['invalid_rows']++;
            }

            if (! empty($payload['missing_compatibility'])) {
                $stats['missing_compatibility_rows']++;
            }

            if (isset($seenSourceKeys[$sourceKey])) {
                $stats['duplicate_rows']++;
                $rowRecord->update(['duplicate_of_row_id' => $seenSourceKeys[$sourceKey]]);
                $import->increment('duplicate_rows');

                return;
            }

            $seenSourceKeys[$sourceKey] = $rowRecord->id;

            $automotivePart = AutomotivePart::query()->firstOrNew(['source_key' => $sourceKey]);
            $previousQuantity = (int) ($automotivePart->quantity ?? 0);
            $newQuantity = (int) ($payload['quantity'] ?? 0);

            $data = [
                'source_key' => $sourceKey,
                'item_number' => $payload['item_number'],
                'manufacturer_part_number' => $payload['manufacturer_part_number'],
                'vendor' => $payload['vendor'],
                'vendor_normalized' => $payload['vendor_normalized'],
                'category' => $payload['category'],
                'subcategory' => $payload['subcategory'],
                'description_original' => $payload['description_original'],
                'description_normalized' => $payload['description_normalized'],
                'quantity' => $newQuantity,
                'original_currency' => 'USD',
                'retail_price_original' => $payload['retail_price_original'],
                'min_model_year' => $payload['min_model_year'],
                'average_model_year' => $payload['average_model_year'],
                'max_model_year' => $payload['max_model_year'],
                'prevalent_model' => $payload['prevalent_model'],
                'applicable_models_text' => $payload['applicable_models_text'],
                'length_inches' => $payload['length_inches'],
                'width_inches' => $payload['width_inches'],
                'height_inches' => $payload['height_inches'],
                'cubic_inches' => $payload['cubic_inches'],
                'weight_pounds' => $payload['weight_pounds'],
                'length_cm' => $payload['length_cm'],
                'width_cm' => $payload['width_cm'],
                'height_cm' => $payload['height_cm'],
                'weight_kg' => $payload['weight_kg'],
                'lifecycle' => $payload['lifecycle'],
                'data_status' => $this->resolveDataStatus($payload),
                'missing_fields' => $payload['missing_fields'],
                'last_import_id' => $import->id,
                'last_imported_at' => now(),
            ];

            $automotivePart->fill($data);
            $automotivePart->save();

            $rowRecord->update(['automotive_part_id' => $automotivePart->id]);

            if ($automotivePart->wasRecentlyCreated || $previousQuantity !== $newQuantity) {
                AutomotivePartStockMovement::query()->create([
                    'automotive_part_id' => $automotivePart->id,
                    'automotive_part_import_id' => $import->id,
                    'previous_quantity' => $previousQuantity,
                    'new_quantity' => $newQuantity,
                    'difference' => $newQuantity - $previousQuantity,
                    'reason' => $automotivePart->wasRecentlyCreated ? 'initial_import' : 'import_update',
                    'metadata' => [
                        'source_key' => $sourceKey,
                        'row_number' => $rowRecord->row_number,
                    ],
                ]);
            }

            $stats['imported_rows']++;
            if ($automotivePart->wasRecentlyCreated === false && $previousQuantity !== $newQuantity) {
                $stats['updated_rows']++;
            }
        });

        $import->update([
            'status' => 'completed',
            'total_rows' => $stats['total_rows'],
            'imported_rows' => $stats['imported_rows'],
            'updated_rows' => $stats['updated_rows'],
            'duplicate_rows' => $stats['duplicate_rows'],
            'invalid_rows' => $stats['invalid_rows'],
            'missing_compatibility_rows' => $stats['missing_compatibility_rows'],
            'completed_at' => now(),
            'metadata' => [
                'processed_rows' => $stats['total_rows'],
                'unique_rows' => $stats['imported_rows'],
                'duplicate_rows' => $stats['duplicate_rows'],
            ],
        ]);

        return $stats;
    }

    protected function findDataStartIndex(Collection $rows): int
    {
        foreach ($rows as $index => $row) {
            $joined = strtolower(implode(' ', array_map(static fn ($cell) => (string) $cell, (array) $row)));

            if (str_contains($joined, 'category')
                && str_contains($joined, 'item')
                && str_contains($joined, 'vendor')) {
                return $index + 1;
            }
        }

        return 0;
    }

    protected function normalizeRowValues(mixed $row): array
    {
        if (! is_array($row)) {
            return [];
        }

        return array_values(array_map(static fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value), $row));
    }

    protected function isEmptyRow(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $hasContent = false;

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $hasContent = true;
                break;
            }

            if (is_numeric($value) || $value instanceof \Stringable) {
                $hasContent = true;
                break;
            }
        }

        return ! $hasContent;
    }

    protected function buildNormalizedPayload(array $values): array
    {
        $category = $this->readCell($values, 0);
        $subcategory = $this->readCell($values, 1);
        $itemNumber = $this->readCell($values, 2);
        $manufacturerPartNumber = $this->readCell($values, 3);
        $vendor = $this->readCell($values, 4);
        $description = $this->readCell($values, 5);
        $quantity = $this->readCell($values, 6);
        $retail = $this->readCell($values, 7);
        $extendedRetail = $this->readCell($values, 8);
        $lifecycle = $this->readCell($values, 9);
        $minModelYear = $this->readCell($values, 10);
        $averageModelYear = $this->readCell($values, 11);
        $maxModelYear = $this->readCell($values, 12);
        $prevalentModel = $this->readCell($values, 13);
        $applicableModels = $this->readCell($values, 14);
        $length = $this->readCell($values, 15);
        $width = $this->readCell($values, 16);
        $height = $this->readCell($values, 17);
        $cubicInches = $this->readCell($values, 18);
        $weight = $this->readCell($values, 19);
        $extendedWeight = $this->readCell($values, 20);

        $normalizedItemNumber = $this->normalizer->normalizePartNumber($itemNumber);
        $normalizedManufacturerPartNumber = $this->normalizer->normalizePartNumber($manufacturerPartNumber);
        $normalizedVendor = $this->normalizer->normalizeVendor($vendor);
        $normalizedCategory = $this->normalizer->normalizeText($category);
        $normalizedSubcategory = $this->normalizer->normalizeText($subcategory);
        $normalizedDescription = $this->normalizer->normalizeText($description);

        $parsedQuantity = $this->normalizer->parseInteger($quantity);
        $retailPriceOriginal = $this->normalizer->parseDecimal($retail);
        $lengthInches = $this->normalizer->parseDecimal($length);
        $widthInches = $this->normalizer->parseDecimal($width);
        $heightInches = $this->normalizer->parseDecimal($height);
        $cubicInchesValue = $this->normalizer->parseDecimal($cubicInches);
        $weightPounds = $this->normalizer->parseDecimal($weight);

        $minModelYear = $this->normalizer->parseYear($minModelYear);
        $averageModelYear = $this->normalizer->parseYear($averageModelYear);
        $maxModelYear = $this->normalizer->parseYear($maxModelYear);

        $validationErrors = [];
        if ($normalizedItemNumber === null) {
            $validationErrors[] = 'item_number_missing';
        }
        if ($normalizedManufacturerPartNumber === null) {
            $validationErrors[] = 'manufacturer_part_number_missing';
        }
        if ($normalizedVendor === null) {
            $validationErrors[] = 'vendor_missing';
        }
        if ($normalizedDescription === null) {
            $validationErrors[] = 'description_missing';
        }

        $applicableModelsText = $this->normalizer->normalizeText($applicableModels);
        $missingCompatibility = empty($applicableModelsText) ? ['applicable_models'] : [];

        $payload = [
            'source_key' => $this->normalizer->makeSourceKey($itemNumber, $manufacturerPartNumber, $vendor),
            'category_raw' => $category,
            'subcategory_raw' => $subcategory,
            'item_number_raw' => $itemNumber,
            'manufacturer_part_number_raw' => $manufacturerPartNumber,
            'vendor_raw' => $vendor,
            'description_raw' => $description,
            'quantity_raw' => $quantity,
            'retail_raw' => $retail,
            'extended_retail_raw' => $extendedRetail,
            'lifecycle_raw' => $lifecycle,
            'min_model_year_raw' => $minModelYear,
            'average_model_year_raw' => $averageModelYear,
            'max_model_year_raw' => $maxModelYear,
            'prevalent_model_raw' => $prevalentModel,
            'applicable_models_raw' => $applicableModels,
            'length_raw' => $length,
            'width_raw' => $width,
            'height_raw' => $height,
            'cubic_inches_raw' => $cubicInches,
            'weight_raw' => $weight,
            'extended_weight_raw' => $extendedWeight,
            'item_number' => $normalizedItemNumber,
            'manufacturer_part_number' => $normalizedManufacturerPartNumber,
            'vendor' => $normalizedVendor,
            'vendor_normalized' => strtolower(str_replace(' ', '_', (string) $normalizedVendor)),
            'category' => $normalizedCategory,
            'subcategory' => $normalizedSubcategory,
            'description_original' => $normalizedDescription,
            'description_normalized' => $normalizedDescription,
            'quantity' => $parsedQuantity ?? 0,
            'retail_price_original' => $retailPriceOriginal,
            'min_model_year' => $minModelYear,
            'average_model_year' => $averageModelYear,
            'max_model_year' => $maxModelYear,
            'prevalent_model' => $this->normalizer->normalizeText($prevalentModel),
            'applicable_models_text' => $applicableModelsText,
            'length_inches' => $lengthInches,
            'width_inches' => $widthInches,
            'height_inches' => $heightInches,
            'cubic_inches' => $cubicInchesValue,
            'weight_pounds' => $weightPounds,
            'length_cm' => $this->normalizer->inchesToCm($lengthInches),
            'width_cm' => $this->normalizer->inchesToCm($widthInches),
            'height_cm' => $this->normalizer->inchesToCm($heightInches),
            'weight_kg' => $this->normalizer->poundsToKg($weightPounds),
            'lifecycle' => $this->normalizer->normalizeText($lifecycle),
            'validation_errors' => $validationErrors,
            'missing_fields' => $this->normalizer->buildMissingFields([
                'item_number' => $normalizedItemNumber,
                'manufacturer_part_number' => $normalizedManufacturerPartNumber,
                'vendor' => $normalizedVendor,
                'category' => $normalizedCategory,
                'description_original' => $normalizedDescription,
                'applicable_models_text' => $applicableModelsText,
            ]),
            'missing_compatibility' => $missingCompatibility,
        ];

        return $payload;
    }

    protected function readCell(array $values, int $index): ?string
    {
        return array_key_exists($index, $values)
            ? (is_string($values[$index]) ? $values[$index] : (string) $values[$index])
            : null;
    }

    protected function resolveDataStatus(array $payload): string
    {
        $missingFields = $payload['missing_fields'] ?? [];

        if ($missingFields !== []) {
            return 'incomplete';
        }

        return 'imported';
    }
}
