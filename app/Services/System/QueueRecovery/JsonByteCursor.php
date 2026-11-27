<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

/**
 * Lightweight buffered byte cursor for streaming JSON manifest files in O(1) memory.
 *
 * @internal
 */
final class JsonByteCursor
{
    private string $buffer = '';

    private int $bufferOffset = 0;

    private int $bufferLength = 0;

    private int $streamPos = 0;

    /** @param resource $fh */
    public function __construct(
        private $fh,
        private readonly int $chunkBytes = 65536,
        int $startOffset = 0,
        private readonly ?int $endOffset = null,
    ) {
        fseek($this->fh, $startOffset);
        $this->streamPos = $startOffset;
    }

    public function position(): int
    {
        return $this->streamPos - ($this->bufferLength - $this->bufferOffset);
    }

    public function peekByte(): ?string
    {
        if ($this->bufferOffset >= $this->bufferLength && ! $this->refill()) {
            return null;
        }

        return $this->buffer[$this->bufferOffset];
    }

    public function consumeByte(): ?string
    {
        if ($this->bufferOffset >= $this->bufferLength && ! $this->refill()) {
            return null;
        }

        return $this->buffer[$this->bufferOffset++];
    }

    public function skipWhitespace(): void
    {
        while (($ch = $this->peekByte()) !== null) {
            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n") {
                $this->bufferOffset++;
            } else {
                break;
            }
        }
    }

    public function readJsonStringLiteral(): string
    {
        $first = $this->consumeByte();
        if ($first !== '"') {
            throw new QueueRecoveryException('Se esperaba cadena JSON delimitada por comillas.', 'manifest_invalid');
        }

        $out = '"';
        $escaped = false;

        while (($ch = $this->consumeByte()) !== null) {
            $out .= $ch;
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($ch === '\\') {
                $escaped = true;

                continue;
            }
            if ($ch === '"') {
                return $out;
            }
        }

        throw new QueueRecoveryException('Cadena JSON sin cerrar en manifiesto.', 'manifest_invalid');
    }

    /**
     * Skips forward until the matching closing `]` of the current JSON array is consumed.
     */
    public function skipRestOfArray(): void
    {
        $depth = 1;
        $inString = false;
        $escaped = false;

        while (true) {
            if ($this->bufferOffset >= $this->bufferLength && ! $this->refill()) {
                throw new QueueRecoveryException('Arreglo entries sin cerrar en manifiesto JSON.', 'manifest_invalid');
            }

            $buf = $this->buffer;
            $len = $this->bufferLength;
            $i = $this->bufferOffset;

            while ($i < $len) {
                $ch = $buf[$i++];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($ch === '\\') {
                        $escaped = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($ch === '"') {
                    $inString = true;
                } elseif ($ch === '[') {
                    $depth++;
                } elseif ($ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $this->bufferOffset = $i;

                        return;
                    }
                }
            }

            $this->bufferOffset = $i;
        }
    }

    /**
     * Reads one complete JSON value (object, array, string, number, bool, null) as raw bytes.
     */
    public function readJsonValueRaw(): string
    {
        $this->skipWhitespace();
        $ch = $this->peekByte();
        if ($ch === null) {
            throw new QueueRecoveryException('Valor JSON inesperado (EOF) en manifiesto.', 'manifest_invalid');
        }

        if ($ch === '"') {
            return $this->readJsonStringLiteral();
        }

        if ($ch === '{' || $ch === '[') {
            $open = $this->consumeByte();
            $out = (string) $open;
            $depth = 1;
            $inString = false;
            $escaped = false;

            while ($depth > 0) {
                if ($this->bufferOffset >= $this->bufferLength && ! $this->refill()) {
                    throw new QueueRecoveryException('Objeto o arreglo JSON sin cerrar en manifiesto.', 'manifest_invalid');
                }

                $buf = $this->buffer;
                $len = $this->bufferLength;
                $start = $this->bufferOffset;
                $i = $start;

                while ($i < $len && $depth > 0) {
                    $c = $buf[$i++];
                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($c === '\\') {
                            $escaped = true;
                        } elseif ($c === '"') {
                            $inString = false;
                        }

                        continue;
                    }

                    if ($c === '"') {
                        $inString = true;
                    } elseif ($c === '{' || $c === '[') {
                        $depth++;
                    } elseif ($c === '}' || $c === ']') {
                        $depth--;
                    }
                }

                $out .= substr($buf, $start, $i - $start);
                $this->bufferOffset = $i;
            }

            return $out;
        }

        $out = '';
        while (($c = $this->peekByte()) !== null) {
            if ($c === ',' || $c === '}' || $c === ']' || $c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                break;
            }
            $out .= (string) $this->consumeByte();
        }

        if ($out === '') {
            throw new QueueRecoveryException('Valor JSON primitivo vacío en manifiesto.', 'manifest_invalid');
        }

        return $out;
    }

    private function refill(): bool
    {
        $maxToRead = $this->chunkBytes;
        if ($this->endOffset !== null) {
            $remaining = $this->endOffset - $this->streamPos;
            if ($remaining <= 0) {
                return false;
            }
            $maxToRead = min($maxToRead, $remaining);
        }

        $chunk = fread($this->fh, $maxToRead);
        if (! is_string($chunk) || $chunk === '') {
            return false;
        }

        $this->buffer = $chunk;
        $this->bufferOffset = 0;
        $this->bufferLength = strlen($chunk);
        $this->streamPos += $this->bufferLength;

        return true;
    }
}
