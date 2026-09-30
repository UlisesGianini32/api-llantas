<?php

namespace App\Services\Pos;

use App\Models\PosSale;
use App\Models\PosShift;

class PosReceiptService
{
    public const WIDTH_58MM = 32;

    public const WIDTH_80MM = 48;

    /**
     * @return array{
     *     text: string,
     *     escpos_base64: string,
     *     width: int,
     *     paper_type: string,
     *     drawer_kick: bool
     * }
     */
    public function formatSaleReceipt(PosSale $sale, string $paperType = '80mm', bool $kickDrawer = true): array
    {
        $sale->loadMissing(['cashier', 'location', 'items']);
        $width = ($paperType === '58mm') ? self::WIDTH_58MM : self::WIDTH_80MM;

        $storeName = config('app.name', 'SALON BARBER SUPPLY');
        $locationName = $sale->location?->name ?? 'Mostrador Principal';
        $cashierName = $sale->cashier?->name ?? 'Cajero';
        $saleDate = $sale->created_at ? $sale->created_at->format('d/m/Y H:i') : date('d/m/Y H:i');

        $lines = [];
        $lines[] = $this->center($storeName, $width);
        $lines[] = $this->center($locationName, $width);
        $lines[] = $this->center('VENTA EN MOSTRADOR', $width);
        $lines[] = str_repeat('-', $width);

        $lines[] = $this->twoColumns('Ticket:', $sale->sale_number, $width);
        $lines[] = $this->twoColumns('Fecha:', $saleDate, $width);
        $lines[] = $this->twoColumns('Cajero:', $cashierName, $width);
        $lines[] = $this->twoColumns('Cliente:', $sale->customer_name ?: 'Público en general', $width);
        $lines[] = $this->twoColumns('Tipo:', $sale->customer_type === 'stylist' ? 'Estilista / Mayorista' : 'Público General', $width);
        $lines[] = str_repeat('-', $width);

        // Header tabla items
        if ($width === self::WIDTH_58MM) {
            $lines[] = 'CANT DESCRIPCION       IMPORTE';
        } else {
            $lines[] = sprintf('%-5s %-28s %12s', 'CANT', 'PRODUCTO', 'IMPORTE');
        }
        $lines[] = str_repeat('-', $width);

        foreach ($sale->items as $item) {
            $qty = (int) $item->quantity;
            $name = (string) $item->product_name;
            $subtotalStr = '$'.number_format((float) $item->subtotal, 2);

            if ($width === self::WIDTH_58MM) {
                // Formato 58mm (32 columnas)
                $firstLine = sprintf('%-3s %-16s %10s', $qty.'x', mb_substr($name, 0, 16), $subtotalStr);
                $lines[] = $firstLine;
                if (mb_strlen($name) > 16) {
                    $lines[] = '    '.mb_substr($name, 16, 28);
                }
            } else {
                // Formato 80mm (48 columnas)
                $firstLine = sprintf('%-5s %-28s %12s', $qty.'x', mb_substr($name, 0, 28), $subtotalStr);
                $lines[] = $firstLine;
                if (mb_strlen($name) > 28) {
                    $lines[] = '      '.mb_substr($name, 28, 42);
                }
            }
        }

        $lines[] = str_repeat('-', $width);
        $lines[] = $this->twoColumns('Subtotal:', '$'.number_format((float) $sale->subtotal, 2), $width);

        if ((float) $sale->discount_amount > 0) {
            $lines[] = $this->twoColumns('Descuento:', '-$'.number_format((float) $sale->discount_amount, 2), $width);
        }

        if ((float) $sale->tax_amount > 0) {
            $lines[] = $this->twoColumns('IVA:', '$'.number_format((float) $sale->tax_amount, 2), $width);
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = $this->twoColumns('TOTAL:', '$'.number_format((float) $sale->total, 2), $width);
        $lines[] = str_repeat('=', $width);

        $methodLabel = match ($sale->payment_method) {
            'cash' => 'Efectivo',
            'card' => 'Tarjeta Débito/Crédito',
            'transfer' => 'Transferencia',
            default => 'Otro / Mixto',
        };

        $lines[] = $this->twoColumns('Forma de pago:', $methodLabel, $width);

        if ($sale->payment_method === 'cash' && $sale->amount_tendered !== null) {
            $lines[] = $this->twoColumns('Efectivo recibido:', '$'.number_format((float) $sale->amount_tendered, 2), $width);
            $lines[] = $this->twoColumns('Cambio entregado:', '$'.number_format((float) $sale->change_due, 2), $width);
        }

        $lines[] = str_repeat('-', $width);
        $lines[] = $this->center('¡Gracias por su preferencia!', $width);
        $lines[] = $this->center('Conserve su ticket para aclaraciones', $width);
        $lines[] = '';

        $plainText = implode("\n", $lines);
        $escpos = $this->buildEscPosBytes($lines, $storeName, $kickDrawer);

        return [
            'text' => $plainText,
            'escpos_base64' => base64_encode($escpos),
            'width' => $width,
            'paper_type' => $paperType,
            'drawer_kick' => $kickDrawer,
        ];
    }

    /**
     * @return array{
     *     text: string,
     *     escpos_base64: string,
     *     width: int,
     *     paper_type: string
     * }
     */
    public function formatShiftCutReceipt(PosShift $shift, string $paperType = '80mm'): array
    {
        $shift->loadMissing(['location', 'cashier', 'closedBy', 'movements.user']);
        $width = ($paperType === '58mm') ? self::WIDTH_58MM : self::WIDTH_80MM;

        $storeName = config('app.name', 'SALON BARBER SUPPLY');
        $locationName = $shift->location?->name ?? 'Mostrador Principal';
        $cashierName = $shift->cashier?->name ?? 'Cajero';
        $closedByName = $shift->closedBy?->name ?? $cashierName;

        $openedDate = $shift->opened_at ? $shift->opened_at->format('d/m/Y H:i') : 'N/A';
        $closedDate = $shift->closed_at ? $shift->closed_at->format('d/m/Y H:i') : date('d/m/Y H:i');

        $lines = [];
        $lines[] = $this->center($storeName, $width);
        $lines[] = $this->center($locationName, $width);
        $lines[] = $this->center('CORTE DE CAJA (TURNO #'.$shift->id.')', $width);
        $lines[] = str_repeat('=', $width);

        $lines[] = $this->twoColumns('Apertura:', $openedDate, $width);
        $lines[] = $this->twoColumns('Cajero inicio:', $cashierName, $width);
        $lines[] = $this->twoColumns('Cierre:', $closedDate, $width);
        $lines[] = $this->twoColumns('Cerrado por:', $closedByName, $width);
        $lines[] = str_repeat('-', $width);

        $lines[] = $this->center('ARQUEO DE EFECTIVO', $width);
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->twoColumns('(+) Fondo de apertura:', '$'.number_format((float) $shift->opening_cash, 2), $width);
        $lines[] = $this->twoColumns('(+) Ventas en efectivo:', '$'.number_format((float) $shift->total_sales_cash, 2), $width);
        $lines[] = $this->twoColumns('(+) Ingresos extra:', '$'.number_format((float) $shift->total_cash_in, 2), $width);
        $lines[] = $this->twoColumns('(-) Retiros / Gastos:', '-$'.number_format((float) $shift->total_cash_out, 2), $width);
        $lines[] = str_repeat('-', $width);

        $expected = (float) ($shift->closing_cash_expected ?? ($shift->opening_cash + $shift->total_sales_cash + $shift->total_cash_in - $shift->total_cash_out));
        $counted = (float) ($shift->closing_cash_counted ?? $expected);
        $diff = (float) ($shift->difference ?? ($counted - $expected));

        $lines[] = $this->twoColumns('EFECTIVO ESPERADO:', '$'.number_format($expected, 2), $width);
        $lines[] = $this->twoColumns('EFECTIVO CONTADO:', '$'.number_format($counted, 2), $width);

        $diffLabel = $diff == 0 ? '$0.00 (CUADRADA)' : ($diff > 0 ? '+$'.number_format($diff, 2).' (SOBRANTE)' : '-$'.number_format(abs($diff), 2).' (FALTANTE)');
        $lines[] = $this->twoColumns('DIFERENCIA:', $diffLabel, $width);
        $lines[] = str_repeat('=', $width);

        $lines[] = $this->center('RESUMEN DE VENTAS TOTALES', $width);
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->twoColumns('Ventas Efectivo:', '$'.number_format((float) $shift->total_sales_cash, 2), $width);
        $lines[] = $this->twoColumns('Ventas Tarjeta:', '$'.number_format((float) $shift->total_sales_card, 2), $width);
        $lines[] = $this->twoColumns('Ventas Transferencia:', '$'.number_format((float) $shift->total_sales_transfer, 2), $width);
        if ((float) $shift->total_sales_other > 0) {
            $lines[] = $this->twoColumns('Otros métodos:', '$'.number_format((float) $shift->total_sales_other, 2), $width);
        }
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->twoColumns('TOTAL VENDIDO:', '$'.number_format((float) $shift->total_sales_amount, 2), $width);
        $lines[] = $this->twoColumns('Tickets emitidos:', (string) $shift->total_sales_count, $width);
        $lines[] = str_repeat('-', $width);

        if ($shift->movements->isNotEmpty()) {
            $lines[] = $this->center('DETALLE DE MOVIMIENTOS', $width);
            foreach ($shift->movements as $m) {
                $typeSign = $m->type === 'IN' ? '(+)' : '(-)';
                $line = sprintf('%s %s: $%s', $typeSign, mb_substr($m->reason, 0, 18), number_format((float) $m->amount, 2));
                $lines[] = $line;
            }
            $lines[] = str_repeat('-', $width);
        }

        if (! empty($shift->notes)) {
            $lines[] = 'NOTAS:';
            $lines[] = mb_substr($shift->notes, 0, $width * 2);
            $lines[] = str_repeat('-', $width);
        }

        $lines[] = '';
        $lines[] = $this->center('_______________________________', $width);
        $lines[] = $this->center('Firma de Cajero / Responsable', $width);
        $lines[] = '';

        $plainText = implode("\n", $lines);
        $escpos = $this->buildEscPosBytes($lines, 'CORTE DE CAJA', false);

        return [
            'text' => $plainText,
            'escpos_base64' => base64_encode($escpos),
            'width' => $width,
            'paper_type' => $paperType,
        ];
    }

    public function getDrawerKickCommand(): string
    {
        // ESC p 0 25 250 (Pulso estándar para cajón conectado a RJ11 de impresora)
        $pulse = "\x1b\x70\x00\x19\xfa";

        return base64_encode($pulse);
    }

    private function buildEscPosBytes(array $lines, string $headerTitle, bool $kickDrawer = false): string
    {
        $buffer = '';

        // Init printer ESC @
        $buffer .= "\x1b@";

        // Drawer kick if required
        if ($kickDrawer) {
            $buffer .= "\x1b\x70\x00\x19\xfa";
        }

        // Center align ESC a 1
        $buffer .= "\x1ba\x01";
        // Double height & width for header title GS ! 0x11
        $buffer .= "\x1d!\x11";
        $buffer .= $headerTitle."\n";
        // Normal text GS ! 0x00
        $buffer .= "\x1d!\x00";

        // Align left ESC a 0
        $buffer .= "\x1ba\x00";

        // Append line by line
        foreach ($lines as $index => $line) {
            if ($index === 0 && trim($line) === trim($headerTitle)) {
                continue; // Ya impreso en doble tamaño
            }
            $buffer .= $line."\n";
        }

        // Feed paper and cut (GS V 66 0 = feed 3 lines & partial cut)
        $buffer .= "\n\n\n\x1dV\x42\x00";

        return $buffer;
    }

    private function center(string $text, int $width): string
    {
        $len = mb_strlen($text);
        if ($len >= $width) {
            return mb_substr($text, 0, $width);
        }
        $padLeft = (int) floor(($width - $len) / 2);

        return str_repeat(' ', $padLeft).$text;
    }

    private function twoColumns(string $left, string $right, int $width): string
    {
        $leftLen = mb_strlen($left);
        $rightLen = mb_strlen($right);

        if ($leftLen + $rightLen >= $width) {
            $maxLeft = $width - $rightLen - 1;
            if ($maxLeft > 0) {
                $left = mb_substr($left, 0, $maxLeft);
                $leftLen = mb_strlen($left);
            }
        }

        $spaces = max(1, $width - $leftLen - $rightLen);

        return $left.str_repeat(' ', $spaces).$right;
    }
}
