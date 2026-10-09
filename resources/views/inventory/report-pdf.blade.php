<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Inventario SBS - {{ $selectedBrand }}</title>
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
            max-width: 1024px;
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
            background-color: #4f46e5;
            color: #ffffff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: background 0.15s;
        }

        .btn-print:hover {
            background-color: #4338ca;
        }

        .btn-download-pdf {
            background-color: #059669;
            color: #ffffff;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }

        .btn-download-pdf:hover {
            background-color: #047857;
        }

        .btn-back {
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

        .btn-back:hover {
            background: #f1f5f9;
        }

        .page-sheet {
            max-width: 1024px;
            margin: 0 auto;
            background: #ffffff;
            padding: 32px 36px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }

        .header-grid {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }

        .logo-title {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        .logo-sub {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #ec4899;
            margin-top: 2px;
        }

        .report-badge {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 6px;
        }

        .meta-right {
            text-align: right;
            font-size: 12px;
            color: #64748b;
        }

        .meta-right strong {
            color: #0f172a;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
        }

        .kpi-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }

        .kpi-val {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            margin-top: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.04em;
            padding: 8px 10px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 2px solid #94a3b8;
            text-align: left;
        }

        td {
            padding: 7px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        tr:nth-child(even) td {
            background-color: #fafbfd;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .sku-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            font-size: 11px;
            color: #4338ca;
        }

        .badge-stock {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 11px;
        }

        .badge-positive {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-zero {
            background: #fee2e2;
            color: #b91c1c;
        }

        .footer-note {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: #94a3b8;
        }

        .signatures {
            margin-top: 36px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding-top: 20px;
        }

        .sig-box {
            text-align: center;
            border-top: 1px solid #64748b;
            padding-top: 6px;
            font-size: 11px;
            color: #475569;
            font-weight: 600;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .no-print-bar {
                display: none;
            }
            .page-sheet {
                box-shadow: none;
                border: none;
                padding: 0;
                max-width: 100%;
            }
            tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <div>
            <span style="font-weight: 700; font-size: 13px; color: #0f172a;">Vista Previa de Impresión</span>
            <span style="font-size: 12px; color: #64748b; margin-left: 8px;">(SBS · Salon & Barber Supply)</span>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="downloadPdfNow()" class="btn-download-pdf">
                ⬇️ Descargar Archivo PDF
            </button>
            <button onclick="window.print()" class="btn-print">
                🖨️ Imprimir
            </button>
            <a href="javascript:window.close()" class="btn-back">Cerrar</a>
        </div>
    </div>

    <div class="page-sheet">
        <div class="header-grid">
            <div>
                <div class="logo-title">SBS · SALON & BARBER SUPPLY</div>
                <div class="logo-sub">T.O. THE BEAUTY SHOP</div>
                <div class="report-badge">Reporte de Inventario Físico</div>
            </div>
            <div class="meta-right">
                <div><strong>Alcance:</strong> {{ $selectedBrand }}</div>
                <div><strong>Emisión:</strong> {{ $generatedAt }}</div>
                <div><strong>Almacén:</strong> Almacén General SBS Hermosillo</div>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">SKUs Listados</div>
                <div class="kpi-val">{{ number_format($totalSkus) }}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Piezas Físicas Totales</div>
                <div class="kpi-val">{{ number_format($totalPhysicalUnits) }} <span style="font-size: 12px; font-weight: 500; color: #64748b;">piezas</span></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Valorización a Costo</div>
                <div class="kpi-val" style="color: #4f46e5;">${{ number_format($totalCostValuation, 2) }} <span style="font-size: 11px; font-weight: 600; color: #64748b;">MXN</span></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 14%;">SKU</th>
                    <th>Producto / Descripción</th>
                    <th style="width: 13%;">Marca</th>
                    <th style="width: 10%;">Ubicación</th>
                    <th class="text-center" style="width: 8%;">Físico</th>
                    <th class="text-center" style="width: 8%;">Reserv.</th>
                    <th class="text-center" style="width: 8%;">Disp.</th>
                    <th class="text-right" style="width: 10%;">Costo</th>
                    <th class="text-right" style="width: 11%;">Val. Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $p)
                    @php
                        $physical = (int) ($p->physical_stock ?? 0);
                        $reserved = (int) ($p->reserved_stock ?? 0);
                        $available = (int) ($p->available_stock ?? ($physical - $reserved));
                        $cost = (float) ($p->cost ?? 0);
                        $val = $cost * $physical;
                    @endphp
                    <tr>
                        <td class="sku-code">{{ $p->sku }}</td>
                        <td>
                            <div style="font-weight: 700; color: #0f172a;">{{ $p->name }}</div>
                            @if($p->barcode)
                                <div style="font-size: 10px; color: #64748b; font-family: monospace;">Cód: {{ $p->barcode }}</div>
                            @endif
                        </td>
                        <td style="font-weight: 600; color: #334155; text-transform: uppercase;">
                            {{ $p->brand ?: 'SIN MARCA' }}
                        </td>
                        <td style="font-weight: 600; color: #475569;">
                            {{ $p->primaryLocation ? $p->primaryLocation->code : '—' }}
                        </td>
                        <td class="text-center">
                            <span class="badge-stock {{ $physical > 0 ? 'badge-positive' : 'badge-zero' }}">
                                {{ $physical }}
                            </span>
                        </td>
                        <td class="text-center" style="color: #64748b;">{{ $reserved }}</td>
                        <td class="text-center" style="font-weight: 800; color: #0f172a;">{{ $available }}</td>
                        <td class="text-right" style="color: #475569;">${{ number_format($cost, 2) }}</td>
                        <td class="text-right" style="font-weight: 800; color: #0f172a;">${{ number_format($val, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 30px; color: #64748b;">
                            No hay productos registrados para los filtros seleccionados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="signatures">
            <div class="sig-box">
                Responsable de Almacén / Auditor
            </div>
            <div class="sig-box">
                Supervisión de Operaciones SBS
            </div>
        </div>

        <div class="footer-note">
            <span>Sistema SBS ERP · mrpoolhmo.com · Impreso el {{ $generatedAt }}</span>
            <span>Documento Oficial de Control Interno SBS</span>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
    <script>
        function downloadPdfNow() {
            var btn = document.querySelector('.btn-download-pdf');
            var oldText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerText = 'Generando archivo...';
                btn.disabled = true;
            }
            var element = document.querySelector('.page-sheet');
            var opt = {
                margin: [8, 8, 8, 8],
                filename: 'Reporte-Inventario-{{ \Illuminate\Support\Str::slug($selectedBrand) }}-{{ date("Y-m-d") }}.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' }
            };
            html2pdf().set(opt).from(element).save().then(function() {
                if (btn) {
                    btn.innerText = '✓ PDF Descargado';
                    btn.disabled = false;
                    setTimeout(function() {
                        btn.innerHTML = oldText;
                    }, 3000);
                }
            }).catch(function(e) {
                console.error(e);
                if (btn) {
                    btn.innerHTML = oldText;
                    btn.disabled = false;
                }
                window.print();
            });
        }

        @if(request('download') == 1)
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(downloadPdfNow, 400);
        });
        @endif
    </script>
</body>
</html>
