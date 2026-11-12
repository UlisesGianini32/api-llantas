import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

const REPO_ROOT = path.resolve(import.meta.dirname, '..', '..')

test('ReceiptShow imports CameraBarcodeScanner, ReceiptItemModal, and autopartesHttp', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptShow.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes("import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'"))
    assert.ok(source.includes("import ReceiptItemModal from './ReceiptItemModal'"))
    assert.ok(source.includes("import { autopartesHttp, formatAutopartesHttpError } from '@/lib/autopartesHttp'"))
})

test('ReceiptShow includes visible mobile-adaptive "Escanear con cámara" button', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptShow.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes('Escanear con cámara'))
    assert.ok(source.includes('setIsCameraOpen(true)'))
    assert.ok(source.includes('CameraBarcodeScanner'))
})

test('ReceiptShow debounces HID scanner input on Enter and guards against duplicate lookups', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptShow.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes("handleScanKeyDown"))
    assert.ok(source.includes("if (e.key === 'Enter')"))
    assert.ok(source.includes("e.preventDefault()"))
    assert.ok(source.includes("isLookingUp || isAddingItem || isModalOpen"))
})

test('ReceiptItemModal has quick addition buttons: +1, +5, +10, +12, +24, +48, +100', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptItemModal.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes('[1, 5, 10, 12, 24, 48, 100]'))
    assert.ok(source.includes('+{n}'))
    assert.ok(source.includes('addQuickQuantity'))
})

test('ReceiptItemModal enforces destination "Almacén Hermosillo" and physical location HMO-REC-01', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptItemModal.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes('Almacén Hermosillo'))
    assert.ok(source.includes('HMO-REC-01'))
    assert.ok(source.includes('locationCode'))
    assert.ok(source.includes('locationName'))
})

test('ReceiptItemModal blocks ambiguous identities and unreceivable items', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptItemModal.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes("candidate.identity_status !== 'ambiguous'"))
    assert.ok(source.includes('isReceivable'))
    assert.ok(source.includes('Autoparte con recepción restringida'))
    assert.ok(source.includes('disabled={isSubmitting || !isReceivable'))
})

test('ReceiptItemModal displays Excel documentary quantity clearly differentiated from physical count', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'ReceiptItemModal.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes('Cantidad documental Excel'))
    assert.ok(source.includes('candidate.excel_qty'))
    assert.ok(source.includes('Referencia documental (no altera stock)'))
    assert.ok(source.includes('Stock actual'))
})

test('CameraBarcodeScanner supports isPaused prop to freeze camera stream while modal is open', () => {
    const filePath = path.join(REPO_ROOT, 'resources', 'js', 'Components', 'CameraBarcodeScanner.jsx')
    const source = fs.readFileSync(filePath, 'utf8')

    assert.ok(source.includes('isPaused = false'))
    assert.ok(source.includes('scannerRef.current.pause(true)'))
    assert.ok(source.includes('scannerRef.current.resume()'))
})
