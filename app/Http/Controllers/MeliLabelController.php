<?php

namespace App\Http\Controllers;

use App\Models\MeliLabelPrint;
use App\Services\MercadoLibre\MeliLabelParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeliLabelController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $request->string('type')->toString();
        if (! in_array($type, [MeliLabelPrint::TYPE_PRODUCT, MeliLabelPrint::TYPE_PACKAGE], true)) {
            $type = '';
        }

        $history = MeliLabelPrint::query()
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (MeliLabelPrint $print): array => $this->serializePrint($print));

        return Inertia::render('MercadoLibre/Etiquetas', [
            'history' => $history,
            'historyFilter' => $type,
        ]);
    }

    public function process(Request $request, MeliLabelParser $parser): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:txt', 'mimetypes:text/plain,application/octet-stream', 'max:5120'],
        ], [
            'file.required' => 'Selecciona un archivo TXT.',
            'file.extensions' => 'Solo se permiten archivos .txt.',
            'file.mimetypes' => 'El archivo debe contener texto ZPL.',
            'file.max' => 'El archivo no debe superar 5 MB.',
        ]);

        $file = $request->file('file');
        $content = $file->get();
        $filename = Str::limit($file->getClientOriginalName(), 255, '');
        $type = $parser->detectType($filename, $content);

        if ($type === null) {
            throw ValidationException::withMessages([
                'file' => 'No fue posible identificar el tipo de etiquetas de Mercado Libre.',
            ]);
        }

        $labels = $parser->parse($content, $type);
        $quantities = $parser->printQuantities($labels);
        $blockCount = count($labels);
        $physicalLabelCount = array_sum($quantities);
        $fileHash = $parser->calculateHash($content);
        $shipmentId = $parser->extractShipmentId($filename);
        $previousPrint = MeliLabelPrint::query()
            ->where('file_hash', $fileHash)
            ->where('type', $type)
            ->where('status', MeliLabelPrint::STATUS_PRINTED)
            ->latest('printed_at')
            ->first();

        $print = MeliLabelPrint::query()->create([
            'shipment_id' => $shipmentId,
            'type' => $type,
            'original_filename' => $filename,
            'file_hash' => $fileHash,
            // Keep the legacy column as block count; explicit columns remove ambiguity.
            'labels_count' => $blockCount,
            'zpl_blocks_count' => $blockCount,
            'physical_labels_count' => $physicalLabelCount,
            'status' => MeliLabelPrint::STATUS_ANALYZED,
            'created_by' => $request->user()->getKey(),
        ]);

        return response()->json([
            'success' => true,
            'print_id' => $print->getKey(),
            'filename' => $filename,
            'shipment_id' => $shipmentId,
            'type' => $type,
            'block_count' => $blockCount,
            'count' => $physicalLabelCount,
            'physical_label_count' => $physicalLabelCount,
            'quantities' => $quantities,
            'file_hash' => $fileHash,
            'fingerprint' => $fileHash,
            'previous_print' => $previousPrint ? $this->serializePrint($previousPrint) : null,
            'encoding' => 'base64',
            'labels' => array_map('base64_encode', $labels),
        ])->header('Cache-Control', 'no-store');
    }

    public function record(Request $request, MeliLabelPrint $labelPrint): JsonResponse
    {
        abort_unless($labelPrint->created_by === $request->user()->getKey(), 404);

        $validated = $request->validate([
            'printer_name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in([MeliLabelPrint::STATUS_PRINTED, MeliLabelPrint::STATUS_FAILED])],
            'confirmed_reprint' => ['sometimes', 'boolean'],
            'error_message' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($labelPrint->status === MeliLabelPrint::STATUS_PRINTED) {
            return response()->json([
                'success' => true,
                'print' => $this->serializePrint($labelPrint),
            ]);
        }

        if ($validated['status'] === MeliLabelPrint::STATUS_PRINTED) {
            $wasPrintedBefore = MeliLabelPrint::query()
                ->whereKeyNot($labelPrint->getKey())
                ->where('file_hash', $labelPrint->file_hash)
                ->where('type', $labelPrint->type)
                ->where('status', MeliLabelPrint::STATUS_PRINTED)
                ->exists();

            if ($wasPrintedBefore && ! ($validated['confirmed_reprint'] ?? false)) {
                throw ValidationException::withMessages([
                    'confirmed_reprint' => 'Debes confirmar explícitamente la reimpresión de este archivo.',
                ]);
            }

            $labelPrint->forceFill([
                'printer_name' => $validated['printer_name'],
                'status' => MeliLabelPrint::STATUS_PRINTED,
                'error_message' => null,
                'printed_at' => now(),
            ])->save();
        } else {
            $labelPrint->forceFill([
                'printer_name' => $validated['printer_name'],
                'status' => MeliLabelPrint::STATUS_FAILED,
                'error_message' => Str::limit(strip_tags($validated['error_message'] ?? 'Error de QZ no especificado.'), 1000, ''),
                'printed_at' => null,
            ])->save();
        }

        return response()->json([
            'success' => true,
            'print' => $this->serializePrint($labelPrint->fresh()),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function serializePrint(MeliLabelPrint $print): array
    {
        return [
            'id' => $print->getKey(),
            'shipment_id' => $print->shipment_id,
            'type' => $print->type,
            'original_filename' => $print->original_filename,
            'labels_count' => $print->labels_count,
            'zpl_blocks_count' => $print->zpl_blocks_count ?? $print->labels_count,
            'physical_labels_count' => $print->physical_labels_count ?? $print->labels_count,
            'printer_name' => $print->printer_name,
            'status' => $print->status,
            'printed_at' => $print->printed_at?->toIso8601String(),
            'created_at' => $print->created_at?->toIso8601String(),
        ];
    }
}
