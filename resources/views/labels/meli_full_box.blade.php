<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rótulo de Caja MeLi FULL - {{ $shipment->shipment_code }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        body {
            background-color: #f1f5f9;
            color: #000;
            padding: 20px;
        }

        /* TOOLBAR */
        .print-toolbar {
            max-width: 800px;
            margin: 0 auto 20px auto;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 14px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
        }
        .btn-group {
            display: flex;
            gap: 8px;
        }
        .mode-btn {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .mode-btn.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        .btn-print {
            background: #0f172a;
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-print:hover {
            background: #1e293b;
        }

        /* ----------------------------------------------------
           FORMATO 1: TÉRMICO ME-LI OFICIAL (100x150 mm / 4x6 pulg)
           ---------------------------------------------------- */
        .thermal-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 30px;
        }
        .thermal-label {
            width: 100mm;
            min-height: 150mm;
            height: 150mm;
            background: #ffffff;
            border: 1px solid #94a3b8;
            padding: 8mm 6mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
            page-break-after: always;
            position: relative;
        }
        .thermal-label:last-child {
            page-break-after: avoid;
        }

        /* Thermal Header */
        .th-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .th-brand {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .th-handshake {
            width: 32px;
            height: 22px;
            display: inline-block;
        }
        .th-seller-id {
            font-size: 11px;
            font-weight: 900;
            font-family: monospace;
            color: #000;
        }
        .th-envio-line {
            font-size: 11px;
            font-weight: 800;
            margin-top: 2px;
            color: #000;
        }
        .th-full-logo {
            font-size: 17px;
            font-weight: 900;
            letter-spacing: 0.5px;
            color: #000;
            display: flex;
            align-items: center;
            gap: 2px;
        }

        /* Framed Delivery Notice */
        .th-notice-box {
            border: 1.5px solid #000;
            border-radius: 2px;
            padding: 4px 10px;
            text-align: center;
            margin: 4mm auto 2mm auto;
            width: 82%;
        }
        .th-notice-title {
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .th-notice-sub {
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        /* Center QR & Destination */
        .th-center {
            text-align: center;
            margin: 2mm 0;
        }
        .th-qr-img {
            width: 36mm;
            height: 36mm;
            display: inline-block;
        }
        .th-qr-code-text {
            font-family: monospace;
            font-size: 12px;
            font-weight: 900;
            margin-top: 2px;
            letter-spacing: 0.5px;
        }
        .th-destination-huge {
            font-size: 52px;
            font-weight: 900;
            letter-spacing: 2px;
            line-height: 1;
            margin-top: 2mm;
            font-family: Arial, -apple-system, sans-serif;
            color: #000;
        }

        /* Divider */
        .th-divider {
            border: none;
            border-top: 1.5px solid #000;
            margin: 3mm 0 2.5mm 0;
            width: 100%;
        }

        /* Thermal Footer */
        .th-footer {
            font-size: 10.5px;
            line-height: 1.35;
            color: #000;
        }
        .th-bultos-title {
            font-weight: 900;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        .th-warehouse-desc {
            font-weight: 700;
            font-size: 10.5px;
        }
        .th-envio-bottom {
            margin-top: 2.5mm;
            font-weight: 900;
            font-size: 11px;
        }
        .th-carrier-note {
            margin-top: 2mm;
            padding-top: 1.5mm;
            border-top: 1px dashed #94a3b8;
            font-size: 9px;
            color: #475569;
            display: flex;
            justify-content: space-between;
            font-weight: 700;
        }

        /* ----------------------------------------------------
           FORMATO 2: PACKING LIST INTERNO DETALLADO (HOJA CARTA)
           ---------------------------------------------------- */
        .packing-wrapper {
            max-width: 800px;
            margin: 0 auto;
        }
        .box-slip {
            background: white;
            border: 2px solid #000;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 30px;
            page-break-after: always;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .box-slip:last-child {
            page-break-after: avoid;
        }
        .slip-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .slip-badge {
            background: #ffe600;
            color: #2d3277;
            padding: 4px 10px;
            font-size: 14px;
            font-weight: 900;
            border-radius: 4px;
            display: inline-block;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0;
            font-size: 12px;
        }
        .items-table th {
            background: #f1f5f9;
            padding: 8px 10px;
            text-align: left;
            border: 1px solid #cbd5e1;
            font-size: 11px;
            text-transform: uppercase;
        }
        .items-table td {
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
        }

        /* MEDIA PRINT */
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .print-toolbar {
                display: none !important;
            }
            body.show-thermal .packing-wrapper {
                display: none !important;
            }
            body.show-packing .thermal-wrapper {
                display: none !important;
            }

            /* For Thermal Printing (100mm x 150mm) */
            body.show-thermal {
                margin: 0;
            }
            body.show-thermal .thermal-label {
                border: none;
                box-shadow: none;
                margin: 0;
                padding: 6mm 5mm;
                page-break-after: always;
            }
            body.show-thermal .thermal-label:last-child {
                page-break-after: avoid;
            }
            @page {
                size: 100mm 150mm;
                margin: 0;
            }
        }
    </style>
</head>
<body class="show-thermal">
    @php
        $meliUserId = $meliAccount?->meli_user_id ?: '139184271';
        $inboundId = $shipment->meli_shipment_id ?: $shipment->shipment_code;
    @endphp

    <!-- PRINT TOOLBAR -->
    <div class="print-toolbar">
        <div>
            <span style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; display: block;">Envío MeLi FULL</span>
            <strong style="font-size: 16px;">{{ $shipment->shipment_code }}</strong>
            <span style="font-size: 13px; color: #64748b;">· {{ $boxes->count() }} cajas de 30 kg</span>
        </div>

        <div class="btn-group">
            <button type="button" class="mode-btn active" id="btnModeThermal" onclick="setMode('thermal')">
                ⚡ Rótulo Térmico MeLi (100x150 mm)
            </button>
            <button type="button" class="mode-btn" id="btnModePacking" onclick="setMode('packing')">
                📋 Packing List Detallado (Carta)
            </button>
        </div>

        <button onclick="window.print()" class="btn-print">
            🖨️ Mandar a Imprimir
        </button>
    </div>

    <!-- 1. FORMATO TÉRMICO OFICIAL MERCADO LIBRE FULL (100x150 mm / 4x6 pulg) -->
    <div class="thermal-wrapper" id="thermalWrapper">
        @foreach($boxes as $box)
            @php
                // Formato idéntico al oficial: {meli_shipment_id}/{box_number} (ej. 78831739/1)
                $bultoCode = "{$inboundId}/{$box->box_number}";
                $warehouseCode = $shipment->meli_warehouse_code ?: 'MXCD06';
                $warehouseName = $shipment->meli_warehouse_name ?: \App\Models\MeliFullShipment::WAREHOUSES[$warehouseCode] ?? 'Centro logístico Panorama MX06';
                $qrUrl = "https://quickchart.io/qr?text=" . urlencode($bultoCode) . "&size=260&margin=1";
            @endphp

            <div class="thermal-label">
                <!-- TOP HEADER -->
                <div>
                    <div class="th-header">
                        <div class="th-brand">
                            <!-- MeLi Handshake SVG -->
                            <svg class="th-handshake" viewBox="0 0 48 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M24 16C24 16 19 11 13 11C7 11 4 15 4 15L15 26L21 20L24 16Z" fill="#000" opacity="0.9"/>
                                <path d="M24 16C24 16 29 11 35 11C41 11 44 15 44 15L33 26L27 20L24 16Z" fill="#000"/>
                                <path d="M21 20L24 23L27 20" stroke="#fff" stroke-width="2"/>
                            </svg>
                            <div>
                                <div class="th-seller-id">#{{ $meliUserId }}</div>
                                <div class="th-envio-line">Envío: {{ $bultoCode }}</div>
                            </div>
                        </div>

                        <div class="th-full-logo">
                            <span style="font-size: 15px;">⚡</span>&nbsp;FULL
                        </div>
                    </div>

                    <!-- NOTICE RECTANGLE -->
                    <div class="th-notice-box">
                        <div class="th-notice-title">ENTREGAR A FULL</div>
                        <div class="th-notice-sub"><u>NO VÁLIDA</u> PARA COLECTA</div>
                    </div>

                    <!-- CENTER QR CODE & DESTINATION -->
                    <div class="th-center">
                        <img src="{{ $qrUrl }}" alt="QR {{ $bultoCode }}" class="th-qr-img" />
                        <div class="th-qr-code-text">{{ $bultoCode }}</div>
                        <div class="th-destination-huge">{{ $warehouseCode }}</div>
                    </div>
                </div>

                <!-- BOTTOM INFO -->
                <div>
                    <hr class="th-divider" />
                    <div class="th-footer">
                        <div class="th-bultos-title">BULTOS</div>
                        <div class="th-warehouse-desc">
                            {{ $warehouseName }} – {{ $warehouseCode }}
                        </div>
                        <div class="th-envio-bottom">
                            Envío: {{ $bultoCode }}
                        </div>

                        @if($shipment->envia_tracking_number)
                            <div class="th-carrier-note">
                                <span>Transportista: {{ $shipment->envia_carrier ?: 'Estafeta' }}</span>
                                <span>Guía: {{ $shipment->envia_tracking_number }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- 2. FORMATO PACKING LIST DETALLADO PARA CONTROL INTERNO DE ALMACÉN -->
    <div class="packing-wrapper" id="packingWrapper" style="display: none;">
        @foreach($boxes as $box)
            <div class="box-slip">
                <div class="slip-header">
                    <div>
                        <div class="slip-badge">MERCADO LIBRE FULL</div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 4px; font-weight: 700;">
                            BEAUTYSHOP · HOJA DE CONTROL Y CONTENIDO
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <h2 style="font-size: 20px; font-weight: 900;">CAJA {{ $box->box_number }} DE {{ $shipment->total_boxes ?: $boxes->count() }} (BULTO #{{ $box->bulto_number ?: $box->box_number }})</h2>
                        <span style="font-size: 12px; font-weight: 700; color: #64748b;">
                            {{ $shipment->meli_warehouse_code }} · Peso: {{ number_format($box->weight_kg, 2) }} kg / 30.00 kg
                        </span>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; font-size: 12px;">
                    <div style="background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Destino MeLi</span>
                        <div style="font-size: 14px; font-weight: 800;">{{ $shipment->meli_warehouse_code }}</div>
                        <div style="color: #64748b;">{{ $shipment->meli_warehouse_name }}</div>
                    </div>
                    <div style="background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Paquetería ENVIA</span>
                        <div style="font-size: 14px; font-weight: 800;">{{ $shipment->envia_carrier ?: 'Estafeta' }}</div>
                        <div style="color: #64748b;">Guía: {{ $shipment->envia_tracking_number ?: 'Pendiente' }}</div>
                    </div>
                </div>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 22%;">SKU / MeLi</th>
                            <th>Descripción</th>
                            <th style="width: 12%; text-align: center;">Piezas</th>
                            <th style="width: 14%; text-align: right;">Peso Unit.</th>
                            <th style="width: 14%; text-align: right;">Peso Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($box->items as $it)
                            <tr>
                                <td style="font-family: monospace; font-weight: 800;">{{ $it->sku }}</td>
                                <td>{{ $it->product_name }}</td>
                                <td style="text-align: center; font-weight: 800;">{{ $it->quantity_sent }} uds</td>
                                <td style="text-align: right; font-family: monospace;">{{ number_format($it->unit_weight_kg ?: 1.0, 2) }} kg</td>
                                <td style="text-align: right; font-family: monospace; font-weight: 800;">{{ number_format($it->total_weight_kg ?: ($it->quantity_sent * ($it->unit_weight_kg ?: 1.0)), 2) }} kg</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 16px; color: #94a3b8;">
                                    Caja sin productos registrados.
                                </td>
                            </tr>
                        @endforelse
                        <tr style="background: #f8fafc; font-weight: 900;">
                            <td colspan="2" style="text-align: right;">TOTAL CAJA #{{ $box->box_number }}:</td>
                            <td style="text-align: center; color: #2563eb;">{{ $box->units_count }} piezas</td>
                            <td></td>
                            <td style="text-align: right; color: #2563eb; font-family: monospace;">{{ number_format($box->weight_kg, 2) }} kg</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <script>
        function setMode(mode) {
            const body = document.body;
            const btnThermal = document.getElementById('btnModeThermal');
            const btnPacking = document.getElementById('btnModePacking');
            const thermalWrapper = document.getElementById('thermalWrapper');
            const packingWrapper = document.getElementById('packingWrapper');

            if (mode === 'thermal') {
                body.className = 'show-thermal';
                btnThermal.className = 'mode-btn active';
                btnPacking.className = 'mode-btn';
                thermalWrapper.style.display = 'flex';
                packingWrapper.style.display = 'none';
            } else {
                body.className = 'show-packing';
                btnThermal.className = 'mode-btn';
                btnPacking.className = 'mode-btn active';
                thermalWrapper.style.display = 'none';
                packingWrapper.style.display = 'block';
            }
        }
    </script>
</body>
</html>
