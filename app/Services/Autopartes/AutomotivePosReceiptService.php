<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotivePosSale;
use App\Models\AutomotivePosShift;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutomotivePosReceiptService
{
    public const WIDTH_58MM = 32;

    public const WIDTH_80MM = 48;

    public const LETTER_WIDTH_MM = 216;

    public const LETTER_HEIGHT_MM = 279;

    public const FORMAT_THERMAL = 'thermal';

    public const FORMAT_LETTER = 'letter';

    public const FORMAT_BOTH = 'both';

    public const SUPPORTED_FORMATS = [
        self::FORMAT_THERMAL,
        self::FORMAT_LETTER,
        self::FORMAT_BOTH,
    ];

    public const SUPPORTED_PAPER_TYPES = [
        '58mm',
        '80mm',
    ];

    public function __construct(
        private readonly BranchCatalogAccess $access,
    ) {}

    /**
     * Build the canonical historical snapshot from the confirmed sale and its item/payment snapshots.
     * Never queries current commercial prices or live catalog attributes so historical vouchers remain immutable.
     *
     * @return array<string, mixed>
     */
    public function buildHistoricalSnapshot(AutomotivePosSale $sale, bool $isReprint = false): array
    {
        $sale->loadMissing(['branch', 'cashRegister', 'shift', 'location', 'items', 'payments']);

        $branchCode = mb_strtoupper((string) ($sale->branch?->code ?? 'HMO'));
        $branchName = mb_strtoupper((string) ($sale->branch?->name ?? 'HERMOSILLO'));
        $registerCode = (string) ($sale->cashRegister?->code ?? 'HMO-CAJA-01');
        $registerName = (string) ($sale->cashRegister?->name ?? 'CAJA MOSTRADOR HERMOSILLO 01');
        $locationCode = (string) ($sale->location?->code ?? 'HMO-REC-01');
        $shiftFolio = (string) ($sale->shift?->folio ?? '#'.$sale->automotive_pos_shift_id);
        $soldAt = $sale->created_at ? $sale->created_at->format('d/m/Y H:i:s') : date('d/m/Y H:i:s');

        $showCashier = (bool) config('autopartes.pos.display_cashier_name_on_ticket', true);
        $cashierName = $sale->cashier_name_snapshot ?: AutopartesUserDirectory::nameFor((int) $sale->cashier_id);
        $cashierLabel = $showCashier
            ? ($cashierName ?? 'Cajero #'.$sale->cashier_id)
            : ('ID #'.$sale->cashier_id);

        $sellerCode = (string) $sale->seller_code_snapshot;
        $sellerName = (string) $sale->seller_name_snapshot;
        $sellerLabel = trim($sellerCode.' - '.$sellerName);

        $methodLabel = match ($sale->payment_method) {
            AutomotivePosSale::PAYMENT_CASH => 'Efectivo',
            AutomotivePosSale::PAYMENT_CARD => 'Tarjeta (Registro Manual)',
            AutomotivePosSale::PAYMENT_TRANSFER => 'Transferencia Bancaria',
            AutomotivePosSale::PAYMENT_MIXED => 'Pago Mixto',
            default => strtoupper((string) $sale->payment_method),
        };

        $items = [];
        foreach ($sale->items as $item) {
            $qty = (int) $item->quantity;
            $unitPrice = number_format((float) $item->unit_price_mxn, 2, '.', '');
            $grossLine = number_format($qty * (float) $item->unit_price_mxn, 2, '.', '');
            $wDisc = number_format((float) $item->wholesale_discount_amount, 2, '.', '');
            $mDisc = number_format((float) $item->manual_discount_amount, 2, '.', '');
            $discTotal = number_format((float) $item->discount_amount, 2, '.', '');
            $taxAmt = number_format((float) $item->tax_amount, 2, '.', '');
            $lineTotal = number_format((float) $item->subtotal, 2, '.', '');

            $items[] = [
                'id' => (int) $item->id,
                'automotive_part_id' => (int) $item->automotive_part_id,
                'part_code' => (string) $item->part_code_snapshot,
                'item_number' => (string) ($item->item_number_snapshot ?? ''),
                'manufacturer_part_number' => (string) ($item->mfg_part_number_snapshot ?? ''),
                'description' => (string) $item->description_snapshot,
                'category' => (string) ($item->category_snapshot ?? ''),
                'quantity' => $qty,
                'unit_price_mxn' => $unitPrice,
                'line_gross_amount' => $grossLine,
                'wholesale_discount_amount' => $wDisc,
                'manual_discount_amount' => $mDisc,
                'discount_amount' => $discTotal,
                'tax_amount' => $taxAmt,
                'subtotal' => $lineTotal,
            ];
        }

        $payments = [];
        foreach ($sale->payments as $payment) {
            $pLabel = match ($payment->method) {
                AutomotivePosSale::PAYMENT_CASH => 'Efectivo',
                AutomotivePosSale::PAYMENT_CARD => 'Tarjeta',
                AutomotivePosSale::PAYMENT_TRANSFER => 'Transferencia',
                default => (string) $payment->method,
            };
            $payments[] = [
                'method' => (string) $payment->method,
                'method_label' => $pLabel,
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'amount_tendered' => $payment->amount_tendered !== null ? number_format((float) $payment->amount_tendered, 2, '.', '') : null,
                'change_due' => $payment->change_due !== null ? number_format((float) $payment->change_due, 2, '.', '') : null,
                'reference' => $payment->reference,
            ];
        }

        $reprintCount = (int) $sale->reprint_count;

        return [
            'sale_id' => (int) $sale->id,
            'folio' => (string) $sale->folio,
            'sold_at' => $soldAt,
            'branch_code' => $branchCode,
            'branch_name' => $branchName,
            'branch_header' => 'AUTOPARTES '.$branchName,
            'register_code' => $registerCode,
            'register_name' => $registerName,
            'location_code' => $locationCode,
            'shift_folio' => $shiftFolio,
            'cashier_id' => (int) $sale->cashier_id,
            'cashier_label' => $cashierLabel,
            'seller_id' => (int) $sale->seller_id,
            'seller_code' => $sellerCode,
            'seller_name' => $sellerName,
            'seller_label' => $sellerLabel,
            'customer_name' => $sale->customer_name ?: 'Público en general',
            'subtotal' => number_format((float) $sale->subtotal, 2, '.', ''),
            'wholesale_discount_amount' => number_format((float) $sale->wholesale_discount_amount, 2, '.', ''),
            'manual_discount_amount' => number_format((float) $sale->manual_discount_amount, 2, '.', ''),
            'discount_amount' => number_format((float) $sale->discount_amount, 2, '.', ''),
            'tax_rate' => number_format((float) $sale->tax_rate, 4, '.', ''),
            'tax_percentage' => round(((float) $sale->tax_rate) * 100, 2),
            'tax_amount' => number_format((float) $sale->tax_amount, 2, '.', ''),
            'total' => number_format((float) $sale->total, 2, '.', ''),
            'payment_method' => (string) $sale->payment_method,
            'payment_method_label' => $methodLabel,
            'amount_tendered' => $sale->amount_tendered !== null ? number_format((float) $sale->amount_tendered, 2, '.', '') : '0.00',
            'cash_tendered' => (static function () use ($sale): string {
                if ((string) $sale->payment_method === 'cash') {
                    return $sale->amount_tendered !== null ? number_format((float) $sale->amount_tendered, 2, '.', '') : '0.00';
                }
                if ((string) $sale->payment_method === 'mixed') {
                    $cashSum = (float) $sale->payments
                        ->where('method', 'cash')
                        ->sum(fn ($p): float => $p->amount_tendered !== null ? (float) $p->amount_tendered : (float) $p->amount);

                    return number_format($cashSum, 2, '.', '');
                }

                return '0.00';
            })(),
            'change_due' => in_array((string) $sale->payment_method, ['cash', 'mixed'], true) && $sale->change_due !== null
                ? number_format((float) $sale->change_due, 2, '.', '')
                : '0.00',
            'payment_reference' => $sale->payment_reference,
            'items' => $items,
            'payments' => $payments,
            'is_reprint' => $isReprint || $reprintCount > 0,
            'reprint_count' => $reprintCount,
            'cfdi_disclaimer' => 'ESTE COMPROBANTE NO ES FACTURA FISCAL CFDI',
        ];
    }

    /**
     * Format printable thermal ticket (58mm or 80mm) AND letter voucher bundle for an Automotive POS sale.
     *
     * @return array<string, mixed>
     */
    public function formatSaleReceipt(
        AutomotivePosSale $sale,
        string $paperType = '80mm',
        bool $kickDrawer = true,
        bool $isReprint = false,
        string $selectedFormat = self::FORMAT_BOTH,
    ): array {
        $paperType = in_array($paperType, self::SUPPORTED_PAPER_TYPES, true) ? $paperType : '80mm';
        $selectedFormat = in_array($selectedFormat, self::SUPPORTED_FORMATS, true) ? $selectedFormat : self::FORMAT_BOTH;

        $snapshot = $this->buildHistoricalSnapshot($sale, $isReprint);
        $thermal = $this->formatThermalFromSnapshot($snapshot, $paperType, $kickDrawer, $isReprint);
        $letter = $this->formatLetterFromSnapshot($snapshot, $isReprint);

        return [
            'selected_format' => $selectedFormat,
            'text' => $thermal['text'],
            'html' => $selectedFormat === self::FORMAT_LETTER ? $letter['html'] : $thermal['html'],
            'escpos_base64' => $thermal['escpos_base64'],
            'width' => $thermal['width'],
            'paper_type' => $thermal['paper_type'],
            'paper_width_mm' => $thermal['paper_width_mm'],
            'drawer_kick' => $kickDrawer,
            'is_reprint' => $snapshot['is_reprint'],
            'reprint_count' => $snapshot['reprint_count'],
            'cfdi_disclaimer' => $snapshot['cfdi_disclaimer'],
            'data' => $snapshot,
            'thermal' => $thermal,
            'letter' => $letter,
        ];
    }

    /**
     * Format printable Letter-size (216 x 279 mm) voucher for conventional printers or Save-as-PDF.
     *
     * @return array<string, mixed>
     */
    public function formatLetterReceipt(AutomotivePosSale $sale, bool $isReprint = false): array
    {
        $snapshot = $this->buildHistoricalSnapshot($sale, $isReprint);

        return $this->formatLetterFromSnapshot($snapshot, $isReprint);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function formatThermalFromSnapshot(
        array $snapshot,
        string $paperType = '80mm',
        bool $kickDrawer = true,
        bool $isReprint = false,
    ): array {
        $is58 = ($paperType === '58mm');
        $width = $is58 ? self::WIDTH_58MM : self::WIDTH_80MM;
        $paperWidthMm = $is58 ? 58 : 80;

        $branchName = $snapshot['branch_header'];
        $branchCode = 'SUCURSAL '.$snapshot['branch_code'];
        $registerLabel = $snapshot['register_code'].' - '.$snapshot['location_code'];

        $lines = [];
        $lines[] = $this->center($branchName, $width);
        $lines[] = $this->center($branchCode, $width);
        $lines[] = $this->center($registerLabel, $width);
        if ($isReprint || (int) $snapshot['reprint_count'] > 0) {
            $lines[] = $this->center('*** REIMPRESION DE COMPROBANTE #'.max(1, (int) $snapshot['reprint_count']).' ***', $width);
        }
        $lines[] = str_repeat('-', $width);

        $lines[] = $this->twoColumns('Folio:', $snapshot['folio'], $width);
        $lines[] = $this->twoColumns('Fecha:', $snapshot['sold_at'], $width);
        $lines[] = $this->twoColumns('Turno:', $snapshot['shift_folio'], $width);
        $lines[] = $this->twoColumns('Cajero:', $snapshot['cashier_label'], $width);
        $lines[] = $this->twoColumns('Vendedor:', $snapshot['seller_label'], $width);
        if (! empty($snapshot['customer_name'])) {
            $lines[] = $this->twoColumns('Cliente:', (string) $snapshot['customer_name'], $width);
        }
        $lines[] = str_repeat('-', $width);

        if ($width === self::WIDTH_58MM) {
            $lines[] = 'CANT DESCRIPCION       IMPORTE';
        } else {
            $lines[] = sprintf('%-5s %-28s %12s', 'CANT', 'AUTOPARTE / PRECIO UNIT.', 'IMPORTE');
        }
        $lines[] = str_repeat('-', $width);

        foreach ($snapshot['items'] as $item) {
            $qty = (int) $item['quantity'];
            $codeTag = $item['item_number'] ?: ($item['manufacturer_part_number'] ?: $item['part_code']);
            $title = trim($codeTag.' '.$item['description']);
            $unitStr = '$'.number_format((float) $item['unit_price_mxn'], 2);
            $lineSubtotalStr = '$'.number_format((float) $item['subtotal'], 2);

            if ($width === self::WIDTH_58MM) {
                $lines[] = sprintf('%-3s %-16s %10s', $qty.'x', mb_substr($title, 0, 16), $lineSubtotalStr);
                $lines[] = '    P.U.: '.$unitStr;
                if ((float) $item['discount_amount'] > 0) {
                    $lines[] = '    Desc: -$'.number_format((float) $item['discount_amount'], 2);
                }
            } else {
                $lines[] = sprintf('%-5s %-28s %12s', $qty.'x', mb_substr($title, 0, 28), $lineSubtotalStr);
                if (mb_strlen($title) > 28) {
                    $lines[] = '      '.mb_substr($title, 28, 40);
                }
                $detailLine = '      P.U. Autorizado: '.$unitStr.' MXN';
                if ((float) $item['discount_amount'] > 0) {
                    $detailLine .= ' (Desc -$'.number_format((float) $item['discount_amount'], 2).')';
                }
                $lines[] = $detailLine;
            }
        }

        $lines[] = str_repeat('-', $width);
        $lines[] = $this->twoColumns('Subtotal Bruto:', '$'.number_format((float) $snapshot['subtotal'], 2).' MXN', $width);

        if ((float) $snapshot['wholesale_discount_amount'] > 0) {
            $lines[] = $this->twoColumns('Desc. Mayoreo:', '-$'.number_format((float) $snapshot['wholesale_discount_amount'], 2).' MXN', $width);
        }
        if ((float) $snapshot['manual_discount_amount'] > 0) {
            $lines[] = $this->twoColumns('Desc. Manual:', '-$'.number_format((float) $snapshot['manual_discount_amount'], 2).' MXN', $width);
        }
        if ((float) $snapshot['discount_amount'] > 0) {
            $lines[] = $this->twoColumns('Descuento Total:', '-$'.number_format((float) $snapshot['discount_amount'], 2).' MXN', $width);
        }
        if ((float) $snapshot['tax_amount'] > 0) {
            $taxPct = $snapshot['tax_percentage'];
            $lines[] = $this->twoColumns("Impuestos ({$taxPct}%):", '$'.number_format((float) $snapshot['tax_amount'], 2).' MXN', $width);
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = $this->twoColumns('TOTAL MXN:', '$'.number_format((float) $snapshot['total'], 2).' MXN', $width);
        $lines[] = str_repeat('=', $width);

        $lines[] = $this->twoColumns('Metodo de pago:', $snapshot['payment_method_label'], $width);

        foreach ($snapshot['payments'] as $payment) {
            $refSuffix = $payment['reference'] ? " [{$payment['reference']}]" : '';
            $lines[] = $this->twoColumns('  '.$payment['method_label'].$refSuffix.':', '$'.number_format((float) $payment['amount'], 2), $width);
        }

        $cashTendered = (float) ($snapshot['cash_tendered']
            ?? (in_array((string) ($snapshot['payment_method'] ?? ''), ['cash', 'mixed'], true)
                ? ($snapshot['amount_tendered'] ?? 0)
                : 0));
        if ($cashTendered > 0) {
            $lines[] = $this->twoColumns('Efectivo recibido:', '$'.number_format($cashTendered, 2).' MXN', $width);
            $lines[] = $this->twoColumns('Cambio entregado:', '$'.number_format((float) $snapshot['change_due'], 2).' MXN', $width);
        }

        $lines[] = str_repeat('-', $width);
        $lines[] = $this->center('ESTE COMPROBANTE NO ES FACTURA FISCAL CFDI', $width);
        $lines[] = $this->center('COMPROBANTE INTERNO DE VENTA MOSTRADOR', $width);
        $lines[] = $this->center('Conserve este ticket para cualquier aclaracion', $width);
        $lines[] = '';

        $plainText = implode("\n", $lines);
        $escpos = $this->buildEscPosBytes($lines, $branchName, $kickDrawer);
        $thermalHtml = $this->buildThermalHtml($plainText, $paperWidthMm, $snapshot['folio']);

        return [
            'format' => self::FORMAT_THERMAL,
            'text' => $plainText,
            'html' => $thermalHtml,
            'escpos_base64' => base64_encode($escpos),
            'width' => $width,
            'paper_type' => $paperType,
            'paper_width_mm' => $paperWidthMm,
            'drawer_kick' => $kickDrawer,
            'is_reprint' => $snapshot['is_reprint'],
            'reprint_count' => $snapshot['reprint_count'],
            'cfdi_disclaimer' => $snapshot['cfdi_disclaimer'],
            'data' => $snapshot,
        ];
    }

    /**
     * Build a clean, professional Letter-size (216 x 279 mm) HTML voucher using the exact historical sale snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function formatLetterFromSnapshot(array $snapshot, bool $isReprint = false): array
    {
        $reprintBanner = '';
        if ($isReprint || (int) $snapshot['reprint_count'] > 0) {
            $copyNum = max(1, (int) $snapshot['reprint_count']);
            $reprintBanner = '<div class="reprint-badge">REIMPRESIÓN AUTORIZADA DE COMPROBANTE — COPIA #'.$copyNum.'</div>';
        }

        $rowsHtml = '';
        foreach ($snapshot['items'] as $idx => $item) {
            $num = $idx + 1;
            $code = htmlspecialchars($item['item_number'] ?: $item['part_code'], ENT_QUOTES, 'UTF-8');
            $mfg = htmlspecialchars($item['manufacturer_part_number'] ?: '—', ENT_QUOTES, 'UTF-8');
            $desc = htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8');
            $cat = htmlspecialchars($item['category'] ?: 'GENERAL', ENT_QUOTES, 'UTF-8');
            $qty = (int) $item['quantity'];
            $unit = '$'.number_format((float) $item['unit_price_mxn'], 2).' MXN';
            $wDisc = (float) $item['wholesale_discount_amount'] > 0
                ? '-$'.number_format((float) $item['wholesale_discount_amount'], 2)
                : '$0.00';
            $mDisc = (float) $item['manual_discount_amount'] > 0
                ? '-$'.number_format((float) $item['manual_discount_amount'], 2)
                : '$0.00';
            $dTotal = (float) $item['discount_amount'] > 0
                ? '-$'.number_format((float) $item['discount_amount'], 2)
                : '$0.00';
            $net = '$'.number_format((float) $item['subtotal'], 2).' MXN';

            $rowsHtml .= "<tr>
                <td style=\"text-align:center;\">{$num}</td>
                <td><strong>{$code}</strong><br><small>MFG: {$mfg}</small></td>
                <td>{$desc}<br><small>Categoría: {$cat}</small></td>
                <td style=\"text-align:center;\">{$qty}</td>
                <td style=\"text-align:right;\">{$unit}</td>
                <td style=\"text-align:right;\">{$wDisc}</td>
                <td style=\"text-align:right;\">{$mDisc}</td>
                <td style=\"text-align:right;\">{$dTotal}</td>
                <td style=\"text-align:right;font-weight:700;\">{$net}</td>
            </tr>";
        }

        $paymentsHtml = '';
        foreach ($snapshot['payments'] as $payment) {
            $label = htmlspecialchars($payment['method_label'], ENT_QUOTES, 'UTF-8');
            $ref = $payment['reference'] ? ' (Ref: '.htmlspecialchars((string) $payment['reference'], ENT_QUOTES, 'UTF-8').')' : '';
            $amt = '$'.number_format((float) $payment['amount'], 2).' MXN';
            $paymentsHtml .= "<div><span>{$label}{$ref}:</span> <strong>{$amt}</strong></div>";
        }

        $cashSectionHtml = '';
        $cashTendered = (float) ($snapshot['cash_tendered']
            ?? (in_array((string) ($snapshot['payment_method'] ?? ''), ['cash', 'mixed'], true)
                ? ($snapshot['amount_tendered'] ?? 0)
                : 0));
        if ($cashTendered > 0) {
            $tenderedStr = '$'.number_format($cashTendered, 2).' MXN';
            $changeStr = '$'.number_format((float) $snapshot['change_due'], 2).' MXN';
            $cashSectionHtml = "
                <div><span>Efectivo recibido:</span> <strong>{$tenderedStr}</strong></div>
                <div><span>Cambio entregado:</span> <strong>{$changeStr}</strong></div>
            ";
        }

        $folioSafe = htmlspecialchars($snapshot['folio'], ENT_QUOTES, 'UTF-8');
        $branchHeaderSafe = htmlspecialchars($snapshot['branch_header'], ENT_QUOTES, 'UTF-8');
        $branchCodeSafe = htmlspecialchars($snapshot['branch_code'], ENT_QUOTES, 'UTF-8');
        $branchNameSafe = htmlspecialchars($snapshot['branch_name'], ENT_QUOTES, 'UTF-8');
        $registerSafe = htmlspecialchars($snapshot['register_code'].' — '.$snapshot['register_name'], ENT_QUOTES, 'UTF-8');
        $locationSafe = htmlspecialchars($snapshot['location_code'], ENT_QUOTES, 'UTF-8');
        $shiftSafe = htmlspecialchars($snapshot['shift_folio'], ENT_QUOTES, 'UTF-8');
        $soldAtSafe = htmlspecialchars($snapshot['sold_at'], ENT_QUOTES, 'UTF-8');
        $sellerSafe = htmlspecialchars($snapshot['seller_label'], ENT_QUOTES, 'UTF-8');
        $cashierSafe = htmlspecialchars($snapshot['cashier_label'], ENT_QUOTES, 'UTF-8');
        $customerSafe = htmlspecialchars((string) $snapshot['customer_name'], ENT_QUOTES, 'UTF-8');
        $methodSafe = htmlspecialchars($snapshot['payment_method_label'], ENT_QUOTES, 'UTF-8');

        $subtotalStr = '$'.number_format((float) $snapshot['subtotal'], 2).' MXN';
        $wDiscTotalStr = '-$'.number_format((float) $snapshot['wholesale_discount_amount'], 2).' MXN';
        $mDiscTotalStr = '-$'.number_format((float) $snapshot['manual_discount_amount'], 2).' MXN';
        $discTotalStr = '-$'.number_format((float) $snapshot['discount_amount'], 2).' MXN';
        $taxStr = '$'.number_format((float) $snapshot['tax_amount'], 2).' MXN ('.$snapshot['tax_percentage'].'%)';
        $totalStr = '$'.number_format((float) $snapshot['total'], 2).' MXN';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante Tamaño Carta {$folioSafe}</title>
    <style>
        @page {
            size: 216mm 279mm;
            margin: 12mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }
        .letter-sheet {
            width: 216mm;
            min-height: 279mm;
            box-sizing: border-box;
            padding: 10mm;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .reprint-badge {
            background: #fef3c7;
            border: 1px solid #d97706;
            color: #92400e;
            font-weight: 700;
            text-align: center;
            padding: 6px;
            margin-bottom: 12px;
            font-size: 12px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
            font-size: 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 10px 12px;
        }
        table.items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 18px;
        }
        table.items-table th {
            background: #1f2937;
            color: #ffffff;
            padding: 7px 6px;
            text-align: left;
            border: 1px solid #1f2937;
        }
        table.items-table td {
            padding: 7px 6px;
            border: 1px solid #d1d5db;
            vertical-align: top;
        }
        .totals-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            font-size: 12px;
        }
        .box {
            border: 1px solid #d1d5db;
            padding: 10px 12px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
        }
        .totals-final {
            display: flex;
            justify-content: space-between;
            padding: 8px 0 4px 0;
            margin-top: 6px;
            border-top: 2px solid #111827;
            font-size: 15px;
            font-weight: 700;
        }
        .footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #9ca3af;
            text-align: center;
            font-size: 11px;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <div class="letter-sheet" data-format="letter" data-dimensions="216x279mm">
        {$reprintBanner}
        <div class="header">
            <div>
                <h1 style="margin:0;font-size:20px;">{$branchHeaderSafe}</h1>
                <div style="font-size:12px;margin-top:4px;">Sucursal: <strong>{$branchCodeSafe} — {$branchNameSafe}</strong></div>
                <div style="font-size:12px;">Caja: {$registerSafe} | Ubicación: {$locationSafe}</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:11px;color:#4b5563;">COMPROBANTE INTERNO DE VENTA (HOJA CARTA 216 × 279 mm)</div>
                <div style="font-size:18px;font-weight:700;margin-top:2px;">Folio: {$folioSafe}</div>
                <div style="font-size:12px;">Fecha: {$soldAtSafe}</div>
                <div style="font-size:12px;">Turno: {$shiftSafe}</div>
            </div>
        </div>

        <div class="meta-grid">
            <div>
                <div><strong>Vendedor que atendió:</strong> {$sellerSafe}</div>
                <div><strong>Cajero en sesión:</strong> {$cashierSafe}</div>
            </div>
            <div>
                <div><strong>Cliente:</strong> {$customerSafe}</div>
                <div><strong>Método de pago:</strong> {$methodSafe}</div>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:28px;text-align:center;">#</th>
                    <th style="width:120px;">Item # / MFG Part #</th>
                    <th>Descripción de Autoparte</th>
                    <th style="width:45px;text-align:center;">Cant.</th>
                    <th style="width:95px;text-align:right;">P. Unitario</th>
                    <th style="width:80px;text-align:right;">Desc. Mayoreo</th>
                    <th style="width:80px;text-align:right;">Desc. Manual</th>
                    <th style="width:80px;text-align:right;">Desc. Total</th>
                    <th style="width:105px;text-align:right;">Importe Neto</th>
                </tr>
            </thead>
            <tbody>
                {$rowsHtml}
            </tbody>
        </table>

        <div class="totals-grid">
            <div class="box">
                <div style="font-weight:700;margin-bottom:6px;">DESGLOSE DE PAGO</div>
                <div><span>Método principal:</span> <strong>{$methodSafe}</strong></div>
                {$paymentsHtml}
                {$cashSectionHtml}
            </div>
            <div class="box">
                <div class="totals-row"><span>Subtotal Bruto:</span> <strong>{$subtotalStr}</strong></div>
                <div class="totals-row"><span>Descuento por Mayoreo:</span> <strong>{$wDiscTotalStr}</strong></div>
                <div class="totals-row"><span>Descuento Manual Autorizado:</span> <strong>{$mDiscTotalStr}</strong></div>
                <div class="totals-row"><span>Descuento Total:</span> <strong>{$discTotalStr}</strong></div>
                <div class="totals-row"><span>Impuestos Aplicables:</span> <strong>{$taxStr}</strong></div>
                <div class="totals-final"><span>TOTAL MXN:</span> <span>{$totalStr}</span></div>
            </div>
        </div>

        <div class="footer">
            <strong>ESTE COMPROBANTE NO ES FACTURA FISCAL CFDI</strong><br>
            Documento interno de control de venta en mostrador de Sucursal {$branchCodeSafe} ({$branchNameSafe}).
        </div>
    </div>
</body>
</html>
HTML;

        return [
            'format' => self::FORMAT_LETTER,
            'paper_size' => 'letter',
            'dimensions_mm' => '216x279',
            'width_mm' => self::LETTER_WIDTH_MM,
            'height_mm' => self::LETTER_HEIGHT_MM,
            'html' => $html,
            'is_reprint' => $snapshot['is_reprint'],
            'reprint_count' => $snapshot['reprint_count'],
            'cfdi_disclaimer' => $snapshot['cfdi_disclaimer'],
            'data' => $snapshot,
        ];
    }

    private function buildThermalHtml(string $plainText, int $widthMm, string $folio): string
    {
        $escapedText = htmlspecialchars($plainText, ENT_QUOTES, 'UTF-8');
        $escapedFolio = htmlspecialchars($folio, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket Térmico {$widthMm}mm {$escapedFolio}</title>
    <style>
        @page {
            size: {$widthMm}mm auto;
            margin: 2mm;
        }
        body {
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            line-height: 1.25;
            margin: 0;
            padding: 0;
            color: #000;
            background: #fff;
        }
        pre.thermal-ticket {
            width: {$widthMm}mm;
            box-sizing: border-box;
            margin: 0 auto;
            padding: 2mm;
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>
<body>
    <pre class="thermal-ticket" data-format="thermal" data-paper-width="{$widthMm}mm">{$escapedText}</pre>
</body>
</html>
HTML;
    }

    /**
     * Authorized reprint of a sale receipt (thermal, letter, or both), recording audit metadata without duplicating the sale.
     *
     * @return array<string, mixed>
     */
    public function reprintSaleReceipt(
        AutomotiveBranch $branch,
        AutomotivePosSale $sale,
        User $actor,
        string $paperType = '80mm',
        string $format = self::FORMAT_BOTH,
    ): array {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::SALES_VIEW)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::SALES_VIEW);
        }

        if ((int) $sale->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'sale' => 'La venta no pertenece a esta sucursal.',
            ]);
        }

        AutopartesModel::transaction(function () use ($sale, $actor): void {
            AutomotivePosSale::query()
                ->whereKey($sale->getKey())
                ->update([
                    'reprint_count' => DB::connection($sale->getConnectionName())->raw('COALESCE(reprint_count, 0) + 1'),
                    'last_reprinted_at' => now(),
                    'last_reprinted_by' => $actor->getKey(),
                ]);
        });

        $sale->refresh();

        return $this->formatSaleReceipt(
            $sale->fresh(['branch', 'cashRegister', 'shift', 'location', 'items', 'payments']),
            $paperType,
            false,
            true,
            $format,
        );
    }

    /**
     * Format thermal receipt for a shift closing cut.
     *
     * @return array<string, mixed>
     */
    public function formatShiftCutReceipt(AutomotivePosShift $shift, string $paperType = '80mm'): array
    {
        $shift->loadMissing(['branch', 'cashRegister', 'location', 'movements']);
        $users = AutopartesUserDirectory::mapByIds([$shift->cashier_id, $shift->closed_by]);
        $cashierName = $users->get((int) $shift->cashier_id)?->name ?? 'Cajero';
        $closedByName = $users->get((int) $shift->closed_by)?->name ?? $cashierName;

        $width = ($paperType === '58mm') ? self::WIDTH_58MM : self::WIDTH_80MM;

        $branchName = 'AUTOPARTES '.mb_strtoupper((string) ($shift->branch?->name ?? 'HERMOSILLO'));
        $registerLabel = ($shift->cashRegister?->code ?? 'CAJA').' - '.($shift->location?->code ?? 'HMO-REC-01');
        $openedDate = $shift->opened_at ? $shift->opened_at->format('d/m/Y H:i') : 'N/A';
        $closedDate = $shift->closed_at ? $shift->closed_at->format('d/m/Y H:i') : date('d/m/Y H:i');

        $lines = [];
        $lines[] = $this->center($branchName, $width);
        $lines[] = $this->center($registerLabel, $width);
        $lines[] = $this->center('CORTE DE CAJA '.$shift->folio, $width);
        $lines[] = str_repeat('=', $width);

        $lines[] = $this->twoColumns('Apertura:', $openedDate, $width);
        $lines[] = $this->twoColumns('Cajero:', $cashierName, $width);
        $lines[] = $this->twoColumns('Cierre:', $closedDate, $width);
        $lines[] = $this->twoColumns('Cerrado por:', $closedByName, $width);
        $lines[] = str_repeat('-', $width);

        $lines[] = $this->twoColumns('(+) Fondo inicial:', '$'.number_format((float) $shift->opening_cash, 2), $width);
        $lines[] = $this->twoColumns('(+) Ventas efectivo:', '$'.number_format((float) $shift->total_sales_cash, 2), $width);
        $lines[] = $this->twoColumns('(+) Entradas efectivo:', '$'.number_format((float) $shift->total_cash_in, 2), $width);
        $lines[] = $this->twoColumns('(-) Salidas efectivo:', '-$'.number_format((float) $shift->total_cash_out, 2), $width);
        $lines[] = $this->twoColumns('(-) Devoluciones efec.:', '-$'.number_format((float) $shift->total_refunds_cash, 2), $width);
        $lines[] = str_repeat('-', $width);

        $expected = (float) ($shift->closing_cash_expected ?? 0);
        $counted = (float) ($shift->closing_cash_counted ?? 0);
        $diff = (float) ($shift->difference ?? 0);

        $lines[] = $this->twoColumns('EFECTIVO ESPERADO:', '$'.number_format($expected, 2), $width);
        $lines[] = $this->twoColumns('EFECTIVO CONTADO:', '$'.number_format($counted, 2), $width);
        $lines[] = $this->twoColumns('DIFERENCIA:', '$'.number_format($diff, 2), $width);
        $lines[] = str_repeat('=', $width);

        $lines[] = $this->twoColumns('Ventas Tarjeta:', '$'.number_format((float) $shift->total_sales_card, 2), $width);
        $lines[] = $this->twoColumns('Ventas Transferencia:', '$'.number_format((float) $shift->total_sales_transfer, 2), $width);
        $lines[] = $this->twoColumns('TOTAL VENTAS:', '$'.number_format((float) $shift->total_sales_amount, 2), $width);
        $lines[] = $this->twoColumns('Operaciones:', (string) $shift->total_sales_count, $width);
        $lines[] = str_repeat('-', $width);

        $plainText = implode("\n", $lines);
        $escpos = $this->buildEscPosBytes($lines, 'CORTE DE CAJA', false);

        return [
            'text' => $plainText,
            'escpos_base64' => base64_encode($escpos),
            'width' => $width,
            'paper_type' => $paperType,
        ];
    }

    private function buildEscPosBytes(array $lines, string $headerTitle, bool $kickDrawer = false): string
    {
        $buffer = "\x1b@";
        if ($kickDrawer) {
            $buffer .= "\x1b\x70\x00\x19\xfa";
        }
        $buffer .= "\x1ba\x01\x1d!\x11".$headerTitle."\n\x1d!\x00\x1ba\x00";
        foreach ($lines as $index => $line) {
            if ($index === 0 && trim($line) === trim($headerTitle)) {
                continue;
            }
            $buffer .= $line."\n";
        }
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
