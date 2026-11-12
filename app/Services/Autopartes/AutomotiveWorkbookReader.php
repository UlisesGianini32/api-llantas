<?php

namespace App\Services\Autopartes;

use App\Support\AutomotiveSourceSchema;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

final class AutomotiveWorkbookReader
{
    public const VERSION = 'source-v1';

    public function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > config('autopartes.max_bytes')) {
            $this->invalid('El archivo no existe o supera el tamaño permitido.');
        }
        try {
            $type = IOFactory::identify($path, ['Xls', 'Xlsx']);
            if ($type === 'Xlsx') {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true) {
                    $this->invalid('El XLSX no es válido.');
                }
                $unpacked = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $unpacked += $zip->statIndex($i)['size'];
                }
                $entries = $zip->numFiles;
                $zip->close();
                if ($unpacked > 128 * 1024 * 1024 || $entries > 2000) {
                    $this->invalid('El contenido descomprimido excede los límites de lectura.');
                }
            }
            $reader = IOFactory::createReader($type);
            $reader->setAllowExternalImages(false);
            $info = $reader->listWorksheetInfo($path);
            if (count($info) > 5 || array_sum(array_column($info, 'totalRows')) > config('autopartes.max_rows') + count($info)) {
                $this->invalid('El libro supera el límite de hojas o filas.');
            }
            foreach ($info as $sheetInfo) {
                if ($sheetInfo['totalColumns'] !== 21) {
                    $this->invalid('Cada hoja debe tener exactamente las 21 columnas A–U.');
                }
            }
            $limit = ini_get('memory_limit');
            $unit = strtolower(substr($limit, -1));
            $bytes = (int) $limit * match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
            if ($bytes > 0 && memory_get_usage(true) + array_sum(array_column($info, 'totalRows')) * 21 * 4096 > $bytes * 0.7) {
                $this->invalid('Memoria insuficiente para una vista previa íntegra. Configure PHP con al menos 768 MB para este catálogo.');
            }
            $book = $reader->setReadDataOnly(false)->load($path);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->invalid('No se pudo leer un libro XLS/XLSX válido.');
        }

        try {
            $sheets = [];
            $semantic = [];
            $items = [];
            $fullRows = [];
            $stats = ['rows' => 0, 'columns' => 21, 'null_cells_j_o' => 0, 'leading_zero_parts' => 0,
                'max_compatibility_length' => 0, 'qty_sum' => 0, 'blank_rows' => 0];
            foreach ($book->getAllSheets() as $index => $sheet) {
                $header = $this->cells($sheet, 1);
                if (array_column($header, 'value') !== AutomotiveSourceSchema::HEADERS || $sheet->getMergeCells() !== []) {
                    $this->invalid('Los encabezados u orden no corresponden al catálogo; no se admiten celdas combinadas.');
                }
                $rows = [];
                foreach (range(2, $sheet->getHighestDataRow()) as $number) {
                    if ($sheet->getHighestDataRow() < 2) {
                        break;
                    }
                    $cells = $this->cells($sheet, $number);
                    AutomotiveSourceSchema::validateCells($cells);
                    $values = array_column($cells, 'value');
                    $item = (string) ($values[2] ?? '');
                    if ($item !== '') {
                        $items[$item] = ($items[$item] ?? 0) + 1;
                    }
                    $stats['rows']++;
                    $stats['null_cells_j_o'] += count(array_filter(array_slice($values, 9, 6), fn ($v) => $v === null));
                    $stats['leading_zero_parts'] += $cells[3]['type'] === 's' && preg_match('/^0/', (string) $values[3]) ? 1 : 0;
                    $stats['max_compatibility_length'] = max($stats['max_compatibility_length'], mb_strlen((string) $values[14]));
                    $stats['qty_sum'] += is_numeric($values[6]) ? (float) $values[6] : 0;
                    $stats['blank_rows'] += count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0 ? 1 : 0;
                    $key = hash('sha256', json_encode(array_map(fn ($i) => [$cells[$i]['type'],
                        $cells[$i]['type'] === 'n' ? self::numericKey($values[$i]) : $values[$i]], [2, 3, 4]), JSON_THROW_ON_ERROR));
                    $semanticHash = hash('sha256', json_encode(self::semanticCells($cells), JSON_THROW_ON_ERROR));
                    $fullRows[$semanticHash] = ($fullRows[$semanticHash] ?? 0) + 1;
                    $rows[] = ['row_number' => $number, 'cells' => $cells, 'semantic_hash' => $semanticHash, 'identity_key' => $key];
                }
                $sheets[] = ['sheet_index' => $index, 'name' => $sheet->getTitle(), 'header_cells' => $header, 'rows' => $rows];
                $semantic[] = [$index, $sheet->getTitle(), self::semanticCells($header), array_column($rows, 'semantic_hash')];
            }
            if ($stats['rows'] === 0) {
                $this->invalid('El archivo no contiene filas de datos.');
            }
            $duplicates = array_filter($items, fn ($count) => $count > 1);
            $stats += ['distinct_items' => count($items), 'duplicate_item_values' => count($duplicates),
                'duplicate_item_rows' => array_sum($duplicates), 'identical_row_groups' => count(array_filter($fullRows, fn ($n) => $n > 1)),
                'data_cells' => $stats['rows'] * 21];

            return ['sheets' => $sheets, 'stats' => $stats, 'sha256' => hash_file('sha256', $path),
                'semantic_sha256' => hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR))];
        } finally {
            $book->disconnectWorksheets();
            unset($sheet, $book, $reader);
            gc_collect_cycles();
        }
    }

    private function cells(Worksheet $sheet, int $row): array
    {
        $cells = [];
        foreach (range('A', 'U') as $column) {
            $coordinate = $column.$row;
            $present = $sheet->cellExists($coordinate);
            $cell = $sheet->getCell($coordinate);
            $value = $cell->getValue();
            $type = $cell->getDataType();
            $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
            if ($value instanceof RichText) {
                $value = $value->getPlainText();
            }
            $cached = $type === 'f' ? $cell->getOldCalculatedValue() : null;
            // Never evaluate formulas, external references or links from an uploaded book.
            $displayValue = $type === 'f' ? $cached : $value;
            $cells[] = ['column' => $column, 'present' => $present, 'type' => $type,
                'value' => $type === 'n' && $value !== null ? self::number($value) : $value,
                'format' => $format, 'display' => $displayValue === null ? '' : (string) NumberFormat::toFormattedString($displayValue, $format),
                'cached' => is_int($cached) || is_float($cached) ? self::number($cached) : $cached];
        }

        return $cells;
    }

    public static function semanticCells(array $cells): array
    {
        return array_map(fn ($cell) => [
            $cell['column'], $cell['type'],
            $cell['type'] === 'n' ? self::numericKey($cell['value']) : $cell['value'],
            $cell['format'] ?? null, $cell['display'] ?? null, $cell['cached'] ?? null,
        ], $cells);
    }

    private static function number(int|float|string $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function numericKey(string $number): string
    {
        preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $number, $parts);
        $digits = ltrim(($parts[2] ?? '').($parts[3] ?? ''), '0');
        $exponent = (int) ($parts[4] ?? 0) - strlen($parts[3] ?? '');
        $trimmed = rtrim($digits, '0');

        return $digits === '' ? '0' : (($parts[1] ?? '') === '-' ? '-' : '').$trimmed.'e'.($exponent + strlen($digits) - strlen($trimmed));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
