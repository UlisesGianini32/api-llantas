<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vale de Entrega - {{ $dispatch['dispatch_number'] }}</title>
    <link rel="icon" type="image/png" href="/logo-beauty-shop.png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
        body { background: #f8fafc; color: #0f172a; padding: 20px; font-size: 12px; }
        .no-print-bar { max-width: 840px; margin: 0 auto 14px auto; background: #fff; padding: 12px 18px; border-radius: 10px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .btn { padding: 8px 14px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #059669; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .sheet { max-width: 840px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 24px 28px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f172a; padding-bottom: 14px; margin-bottom: 16px; }
        .company h1 { font-size: 18px; font-weight: 800; letter-spacing: 0.3px; }
        .company p { color: #475569; font-size: 11px; margin-top: 2px; }
        .folio-box { text-align: right; }
        .folio-box .folio { font-size: 16px; font-weight: 800; color: #0f172a; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-status { background: #dbeafe; color: #1d4ed8; }
        .badge-prov { background: #fef3c7; color: #92400e; }
        .badge-reg { background: #dcfce7; color: #166534; }
        .meta-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        .meta-label { font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700; }
        .meta-val { font-size: 12px; font-weight: 600; color: #0f172a; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f1f5f9; text-align: left; font-size: 10px; text-transform: uppercase; color: #334155; padding: 8px 6px; border-bottom: 1px solid #cbd5e1; }
        td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals-box { margin-top: 14px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .totals-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; min-width: 260px; }
        .totals-row { display: flex; justify-content: space-between; margin-bottom: 4px; font-size: 12px; }
        .totals-row strong { font-size: 13px; }
        .disclaimer { margin-top: 10px; padding: 8px 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; color: #92400e; font-size: 10px; }
        .signatures { display: grid; grid-template-columns: repeat(2, 1fr); gap: 40px; margin-top: 46px; }
        .sig-block { text-align: center; }
        .sig-line { border-top: 1px solid #0f172a; padding-top: 6px; font-weight: 700; font-size: 11px; }
        .sig-sub { color: #475569; font-size: 10px; margin-top: 2px; }
        @media print {
            body { background: #fff; padding: 0; }
            .no-print-bar { display: none !important; }
            .sheet { border: none; border-radius: 0; padding: 10px; max-width: none; }
        }
    </style>
</head>
<body>
@php
    $opLabels = [
        'INTERNAL_CONSUMPTION' => 'Consumo interno',
        'SALON_SALE' => 'Venta al salón (referencial)',
        'TRANSFER' => 'Traspaso de mercancía',
    ];
    $statusLabels = [
        'DRAFT' => 'Borrador',
        'CONFIRMED' => 'Confirmada',
        'CANCELLED' => 'Cancelada / Anulada',
        'PARTIALLY_RETURNED' => 'Devuelta parcialmente',
        'FULLY_RETURNED' => 'Devuelta totalmente',
    ];
    $uomLabels = [
        'UNIT' => 'Pza',
        'TUBE' => 'Tubo',
        'BOTTLE' => 'Botella',
        'SACHET' => 'Sobre',
        'BOX' => 'Caja',
        'PACK' => 'Paquete',
    ];
@endphp

<div class="no-print-bar">
    <div>
        <strong>Vale de Entrega · {{ $dispatch['dispatch_number'] }}</strong>
        @if($canTogglePrices)
            <span style="margin-left: 12px;">
                @if($includePrices)
                    <a class="btn btn-secondary" href="{{ route('inventory.dispatches.pdf', ['inventoryDispatch' => $dispatch['id']]) }}">Ocultar precios estilista</a>
                @else
                    <a class="btn btn-secondary" href="{{ route('inventory.dispatches.pdf', ['inventoryDispatch' => $dispatch['id'], 'include_prices' => 1]) }}">Mostrar precios estilista (referencial)</a>
                @endif
            </span>
        @endif
    </div>
    <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>

<div class="sheet">
    <div class="header">
        <div class="company">
            <h1>MrPoolHMO · Beauty Shop</h1>
            <p>Comprobante de Salida de Almacén y Surtido a Salones</p>
            <p>mrpoolhmo.com · Hermosillo, Sonora</p>
        </div>
        <div class="folio-box">
            <div class="folio">{{ $dispatch['dispatch_number'] }}</div>
            <div style="margin-top: 4px;">
                <span class="badge badge-status">{{ $statusLabels[$dispatch['status']] ?? $dispatch['status'] }}</span>
            </div>
            <div style="margin-top: 4px; color: #475569; font-size: 11px;">
                Fecha efectiva: <strong>{{ $effectiveLocal }}</strong> (Hermosillo)
            </div>
        </div>
    </div>

    <div class="meta-grid">
        <div>
            <div class="meta-label">Salón / Destino</div>
            <div class="meta-val">{{ $dispatch['destination_name'] }} ({{ $dispatch['destination_code'] }})</div>
        </div>
        <div>
            <div class="meta-label">Tipo de salida</div>
            <div class="meta-val">{{ $opLabels[$dispatch['operation_type']] ?? $dispatch['operation_type'] }}</div>
        </div>
        <div>
            <div class="meta-label">Entregó (Almacén)</div>
            <div class="meta-val">{{ $dispatch['delivered_by_name'] ?: '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Recibió (Salón)</div>
            <div class="meta-val">{{ $dispatch['received_by_name'] ?: 'Pendiente de firma' }}</div>
        </div>
        @if(!empty($dispatch['notes']))
        <div style="grid-column: span 2;">
            <div class="meta-label">Observaciones</div>
            <div class="meta-val" style="font-weight: 400;">{{ $dispatch['notes'] }}</div>
        </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 28px;">#</th>
                <th>Producto (Snapshot confirmado)</th>
                <th>Códigos</th>
                <th>Ubicación</th>
                <th class="num">Cantidad</th>
                @if($includePrices)
                    <th class="num">Precio Estilista Ref.</th>
                    <th class="num">Importe Ref.</th>
                @endif
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dispatch['items'] as $idx => $item)
                @php
                    $isProv = in_array($item['identification_status'], ['PROVISIONAL', 'AMBIGUOUS_PENDING'], true);
                    $uom = $uomLabels[$item['captured_unit_of_measure']] ?? $item['captured_unit_of_measure'];
                    $stylistUnit = $item['unit_price_stylist_snapshot'] ?? null;
                    $normQty = $item['normalized_physical_quantity'] ?? null;
                    $lineTotal = ($includePrices && $stylistUnit !== null && $normQty !== null) ? ($stylistUnit * $normQty) : null;
                @endphp
                <tr>
                    <td>{{ $idx + 1 }}</td>
                    <td>
                        <strong>{{ $item['captured_name'] }}</strong>
                        <div>
                            <span class="badge {{ $isProv ? 'badge-prov' : 'badge-reg' }}">
                                {{ $isProv ? 'Provisional' : 'Registrado' }}
                            </span>
                            @if(!empty($item['captured_brand'])) <span>· {{ $item['captured_brand'] }}</span> @endif
                            @if(!empty($item['captured_tone'])) <span>· Tono {{ $item['captured_tone'] }}</span> @endif
                            @if(!empty($item['captured_presentation'])) <span>· {{ $item['captured_presentation'] }}</span> @endif
                        </div>
                    </td>
                    <td style="font-family: monospace; font-size: 11px;">
                        @if(!empty($item['captured_sku'])) <div>SKU: {{ $item['captured_sku'] }}</div> @endif
                        @if(!empty($item['captured_barcode'])) <div>CB: {{ $item['captured_barcode'] }}</div> @endif
                        @if(!empty($item['provisional_code'])) <div>{{ $item['provisional_code'] }}</div> @endif
                        @if(empty($item['captured_sku']) && empty($item['captured_barcode']) && empty($item['provisional_code'])) — @endif
                    </td>
                    <td>{{ $item['location_code'] ?: '—' }}</td>
                    <td class="num">
                        <strong>{{ $item['captured_quantity'] }} {{ $uom }}</strong>
                        @if($item['unit_conversion_factor'] !== null && $item['unit_conversion_factor'] > 1)
                            <div style="font-size: 10px; color: #475569;">({{ $item['normalized_physical_quantity'] }} pzas)</div>
                        @elseif($item['normalized_physical_quantity'] === null)
                            <div style="font-size: 10px; color: #b45309;">Sin equivalencia</div>
                        @endif
                    </td>
                    @if($includePrices)
                        <td class="num">
                            {{ $stylistUnit !== null ? '$' . number_format((float) $stylistUnit, 2) : 'Sin precio' }}
                        </td>
                        <td class="num">
                            {{ $lineTotal !== null ? '$' . number_format((float) $lineTotal, 2) : '—' }}
                        </td>
                    @endif
                    <td>{{ $item['line_notes'] ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals-box">
        <div style="flex: 1;">
            <div class="disclaimer">
                Documento operativo de entrega física generado con snapshots confirmados.
                @if($includePrices)
                    <strong>Importes estrictamente referenciales (precio estilista): no constituye factura, ingreso contable ni cuenta por cobrar.</strong>
                @else
                    No incluye costos de adquisición ni márgenes.
                @endif
            </div>
        </div>
        <div class="totals-card">
            <div class="totals-row">
                <span>Total de partidas:</span>
                <strong>{{ $dispatch['total_lines_count'] }}</strong>
            </div>
            <div class="totals-row">
                <span>Total piezas físicas:</span>
                <strong>{{ $dispatch['total_physical_units'] }}</strong>
            </div>
            @if(($dispatch['unconverted_containers_count'] ?? 0) > 0)
            <div class="totals-row" style="color: #b45309;">
                <span>Partidas en contenedor sin equivalencia:</span>
                <strong>{{ $dispatch['unconverted_containers_count'] }}</strong>
            </div>
            @endif
            @if($includePrices)
            <div class="totals-row" style="border-top: 1px solid #cbd5e1; padding-top: 6px; margin-top: 6px;">
                <span>Subtotal estilista ref.:</span>
                <strong>
                    @if(isset($dispatch['reference_subtotal_stylist']) && $dispatch['reference_subtotal_stylist'] !== null)
                        ${{ number_format((float) $dispatch['reference_subtotal_stylist'], 2) }}
                    @else
                        Sin precio
                    @endif
                </strong>
            </div>
            @if(empty($dispatch['reference_stylist_complete']))
                <div style="font-size: 10px; color: #b45309; text-align: right;">Subtotal parcial (existen partidas sin precio)</div>
            @endif
            @endif
        </div>
    </div>

    <div class="signatures">
        <div class="sig-block">
            <div class="sig-line">Entrega (Almacén)</div>
            <div class="sig-sub">{{ $dispatch['delivered_by_name'] ?: 'Nombre y firma' }}</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Recibe de conformidad (Salón)</div>
            <div class="sig-sub">{{ $dispatch['received_by_name'] ?: 'Nombre y firma' }}</div>
        </div>
    </div>
</div>
</body>
</html>
