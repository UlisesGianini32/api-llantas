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
            page-break-inside: avoid !important;
            break-inside: avoid !important;
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
            line-height: 1.5;
        }

        .meta-right strong {
            color: #0f172a;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .kpi-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
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

        /* Tabla basada en filas individuales para evitar cortes de página */
        .report-table {
            width: 100%;
            display: flex;
            flex-direction: column;
        }

        .report-header-row {
            display: grid;
            grid-template-columns: 45px 1fr 180px 100px;
            background: #f1f5f9;
            color: #334155;
            font-weight: 800;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.05em;
            padding: 9px 12px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 2px solid #94a3b8;
            align-items: center;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .report-row {
            display: grid;
            grid-template-columns: 45px 1fr 180px 100px;
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
            align-items: center;
            font-size: 11px;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .report-row:nth-child(even) {
            background-color: #fafbfd;
        }

        .col-num {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-align: center;
        }

        .col-product {
            font-weight: 700;
            color: #0f172a;
            padding-right: 14px;
            line-height: 1.35;
        }

        .col-brand {
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.02em;
        }

        .col-stock {
            text-align: center;
        }

        .badge-kit {
            display: inline-block;
            background: #e0e7ff;
            color: #4338ca;
            font-size: 9px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: middle;
            letter-spacing: 0.05em;
        }

        .badge-stock {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 12px;
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
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .signatures {
            margin-top: 36px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding-top: 20px;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .sig-box {
            text-align: center;
            border-top: 1px solid #64748b;
            padding-top: 6px;
            font-size: 11px;
            color: #475569;
            font-weight: 600;
        }

        .avoid-break {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .no-print-bar {
                display: none !important;
            }
            .page-sheet {
                box-shadow: none;
                border: none;
                padding: 0;
                max-width: 100%;
            }
            .report-row, .avoid-break, .signatures, .kpi-card, .kpi-grid, .header-grid {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
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
            <a href="javascript:window.close()" class="btn-back">✕ Cerrar</a>
        </div>
    </div>

    <div class="page-sheet">
        <div class="header-grid avoid-break">
            <div>
                <div class="logo-title">SBS · SALON & BARBER SUPPLY</div>
                <div class="logo-sub">T.O. THE BEAUTY SHOP</div>
                <div class="report-badge">Reporte de Inventario de Productos</div>
            </div>
            <div class="meta-right">
                <div><strong>Alcance:</strong> {{ $selectedBrand }}</div>
                <div><strong>Filtro Kits:</strong> {{ $kitsFilter === 'exclude' ? 'Solo productos simples (sin kits)' : ($kitsFilter === 'only' ? 'Solo kits' : 'Incluye productos y kits') }}</div>
                <div><strong>Emisión:</strong> {{ $generatedAt }}</div>
                <div><strong>Almacén:</strong> Almacén General SBS Hermosillo</div>
            </div>
        </div>

        <div class="kpi-grid avoid-break">
            <div class="kpi-card avoid-break">
                <div class="kpi-label">Productos Listados</div>
                <div class="kpi-val">{{ number_format($totalSkus) }}</div>
            </div>
            <div class="kpi-card avoid-break">
                <div class="kpi-label">Existencias Disponibles</div>
                <div class="kpi-val">{{ number_format($totalAvailableUnits) }} <span style="font-size: 12px; font-weight: 500; color: #64748b;">piezas</span></div>
            </div>
            <div class="kpi-card avoid-break">
                <div class="kpi-label">Con Existencias / Agotados</div>
                <div class="kpi-val" style="color: #059669;">
                    {{ number_format($withStockCount) }}
                    <span style="font-size: 11px; font-weight: 600; color: #64748b;">/ {{ number_format($zeroStockCount) }} agotados</span>
                </div>
            </div>
        </div>

        <div class="report-table">
            <div class="report-header-row avoid-break">
                <div class="col-num">#</div>
                <div>Producto / Descripción</div>
                <div class="col-brand">Marca</div>
                <div class="col-stock">Existencias</div>
            </div>

            @php $index = 1; @endphp
            @forelse($products as $p)
                @php
                    $available = (int) ($p->available_stock ?? (($p->physical_stock ?? 0) - ($p->reserved_stock ?? 0)));
                @endphp
                <div class="report-row avoid-break">
                    <div class="col-num">{{ $index++ }}</div>
                    <div class="col-product">
                        {{ $p->name }}
                        @if($p->isKit())
                            <span class="badge-kit">KIT</span>
                        @endif
                    </div>
                    <div class="col-brand">
                        {{ $p->brand ?: 'SIN MARCA' }}
                    </div>
                    <div class="col-stock">
                        <span class="badge-stock {{ $available > 0 ? 'badge-positive' : 'badge-zero' }}">
                            {{ $available }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="report-row avoid-break" style="grid-template-columns: 1fr; text-align: center; padding: 30px; color: #64748b;">
                    No hay productos registrados para los filtros seleccionados.
                </div>
            @endforelse
        </div>

        <div class="signatures avoid-break">
            <div class="sig-box">
                Responsable de Almacén / Auditor
            </div>
            <div class="sig-box">
                Supervisión de Operaciones SBS
            </div>
        </div>

        <div class="footer-note avoid-break">
            <span>Sistema SBS ERP · mrpoolhmo.com · Generado el {{ $generatedAt }}</span>
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
                jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' },
                pagebreak: {
                    mode: ['avoid-all', 'css', 'legacy'],
                    avoid: ['.report-row', '.kpi-card', '.signatures', '.header-grid', '.avoid-break']
                }
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
