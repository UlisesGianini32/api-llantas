<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class QzTrayController extends Controller
{
    public function certificate(): Response
    {
        $path = storage_path('app/private/qz/digital-certificate.txt');

        if (! is_file($path) || ! is_readable($path)) {
            abort(500, 'No se encontró el certificado público de QZ Tray.');
        }

        $certificate = file_get_contents($path);

        if ($certificate === false || trim($certificate) === '') {
            abort(500, 'El certificado público de QZ Tray está vacío.');
        }

        return response($certificate, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function sign(Request $request): Response
    {
        $validated = $request->validate([
            'request' => ['required', 'string'],
        ]);

        $privateKeyPath = storage_path('app/private/qz/private-key.pem');

        if (! is_file($privateKeyPath) || ! is_readable($privateKeyPath)) {
            abort(500, 'No se encontró la llave privada de QZ Tray.');
        }

        $privateKeyContents = file_get_contents($privateKeyPath);

        if ($privateKeyContents === false || trim($privateKeyContents) === '') {
            abort(500, 'La llave privada de QZ Tray está vacía.');
        }

        $privateKey = openssl_pkey_get_private($privateKeyContents);

        if ($privateKey === false) {
            throw new RuntimeException('No se pudo cargar la llave privada de QZ Tray.');
        }

        $signature = '';

        try {
            $signed = openssl_sign(
                $validated['request'],
                $signature,
                $privateKey,
                OPENSSL_ALGO_SHA512
            );
        } finally {
            if (is_resource($privateKey)) {
                openssl_free_key($privateKey);
            }
        }

        if (! $signed) {
            throw new RuntimeException('No se pudo firmar la solicitud de QZ Tray.');
        }

        return response(base64_encode($signature), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}