<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class MeliFullRecommendationExportService
{
    /**
     * Genera el Excel oficial de Mercado Libre para crear un envío de stock FULL.
     *
     * Se escribe una sola fila por inventario físico recomendado. Cuando varias
     * publicaciones o variantes están conectadas al mismo inventory_id, se usa
     * una sola de ellas para no duplicar la cantidad enviada.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     */
    public function create(
        Collection $groups,
        int $salesDays,
        int $coverageDays,
    ): string {
        $templatePath = storage_path('app/templates/Envio-de-stock-Full.xlsx');

        if (! is_file($templatePath)) {
            throw new RuntimeException(
                'No se encontró la plantilla de Mercado Libre en storage/app/templates/Envio-de-stock-Full.xlsx.',
            );
        }

        $spreadsheet = IOFactory::load($templatePath);
        [$sheet, $headerRow] = $this->findProductsSheet($spreadsheet->getAllSheets());

        // La plantilla oficial tiene una fila de ayuda debajo del encabezado.
        // Los productos comienzan dos filas después del encabezado.
        $startRow = $headerRow + 2;

        $recommendedGroups = $groups
            ->filter(fn (array $group): bool => (int) ($group['recommended_quantity'] ?? 0) > 0)
            ->values();

        if ($recommendedGroups->isEmpty()) {
            throw new RuntimeException('No existen productos con cantidad recomendada para exportar.');
        }

        $row = $startRow;

        foreach ($recommendedGroups as $group) {
            $rows = collect($group['rows'] ?? []);
            $inventoryId = strtoupper(trim((string) ($group['inventory_id'] ?? '')));
            $userProductId = strtoupper(trim((string) ($group['user_product_id'] ?? '')));
            $quantity = max(0, (int) ($group['recommended_quantity'] ?? 0));

            $representative = $rows
                ->first(function (array $candidate) use ($inventoryId): bool {
                    if ($inventoryId === '') {
                        return false;
                    }

                    return strtoupper(trim((string) ($candidate['inventory_id'] ?? ''))) === $inventoryId;
                }) ?? $rows->first();

            $representative = is_array($representative) ? $representative : [];

            $sku = trim((string) ($representative['sku'] ?? ''));

            if ($sku === '') {
                $candidateWithSku = $rows->first(
                    fn (array $candidate): bool => filled($candidate['sku'] ?? null),
                );

                $sku = is_array($candidateWithSku)
                    ? trim((string) ($candidateWithSku['sku'] ?? ''))
                    : '';
            }

            if ($inventoryId === '') {
                $inventoryId = strtoupper(trim((string) ($representative['inventory_id'] ?? '')));
            }

            if ($userProductId === '') {
                $userProductId = strtoupper(trim((string) ($representative['user_product_id'] ?? '')));
            }

            $mlm = strtoupper(trim((string) ($representative['mlm'] ?? '')));
            $publicationNumber = preg_replace('/\D+/', '', $mlm) ?: '';

            if ($row > $startRow) {
                $sheet->duplicateStyle(
                    $sheet->getStyle("A{$startRow}:F{$startRow}"),
                    "A{$row}:F{$row}",
                );

                $sheet->getRowDimension($row)
                    ->setRowHeight($sheet->getRowDimension($startRow)->getRowHeight());
            }

            $sheet->setCellValueExplicit("A{$row}", $sku, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", $inventoryId, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $publicationNumber, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", $userProductId, DataType::TYPE_STRING);
            $sheet->setCellValue("F{$row}", $quantity);

            $row++;
        }

        $lastRow = $row - 1;

        $sheet->getStyle("A{$startRow}:E{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_TEXT);

        $sheet->getStyle("F{$startRow}:F{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('0');

        $sheet->setSelectedCell("A{$startRow}");
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        $spreadsheet->getProperties()
            ->setTitle("Envío FULL recomendado - {$salesDays} días")
            ->setSubject("Cobertura objetivo de {$coverageDays} días")
            ->setDescription(
                'Plantilla de Mercado Libre completada automáticamente con las recomendaciones de inventario FULL.',
            );

        $directory = storage_path('app/tmp/meli-full-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('No fue posible crear el directorio temporal para el Excel.');
        }

        $path = $directory.'/'.Str::uuid().'.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Encuentra la hoja de carga aunque Mercado Libre cambie espacios, acentos
     * o el nombre visible. Primero compara el nombre y después reconoce los
     * encabezados oficiales dentro de las primeras filas.
     *
     * @param  array<int, Worksheet>  $sheets
     * @return array{0: Worksheet, 1: int}
     */
    private function findProductsSheet(array $sheets): array
    {
        $expectedTitle = $this->normalizeText('Selección de productos');

        foreach ($sheets as $sheet) {
            if ($this->normalizeText($sheet->getTitle()) === $expectedTitle) {
                return [$sheet, $this->findHeaderRow($sheet) ?? 4];
            }
        }

        foreach ($sheets as $sheet) {
            $headerRow = $this->findHeaderRow($sheet);

            if ($headerRow !== null) {
                return [$sheet, $headerRow];
            }
        }

        // Último respaldo: la plantilla normalmente tiene "Ayuda" como primera
        // hoja y la hoja de productos como segunda. Se evita depender del espacio
        // inicial que Mercado Libre agrega al nombre interno de la pestaña.
        foreach ($sheets as $sheet) {
            if ($this->normalizeText($sheet->getTitle()) !== 'ayuda') {
                return [$sheet, 4];
            }
        }

        $availableSheets = collect($sheets)
            ->map(fn (Worksheet $sheet): string => '"'.$sheet->getTitle().'"')
            ->implode(', ');

        throw new RuntimeException(
            'No fue posible identificar la hoja de productos de la plantilla. '.
            'Hojas encontradas: '.$availableSheets,
        );
    }

    private function findHeaderRow(Worksheet $sheet): ?int
    {
        for ($row = 1; $row <= 15; $row++) {
            $values = $sheet->rangeToArray("A{$row}:F{$row}", null, true, true, false)[0] ?? [];
            $text = $this->normalizeText(implode(' ', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $values,
            )));

            $requiredLabels = [
                'sku',
                'codigo universal',
                'codigo ml',
                'numero de publicacion',
                'numero de producto',
                'cantidad de unidades',
            ];

            $matches = collect($requiredLabels)
                ->filter(fn (string $label): bool => str_contains($text, $label))
                ->count();

            if ($matches >= 4) {
                return $row;
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        // Convierte espacios no separables, caracteres invisibles y saltos en
        // espacios normales antes de eliminar acentos y signos.
        $value = preg_replace(
            '/[\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]+/u',
            ' ',
            $value,
        ) ?? $value;

        $value = Str::ascii(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
