<?php

namespace App\Services\MercadoLibre;

use Illuminate\Validation\ValidationException;

final class MeliLabelParser
{
    public const MAX_LABELS = 500;

    public const TYPE_PRODUCT = 'product';

    public const TYPE_PACKAGE = 'package';

    /** @return list<string> */
    public function parse(string $content): array
    {
        return array_map($this->normalizeSingleCopy(...), $this->extractLabels($content));
    }

    /** @return list<string> */
    public function extractLabels(string $content): array
    {
        // Preserve the original encoding and bytes; only discard a UTF-8 BOM outside ZPL.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        // Linear scanning avoids PCRE backtracking limits on large graphic labels.
        $labels = [];
        $offset = 0;
        while (($start = strpos($content, '^XA', $offset)) !== false) {
            $end = strpos($content, '^XZ', $start + 3);
            $nested = strpos($content, '^XA', $start + 3);
            if (trim(substr($content, $offset, $start - $offset)) !== '' || $end === false || ($nested !== false && $nested < $end)) {
                $this->invalidContent();
            }
            $labels[] = substr($content, $start, $end + 3 - $start);
            if (count($labels) > self::MAX_LABELS) {
                throw ValidationException::withMessages([
                    'file' => 'El archivo supera el máximo permitido de 500 etiquetas.',
                ]);
            }
            $offset = $end + 3;
        }

        if ($labels === [] || trim(substr($content, $offset)) !== '') {
            $this->invalidContent();
        }

        return $labels;
    }

    public function detectType(string $filename, string $content): ?string
    {
        $normalizedFilename = strtolower(str_replace(['_', ' '], '-', $filename));

        if (preg_match('/etiquetas?-de-productos?/', $normalizedFilename)) {
            return self::TYPE_PRODUCT;
        }

        if (preg_match('/etiquetas?-de-(?:bultos?|cajas?|gu[ií]as?)/u', $normalizedFilename)) {
            return self::TYPE_PACKAGE;
        }

        $normalizedContent = strtolower($content);
        $hasPackageMarker = preg_match('/(?:etiquetas?[- _]de[- _])?(?:bultos?|cajas?|gu[ií]as?)/u', $normalizedContent) === 1;
        $hasProductMarker = preg_match('/etiquetas?[- _]de[- _]productos?|\^FD[^\^\r\n]*(?:producto|sku)/iu', $content) === 1;

        if ($hasProductMarker xor $hasPackageMarker) {
            return $hasProductMarker ? self::TYPE_PRODUCT : self::TYPE_PACKAGE;
        }

        return null;
    }

    public function extractShipmentId(string $filename): ?string
    {
        return preg_match('/env[ií]o[-_\s]+([0-9]+)/iu', $filename, $matches) === 1
            ? $matches[1]
            : null;
    }

    public function normalizeSingleCopy(string $label): string
    {
        $replacements = 0;
        $normalized = preg_replace_callback('/\^PQ([^\^~\r\n]*)/', function (array $match): string {
            if (! preg_match('/^\d*(?:,\d*)?(?:,\d*)?(?:,[YN]?)?(?:,[YN]?)?[ \t]*$/D', $match[1])) {
                throw ValidationException::withMessages([
                    'file' => 'Una etiqueta contiene un comando ^PQ no compatible. No se puede garantizar una sola copia.',
                ]);
            }

            return '^PQ1,0,1,Y';
        }, $label, -1, $replacements);

        if ($normalized === null) {
            $this->invalidContent();
        }

        if ($replacements === 0) {
            return substr($normalized, 0, -3).'^PQ1,0,1,Y^XZ';
        }

        return $normalized;
    }

    public function calculateHash(string $content): string
    {
        return hash('sha256', $content);
    }

    private function invalidContent(): never
    {
        throw ValidationException::withMessages([
            'file' => 'El archivo no contiene etiquetas ZPL válidas o incluye bloques incompletos/comandos fuera de las etiquetas.',
        ]);
    }
}
