import qz from 'qz-tray'
import { configureQzSecurity } from '@/lib/qzSecurity'
import { esAndroid, rawBtBase64Url } from '@/lib/rawBtPng'

export const THERMAL_PRINTER_STORAGE_KEY = 'pos_thermal_printer_name'
export const THERMAL_PAPER_STORAGE_KEY = 'pos_thermal_paper_width' // '80mm' | '58mm'

export function isQzActive() {
    return Boolean(qz?.websocket?.isActive?.())
}

export async function connectQz() {
    configureQzSecurity()
    if (!isQzActive()) {
        await qz.websocket.connect({ usingSecure: true })
    }
    return true
}

export async function getThermalPrinters() {
    try {
        await connectQz()
        const printers = await qz.printers.find()
        return Array.isArray(printers) ? printers : []
    } catch (e) {
        console.warn('QZ Tray no disponible:', e)
        return []
    }
}

export function openRawBtTicket(escposBase64) {
    if (!escposBase64) throw new Error('No hay datos ESC/POS para RawBT.')
    const url = rawBtBase64Url(escposBase64)
    window.location.href = url
}

export async function printViaQz(printerName, escposBase64) {
    await connectQz()
    const targetPrinter = printerName || (await qz.printers.getDefault())
    const config = qz.configs.create(targetPrinter, { copies: 1 })
    const data = [
        {
            type: 'raw',
            format: 'command',
            flavor: 'base64',
            data: escposBase64,
        },
    ]
    await qz.print(config, data)
    return true
}

export function printViaBrowser(ticketText, paperWidth = '80mm') {
    const printWindow = window.open('', '_blank', 'width=450,height=650')
    if (!printWindow) {
        window.alert('El navegador bloqueó la ventana emergente de impresión. Permite popups para esta página.')
        return
    }

    const cssWidth = paperWidth === '58mm' ? '58mm' : '80mm'
    const fontSize = paperWidth === '58mm' ? '10px' : '12px'

    const html = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Impresión de Ticket</title>
    <style>
        @page {
            size: ${cssWidth} auto;
            margin: 0;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: ${fontSize};
            line-height: 1.25;
            margin: 0;
            padding: 8px;
            color: #000;
            background: #fff;
            width: ${cssWidth};
        }
        pre {
            white-space: pre-wrap;
            word-break: break-all;
            margin: 0;
            font-family: inherit;
        }
    </style>
</head>
<body>
    <pre>${ticketText.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>
    <script>
        window.onload = function() {
            window.focus();
            window.print();
            setTimeout(function() { window.close(); }, 500);
        };
    </script>
</body>
</html>
    `

    printWindow.document.open()
    printWindow.document.write(html)
    printWindow.document.close()
}

export async function kickDrawer(printerName = null) {
    // 1. Si estamos en Android, mandar pulso a RawBT
    if (esAndroid()) {
        const pulseBase64 = 'G3AA' // \x1bp\x00\x19\xfa en base64
        openRawBtTicket(pulseBase64)
        return true
    }

    // 2. Si QZ Tray está conectado, mandar directo
    if (isQzActive()) {
        const pulseBase64 = 'G3AA'
        await printViaQz(printerName, pulseBase64)
        return true
    }

    // 3. Fallback: llamar endpoint
    const res = await fetch('/pos/drawer/open', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
    })
    return res.ok
}
