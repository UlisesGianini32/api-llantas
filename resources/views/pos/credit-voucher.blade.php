<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Venta a Crédito - {{ $sale->sale_number }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: #0f172a;
            padding: 24px;
        }

        .no-print-bar {
            max-width: 800px;
            margin: 0 auto 16px auto;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-print {
            background-color: #4f46e5;
            color: #ffffff;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-print:hover {
            background-color: #4338ca;
        }

        .btn-close {
            background: #f8fafc;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        .invoice-card {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 40px;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }

        .company-title {
            font-size: 24px;
            font-weight: 800;
            color: #1e1b4b;
            letter-spacing: -0.5px;
        }

        .company-subtitle {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .invoice-meta {
            text-align: right;
        }

        .invoice-badge {
            display: inline-block;
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .invoice-folio {
            font-size: 18px;
            font-weight: 800;
            font-family: monospace;
            color: #0f172a;
        }

        .client-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 24px;
        }

        .client-block h4 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .client-block p {
            font-size: 13px;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .client-block p strong {
            color: #0f172a;
        }

        .credit-terms-pill {
            display: inline-flex;
            align-items: center;
            background: #fef3c7;
            color: #92400e;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .items-table th {
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e1;
        }

        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
            color: #1e293b;
        }

        .items-table td.qty {
            font-weight: 700;
            width: 60px;
        }

        .items-table td.sku {
            font-family: monospace;
            color: #64748b;
            font-size: 12px;
            width: 110px;
        }

        .items-table td.price, .items-table td.total {
            text-align: right;
            font-family: monospace;
            font-weight: 600;
            width: 100px;
        }

        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 28px;
        }

        .totals-box {
            width: 320px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #475569;
            margin-bottom: 8px;
        }

        .totals-row.main {
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
        }

        .totals-row.balance {
            border-top: 2px solid #ef4444;
            padding-top: 8px;
            font-size: 16px;
            font-weight: 800;
            color: #b91c1c;
        }

        .legal-box {
            background: #fafafa;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 14px;
            font-size: 11px;
            line-height: 1.5;
            color: #64748b;
            text-align: justify;
            margin-bottom: 36px;
        }

        .signatures-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 30px;
            padding-top: 10px;
        }

        .sig-block {
            text-align: center;
        }

        .sig-line {
            border-top: 1px solid #0f172a;
            margin-bottom: 8px;
        }

        .sig-name {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }

        .sig-role {
            font-size: 11px;
            color: #64748b;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }

            .no-print-bar {
                display: none !important;
            }

            .invoice-card {
                box-shadow: none;
                border: none;
                padding: 0;
                max-width: 100%;
            }

            @page {
                size: letter;
                margin: 15mm;
            }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <div>
            <strong>Comprobante de Crédito #{{ $sale->sale_number }}</strong>
            <span style="font-size: 12px; color: #64748b; margin-left: 8px;">Estilista: {{ $sale->customer_name }}</span>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn-print">🖨️ Imprimir / Guardar PDF</button>
            <button onclick="window.close()" class="btn-close">Cerrar</button>
        </div>
    </div>

    <div class="invoice-card">
        <!-- HEADER -->
        <div class="header-row">
            <div>
                <div class="company-title">SALON & BARBER SUPPLY</div>
                <div class="company-subtitle">Venta Especializada para Estilistas y Barberías</div>
                <div class="company-subtitle">{{ $sale->location->name ?? 'Sucursal Principal' }} • Folio Caja: {{ $sale->cashier->name ?? 'Caja' }}</div>
            </div>
            <div class="invoice-meta">
                <div class="invoice-badge">
                    @if($sale->payment_method === 'credit')
                        NOTA DE REMISIÓN A CRÉDITO
                    @else
                        COMPROBANTE DE VENTA
                    @endif
                </div>
                <div class="invoice-folio">{{ $sale->sale_number }}</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Fecha: {{ $sale->created_at->format('d/m/Y H:i') }}</div>
            </div>
        </div>

        <!-- CLIENT & CREDIT DETAILS -->
        <div class="client-grid">
            <div class="client-block">
                <h4>Datos del Cliente / Estilista</h4>
                <p><strong>Nombre:</strong> {{ $sale->customer_name }}</p>
                @if($sale->customer && $sale->customer->business_name)
                    <p><strong>Estética / Salón:</strong> {{ $sale->customer->business_name }}</p>
                @endif
                @if($sale->customer_phone)
                    <p><strong>Tel / WhatsApp:</strong> {{ $sale->customer_phone }}</p>
                @endif
                @if($sale->customer && $sale->customer->address)
                    <p><strong>Dirección:</strong> {{ $sale->customer->address }}</p>
                @endif
            </div>

            <div class="client-block">
                <h4>Condiciones de Pago</h4>
                <p><strong>Tipo:</strong> {{ $sale->customer_type === 'stylist' ? 'Estilista / Precio Mayoreo' : 'Público General' }}</p>
                @if($sale->payment_method === 'credit')
                    <p><strong>Forma de pago:</strong> Crédito en cuenta</p>
                    <p>
                        <strong>Plazo otorgado:</strong>
                        <span class="credit-terms-pill">{{ $sale->credit_days ?? 15 }} Días</span>
                    </p>
                    <p>
                        <strong>Fecha Límite / Vencimiento:</strong>
                        <span style="font-weight: 800; color: #b91c1c; font-size: 14px;">
                            {{ $sale->credit_due_date ? $sale->credit_due_date->format('d/m/Y') : 'Inmediato' }}
                        </span>
                    </p>
                @else
                    <p><strong>Forma de pago:</strong> {{ ucfirst($sale->payment_method) }}</p>
                    <p><strong>Estado:</strong> Liquidado</p>
                @endif
            </div>
        </div>

        <!-- ITEMS TABLE -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="qty">Cant.</th>
                    <th class="sku">SKU</th>
                    <th>Descripción del Producto</th>
                    <th class="price">Precio U.</th>
                    <th class="price">Desc.</th>
                    <th class="total">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->items as $item)
                    <tr>
                        <td class="qty">{{ $item->quantity }}</td>
                        <td class="sku">{{ $item->sku }}</td>
                        <td>
                            <strong>{{ $item->product_name }}</strong>
                            @if($item->product_type === 'KIT')
                                <span style="font-size: 10px; background: #f3e8ff; color: #7e22ce; padding: 1px 4px; border-radius: 4px; margin-left: 4px;">KIT</span>
                            @endif
                        </td>
                        <td class="price">${{ number_format($item->unit_price, 2) }}</td>
                        <td class="price">${{ number_format($item->discount, 2) }}</td>
                        <td class="total">${{ number_format($item->subtotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- TOTALS -->
        <div class="totals-section">
            <div class="totals-box">
                <div class="totals-row">
                    <span>Subtotal:</span>
                    <span style="font-family: monospace;">${{ number_format($sale->subtotal, 2) }}</span>
                </div>
                @if($sale->discount_amount > 0)
                    <div class="totals-row" style="color: #16a34a;">
                        <span>Descuento aplicado:</span>
                        <span style="font-family: monospace;">-${{ number_format($sale->discount_amount, 2) }}</span>
                    </div>
                @endif
                @if($sale->tax_amount > 0)
                    <div class="totals-row">
                        <span>IVA (16%):</span>
                        <span style="font-family: monospace;">${{ number_format($sale->tax_amount, 2) }}</span>
                    </div>
                @endif
                <div class="totals-row main">
                    <span>TOTAL DE VENTA:</span>
                    <span style="font-family: monospace; font-size: 16px;">${{ number_format($sale->total, 2) }}</span>
                </div>

                @if($sale->payment_method === 'credit')
                    <div class="totals-row" style="margin-top: 6px;">
                        <span>Anticipo / Enganche:</span>
                        <span style="font-family: monospace;">${{ number_format($sale->amount_paid, 2) }}</span>
                    </div>
                    <div class="totals-row balance">
                        <span>SALDO PENDIENTE:</span>
                        <span style="font-family: monospace; font-size: 18px;">${{ number_format($sale->balance_due, 2) }}</span>
                    </div>
                @endif
            </div>
        </div>

        @if($sale->payment_method === 'credit' && $sale->balance_due > 0)
            <!-- PAGARÉ LEGAL CLAUSE -->
            <div class="legal-box">
                <strong>PAGARÉ MERCANTIL:</strong> Por este pagaré mercantil me(nos) obligo(amos) a pagar incondicionalmente a la orden de
                <strong>SALON & BARBER SUPPLY</strong>, en la plaza de esta ciudad el día
                <strong>{{ $sale->credit_due_date ? $sale->credit_due_date->format('d/m/Y') : '' }}</strong>, la cantidad de
                <strong>${{ number_format($sale->balance_due, 2) }} M.N.</strong>, valor recibido a mi entera satisfacción en mercancía descrita en el presente documento.
                En caso de no ser pagado a su vencimiento, causará intereses moratorios a razón del 4% mensual hasta su liquidación total.
            </div>
        @endif

        @if($sale->notes)
            <div style="font-size: 12px; color: #475569; margin-bottom: 24px;">
                <strong>Observaciones:</strong> {{ $sale->notes }}
            </div>
        @endif

        <!-- SIGNATURES -->
        <div class="signatures-grid">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-name">{{ $sale->cashier->name ?? 'Vendedor' }}</div>
                <div class="sig-role">Entregó / Responsable Mostrador</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-name">{{ $sale->customer_name }}</div>
                <div class="sig-role">Firma de Conformidad / Deudor</div>
            </div>
        </div>
    </div>

</body>
</html>
