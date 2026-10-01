<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetas de Producto MeLi FULL - {{ $shipment->shipment_code }}</title>
    <!-- JsBarcode via CDN para generar códigos de barras 100% nítidos en SVG -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
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
            padding: 14px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            border: 1px solid #cbd5e1;
        }

        .btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .labels-grid {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }

        /* Formato estándar de etiqueta térmica: 50mm x 30mm (o ~200px x 120px) */
        .product-label {
            background: white;
            border: 1.5px solid #000;
            border-radius: 4px;
            width: 220px;
            height: 130px;
            padding: 6px 8px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .label-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            margin-bottom: 2px;
        }

        .meli-tag {
            font-size: 8px;
            font-weight: 900;
            background: #ffe600;
            color: #2d3277;
            padding: 1px 4px;
            border-radius: 2px;
            border: 0.5px solid #cca300;
            letter-spacing: 0.3px;
        }

        .brand-text {
            font-size: 8px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            max-width: 100px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-title {
            font-size: 9px;
            font-weight: 700;
            line-height: 1.15;
            color: #000;
            max-height: 22px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            margin-bottom: 2px;
        }

        .barcode-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            margin: 1px 0;
        }

        .barcode-container svg {
            width: 100%;
            max-height: 48px;
        }

        .label-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-top: 1px dashed #64748b;
            padding-top: 2px;
            font-size: 8px;
            color: #334155;
            font-weight: bold;
        }

        .sku-badge {
            font-family: monospace;
            font-weight: 900;
            font-size: 9px;
            color: #000;
        }

        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .print-toolbar {
                display: none !important;
            }

            .labels-grid {
                max-width: 100%;
                margin: 0;
                gap: 0;
                display: block;
            }

            .product-label {
                box-shadow: none;
                border: 1px solid #000;
                margin: 0;
                width: 50mm;
                height: 30mm;
                page-break-after: always;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    @php
        $totalLabelsToPrint = 0;
        foreach($items as $it) {
            $totalLabelsToPrint += (int) $it->quantity_sent;
        }
    @endphp

    <div class="print-toolbar">
        <div>
            <h2 style="font-size: 15px; font-weight: 900;">
                Etiquetas de Producto para Mercado Libre FULL
            </h2>
            <p style="font-size: 12px; color: #64748b; margin-top: 2px;">
                Envío: <strong>{{ $shipment->shipment_code }}</strong> · 
                Total etiquetas a imprimir: <strong>{{ $totalLabelsToPrint }} stickers</strong>
            </p>
        </div>
        <button onclick="window.print()" class="btn">
            🖨️ Imprimir {{ $totalLabelsToPrint }} Etiquetas
        </button>
    </div>

    <div class="labels-grid">
        @if($totalLabelsToPrint === 0)
            <div style="background: white; padding: 40px; border-radius: 12px; text-align: center; border: 1px solid #cbd5e1; width: 100%;">
                <p style="font-size: 14px; color: #64748b; font-weight: 600;">
                    No hay productos marcados con requerimiento de etiquetado MeLi en este envío.
                </p>
                <p style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                    Todos los productos ingresados ya cuentan con código de fábrica legible.
                </p>
            </div>
        @else
            @foreach($items as $item)
                @php
                    $barcodeVal = $item->inventoryProduct?->barcode 
                        ?: ($item->mlm ?: $item->sku);
                    $brand = $item->inventoryProduct?->brand ?: 'MeLi FULL';
                    $qty = (int) $item->quantity_sent;
                @endphp

                @for($i = 1; $i <= $qty; $i++)
                    <div class="product-label">
                        <div class="label-header">
                            <span class="meli-tag">MELI FULL</span>
                            <span class="brand-text">{{ $brand }}</span>
                        </div>

                        <div class="product-title" title="{{ $item->product_name }}">
                            {{ $item->product_name }}
                        </div>

                        <div class="barcode-container">
                            <svg class="barcode-svg" data-barcode="{{ $barcodeVal }}"></svg>
                        </div>

                        <div class="label-footer">
                            <span class="sku-badge">{{ $item->sku }}</span>
                            <span>{{ $i }}/{{ $qty }}</span>
                        </div>
                    </div>
                @endfor
            @endforeach
        @endif
    </div>

    <script>
        // Renderizar códigos de barras en cada etiqueta
        document.addEventListener('DOMContentLoaded', function() {
            var barcodeElements = document.querySelectorAll('.barcode-svg');
            barcodeElements.forEach(function(el) {
                var code = el.getAttribute('data-barcode') || '00000000';
                try {
                    JsBarcode(el, code, {
                        format: "CODE128",
                        width: 1.2,
                        height: 32,
                        displayValue: true,
                        fontSize: 9,
                        font: "monospace",
                        margin: 0,
                    });
                } catch(e) {
                    console.error("Error generando código de barras:", e);
                }
            });
        });
    </script>
</body>
</html>
