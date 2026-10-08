<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orden de Compra - {{ $order->order_number }}</title>
    <link rel="icon" type="image/png" href="/logo-beauty-shop.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        body {
            background-color: #f8fafc;
            color: #0f172a;
            padding: 24px;
        }

        .no-print-bar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e2e8f0;
        }

        .btn-print {
            background-color: #059669;
            color: #ffffff;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-print:hover {
            background-color: #047857;
        }

        .btn-close {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-close:hover {
            background: #f1f5f9;
        }

        .pdf-container {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 40px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 24px;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo-img {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            background: #000;
        }

        .company-name {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.5px;
        }

        .company-subtitle {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            margin-top: 2px;
        }

        .po-title-block {
            text-align: right;
        }

        .po-badge {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #059669;
            background: #ecfdf5;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 4px;
        }

        .po-title {
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
        }

        .po-number {
            font-size: 15px;
            font-weight: 700;
            color: #4f46e5;
            margin-top: 2px;
        }

        .po-date {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 24px;
            margin-bottom: 24px;
        }

        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
        }

        .info-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 6px;
        }

        .info-value-main {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
        }

        .info-sub {
            font-size: 12px;
            color: #475569;
            margin-top: 4px;
        }

        table.items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 13px;
        }

        table.items-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
        }

        table.items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }

        table.items-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        .sku-cell {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            color: #0f172a;
        }

        .qty-cell {
            text-align: center;
            font-weight: 800;
            font-size: 14px;
            color: #0f172a;
        }

        .footer-summary {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
            gap: 20px;
        }

        .notes-block {
            flex: 1;
        }

        .notes-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }

        .notes-content {
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }

        .totals-box {
            width: 260px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 18px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #475569;
            margin-bottom: 6px;
        }

        .total-row.highlight {
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            margin-top: 8px;
            font-weight: 800;
            font-size: 15px;
            color: #0f172a;
        }

        .signatures {
            margin-top: 48px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding-top: 20px;
        }

        .signature-line {
            border-top: 1px solid #94a3b8;
            text-align: center;
            padding-top: 8px;
        }

        .signature-title {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }

        .signature-sub {
            font-size: 10px;
            color: #64748b;
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .no-print-bar {
                display: none !important;
            }
            .pdf-container {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <div style="font-size: 13px; font-weight: 600; color: #475569;">
            Vista Oficial de Impresión / PDF: <span style="color: #0f172a; font-weight: 800;">{{ $order->order_number }}</span>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn-print">
                🖨️ Imprimir / Guardar como PDF
            </button>
            <a href="javascript:window.close()" class="btn-close">Cerrar</a>
        </div>
    </div>

    <div id="printable-order" class="pdf-container">
        <!-- Header -->
        <div class="header">
            <div class="brand-block">
                <img src="/logo-beauty-shop.png" alt="T.O. THE BEAUTY SHOP" class="logo-img">
                <div>
                    <h1 class="company-name">T.O. THE BEAUTY SHOP</h1>
                    <p class="company-subtitle">Salon & Barber Supply (SBS) • Hermosillo, Sonora, México</p>
                </div>
            </div>

            <div class="po-title-block">
                <span class="po-badge">Documento Oficial</span>
                <h2 class="po-title">ORDEN DE COMPRA</h2>
                <div class="po-number">{{ $order->order_number }}</div>
                <div class="po-date">Fecha de emisión: {{ $order->ordered_at ? \Carbon\Carbon::parse($order->ordered_at)->format('d/m/Y') : \Carbon\Carbon::parse($order->created_at)->format('d/m/Y') }}</div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">Proveedor</div>
                <div class="info-value-main">{{ $order->supplier_name }}</div>
                @if($order->brand)
                    <div class="info-sub"><strong>Línea / Marca:</strong> {{ $order->brand }}</div>
                @endif
                @if($order->supplier_quote_reference)
                    <div class="info-sub"><strong>Referencia / Folio:</strong> {{ $order->supplier_quote_reference }}</div>
                @endif
                @if(!empty($supplier?->phone))
                    <div class="info-sub"><strong>Teléfono:</strong> {{ $supplier->phone }}</div>
                @endif
            </div>

            <div class="info-card">
                <div class="info-label">Lugar de Entrega / Almacén</div>
                <div class="info-value-main">{{ $order->location ? $order->location->name : 'Almacén General SBS' }}</div>
                @if($order->location?->code)
                    <div class="info-sub"><strong>Código Almacén:</strong> {{ $order->location->code }}</div>
                @endif
                @if($order->buyer)
                    <div class="info-sub"><strong>Comprador SBS:</strong> {{ $order->buyer->name }}</div>
                @endif
            </div>
        </div>

        <!-- Table of Items (Only SKU, Product, Ordered Qty - Zero costs) -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 36px; text-align: center;">#</th>
                    <th style="width: 140px;">SKU</th>
                    <th>Producto / Descripción</th>
                    <th style="width: 110px; text-align: center;">Cantidad Pedida</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $index => $item)
                    <tr>
                        <td style="text-align: center; color: #94a3b8; font-size: 11px;">{{ $index + 1 }}</td>
                        <td class="sku-cell">{{ $item->product?->sku ?? $item->sku }}</td>
                        <td>
                            <div style="font-weight: 600;">{{ $item->product?->name ?? $item->product_name }}</div>
                            @if($item->product?->brand)
                                <div style="font-size: 11px; color: #64748b;">Marca: {{ $item->product->brand }}</div>
                            @endif
                        </td>
                        <td class="qty-cell">{{ $item->quantity_ordered }} pzas</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Summary & Notes -->
        <div class="footer-summary">
            <div class="notes-block">
                <div class="notes-title">Observaciones / Términos de Recepción</div>
                <div class="notes-content">
                    {{ $order->notes ?: 'Favor de entregar la mercancía debidamente empaquetada e identificada con este folio de orden de compra.' }}
                </div>
            </div>

            <div class="totals-box">
                <div class="total-row">
                    <span>Partidas ordenadas:</span>
                    <strong style="color: #0f172a;">{{ count($order->items) }}</strong>
                </div>
                <div class="total-row highlight">
                    <span>Total piezas pedidas:</span>
                    <span style="color: #059669;">{{ $order->total_units_ordered }} pzas</span>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-line">
                <div class="signature-title">Autorizado por Compras</div>
                <div class="signature-sub">T.O. THE BEAUTY SHOP (SBS)</div>
            </div>
            <div class="signature-line">
                <div class="signature-title">Recibido en Almacén</div>
                <div class="signature-sub">Firma y Sello de Recepción</div>
            </div>
        </div>
    </div>

</body>
</html>
