<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rótulo de Caja - {{ $shipment->shipment_code }}</title>
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
            padding: 20px;
        }
        .print-toolbar {
            max-width: 800px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
        }
        .btn:hover {
            background: #1d4ed8;
        }
        .label-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .box-label {
            background: white;
            border: 3px solid #000;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 30px;
            page-break-after: always;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .box-label:last-child {
            page-break-after: avoid;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #000;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }
        .meli-badge {
            background: #ffe600;
            color: #2d3277;
            padding: 6px 14px;
            font-size: 16px;
            font-weight: 900;
            border-radius: 6px;
            border: 1px solid #cca300;
            display: inline-block;
            letter-spacing: 0.5px;
        }
        .box-title {
            text-align: right;
        }
        .box-number-big {
            font-size: 28px;
            font-weight: 900;
            color: #000;
            line-height: 1.1;
        }
        .shipment-code {
            font-size: 14px;
            font-weight: 700;
            color: #475569;
            margin-top: 4px;
        }
        .dest-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            border-bottom: 2px solid #000;
            padding-bottom: 16px;
            margin-bottom: 16px;
        }
        .dest-card {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 12px;
            border-radius: 6px;
        }
        .card-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .card-val {
            font-size: 18px;
            font-weight: 900;
            color: #0f172a;
        }
        .card-sub {
            font-size: 12px;
            color: #475569;
            margin-top: 2px;
        }
        .carrier-badge {
            display: inline-block;
            background: #0284c7;
            color: white;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 4px;
            margin-right: 6px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .items-table th {
            background: #e2e8f0;
            color: #0f172a;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 8px 10px;
            text-align: left;
            border: 1px solid #cbd5e1;
        }
        .items-table td {
            font-size: 13px;
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
        }
        .items-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .sku-cell {
            font-family: monospace;
            font-weight: 800;
            font-size: 13px;
            color: #1e293b;
        }
        .qty-cell {
            text-align: right;
            font-weight: 900;
            font-size: 14px;
        }
        .summary-row {
            background: #f1f5f9;
            font-weight: 900;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 2px dashed #94a3b8;
            padding-top: 14px;
            font-size: 11px;
            color: #64748b;
        }
        .barcode-mock {
            font-family: "Courier New", monospace;
            letter-spacing: 4px;
            font-size: 14px;
            font-weight: 900;
            border: 1px solid #94a3b8;
            padding: 6px 12px;
            background: #fafafa;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            .print-toolbar {
                display: none;
            }
            .box-label {
                box-shadow: none;
                border: 2px solid #000;
                margin-bottom: 0;
                page-break-after: always;
            }
            .box-label:last-child {
                page-break-after: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <div>
            <strong>Rótulos de Envío FULL</strong>: {{ $shipment->shipment_code }} ({{ $boxes->count() }} cajas)
        </div>
        <button onclick="window.print()" class="btn">🖨️ Imprimir Rótulos</button>
    </div>

    <div class="label-container">
        @foreach($boxes as $box)
            <div class="box-label">
                <div class="header">
                    <div>
                        <div class="meli-badge">MERCADO LIBRE FULL</div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 4px; font-weight: 600;">
                            SISTEMA BEAUTYSHOP / ALMACÉN CENTRAL
                        </div>
                    </div>
                    <div class="box-title">
                        <div class="box-number-big">CAJA {{ $box->box_number }} DE {{ $shipment->total_boxes ?: $boxes->count() }}</div>
                        <div class="shipment-code">Folio: {{ $shipment->shipment_code }}</div>
                    </div>
                </div>

                <div class="dest-grid">
                    <div class="dest-card">
                        <div class="card-label">CEDIS / Bodega MeLi Destino</div>
                        <div class="card-val">{{ $shipment->meli_warehouse_code }}</div>
                        <div class="card-sub">{{ $shipment->meli_warehouse_name }}</div>
                        @if($shipment->meli_shipment_id)
                            <div class="card-sub"><strong>ID MeLi:</strong> {{ $shipment->meli_shipment_id }}</div>
                        @endif
                    </div>
                    <div class="dest-card">
                        <div class="card-label">Logística & Guía ENVIA</div>
                        <div class="card-val" style="font-size: 15px;">
                            <span class="carrier-badge">{{ $shipment->envia_carrier ?: 'ENVIA' }}</span>
                            {{ $shipment->envia_tracking_number ?: 'Pendiente' }}
                        </div>
                        <div class="card-sub">Capacidad estándar: <strong>{{ $box->capacity }} unidades</strong></div>
                        <div class="card-sub">Dimensiones: {{ $box->dimensions ?: '40x30x30 cm' }} @if($box->weight_kg > 0) | Peso: {{ $box->weight_kg }} kg @endif</div>
                    </div>
                </div>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">SKU</th>
                            <th style="width: 60%;">Descripción del Producto</th>
                            <th style="width: 15%; text-align: right;">Piezas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($box->items as $item)
                            <tr>
                                <td class="sku-cell">{{ $item->sku }}</td>
                                <td>
                                    <strong>{{ $item->product_name }}</strong>
                                    @if($item->inventoryProduct && $item->inventoryProduct->brand)
                                        <span style="font-size: 11px; color: #64748b;">({{ $item->inventoryProduct->brand }})</span>
                                    @endif
                                </td>
                                <td class="qty-cell">{{ $item->quantity_sent }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" style="text-align: center; color: #94a3b8; padding: 16px;">
                                    Caja sin productos asignados todavía.
                                </td>
                            </tr>
                        @endforelse
                        <tr class="summary-row">
                            <td colspan="2" style="text-align: right; padding-right: 12px; font-weight: 800;">
                                TOTAL EN ESTA CAJA:
                            </td>
                            <td class="qty-cell" style="color: #2563eb;">
                                {{ $box->units_count }} / {{ $box->capacity }} uds
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="footer">
                    <div>
                        <strong>Etiqueta de Bulto Máster</strong> · Colocar en cara lateral visible
                    </div>
                    <div class="barcode-mock">
                        *{{ $box->box_code ?: $shipment->shipment_code . '-B' . $box->box_number }}*
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
