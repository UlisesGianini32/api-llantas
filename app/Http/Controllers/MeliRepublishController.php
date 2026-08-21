<?php

namespace App\Http\Controllers;

use App\Models\Llanta;
use App\Models\MeliPublication;
use App\Models\ProductoCompuesto;
use App\Services\MeliPublishService;
use App\Services\MeliRepublishService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MeliRepublishController extends Controller
{
    private function resolveOfficialStoreId(Request $request): ?int
    {
        $mode = (string) $request->input('official_store_mode', 'tobeauty');

        return match ($mode) {
            'marketmax' => (int) (
                config('services.meli.official_store_id_marketmax')
                ?: config('services.meli.official_store_id')
                ?: 0
            ),
            'tobeauty' => (int) (
                config('services.meli.official_store_id_tobeauty')
                ?: config('services.meli.official_store_id')
                ?: 0
            ),
            'none' => null,
            default => (int) (config('services.meli.official_store_id') ?: 0),
        };
    }

    public function refreshPublication(
        MeliPublication $pub,
        Request $request,
        MeliPublishService $publishSvc
    ) {
        $user = $request->user();

        if ((int) $pub->user_id !== (int) $user->id) {
            abort(403);
        }

        try {
            $updated = $publishSvc->refreshStatus($user, (string) $pub->mlm, $pub->sku);

            $msg = "Status actualizado: {$updated->mlm}";
            if ($updated->status) {
                $msg .= " ({$updated->status})";
            }

            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            Log::warning('ML refreshPublication failed', [
                'pub_id' => $pub->id,
                'mlm'    => $pub->mlm,
                'err'    => $e->getMessage(),
            ]);

            return back()->with('error', 'No se pudo refrescar status: ' . $e->getMessage());
        }
    }

    public function showProductRepublishForm(
        string $ml,
        Request $request,
        MeliRepublishService $republishSvc
    ): Response|\Illuminate\Http\RedirectResponse {
        $user = $request->user();
        $ml = trim($ml);

        if ($ml === '') {
            abort(404);
        }

        try {
            $form = $republishSvc->getFormData($user, $ml);

            $requiredAttributes = is_array($form['requiredAttributes'] ?? null)
                ? $form['requiredAttributes']
                : [];

            $oldRequiredAttributes = old('required_attributes', []);
            if (!is_array($oldRequiredAttributes)) {
                $oldRequiredAttributes = [];
            }

            $requiredAttributeDefaults = [];
            foreach ($requiredAttributes as $attribute) {
                $attributeId = strtoupper(trim((string) ($attribute['id'] ?? '')));
                if ($attributeId === '') {
                    continue;
                }

                $requiredAttributeDefaults[$attributeId] = (string) (
                    $oldRequiredAttributes[$attributeId]
                    ?? $attribute['default_value_id']
                    ?? ''
                );
            }

            return Inertia::render('Ml/ProductRepublish', [
                'ml' => $form['ml'],
                'item' => $form['item'],
                'pub' => $form['pub'] ? $form['pub']->only(['id', 'sku', 'mlm']) : null,
                'isUserProduct' => $form['isUserProduct'],
                'hasCatalogProduct' => ! empty($form['item']['catalog_product_id'] ?? null),
                'currentUniversalCode' => (string) ($form['currentUniversalCode'] ?? ''),
                'requiredAttributes' => $requiredAttributes,
                'defaults' => [
                    'title' => (string) old('title', $form['defaultLabel']),
                    'price' => (float) old('price', $form['defaultPrice']),
                    'official_store_mode' => (string) old('official_store_mode', $form['defaultOfficialStoreMode']),
                    'copy_catalog' => (bool) old('copy_catalog', false),
                    'universal_code' => (string) old('universal_code', ''),
                    'required_attributes' => $requiredAttributeDefaults,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ML showProductRepublishForm failed', [
                'mlm' => $ml,
                'err' => $e->getMessage(),
            ]);

            return back()->with('error', 'No se pudo cargar la publicación original: ' . $e->getMessage());
        }
    }

    public function republishProductByMlm(
        string $ml,
        Request $request,
        MeliRepublishService $republishSvc
    ) {
        $user = $request->user();
        $ml = trim($ml);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:60'],
            'price' => ['required', 'numeric', 'min:1'],
            'copy_catalog' => ['nullable', 'boolean'],
            'official_store_mode' => ['required', 'string', 'in:marketmax,tobeauty,none'],
            'universal_code' => ['nullable', 'string', 'max:32'],
            'required_attributes' => ['nullable', 'array'],
            'required_attributes.*' => ['nullable', 'string', 'max:120'],
        ], [
            'title.required' => 'Debes capturar el nuevo título o nombre de familia.',
            'title.min' => 'El campo debe tener al menos 3 caracteres.',
            'title.max' => 'El campo no puede pasar de 60 caracteres.',
            'price.required' => 'Debes capturar el nuevo precio.',
            'price.numeric' => 'El precio debe ser numérico.',
            'price.min' => 'El precio debe ser mayor a 0.',
            'official_store_mode.required' => 'Debes elegir una tienda oficial.',
        ]);

        $officialStoreId = $this->resolveOfficialStoreId($request);
        $officialStoreMode = (string) $request->input('official_store_mode', 'tobeauty');

        if ($officialStoreMode !== 'none' && !$officialStoreId) {
            return back()
                ->withInput()
                ->with('error', 'Falta configurar la tienda oficial seleccionada en services.php / .env.');
        }

        try {
            $newPub = $republishSvc->republishProductByMlm(
                $user,
                $ml,
                (string) $data['title'],
                (float) $data['price'],
                [
                    'keep_catalog'      => (bool) $request->boolean('copy_catalog'),
                    'official_store_id' => $officialStoreId,
                    'universal_code'    => trim((string) ($data['universal_code'] ?? '')),
                    'attribute_overrides' => is_array($data['required_attributes'] ?? null)
                        ? $data['required_attributes']
                        : [],
                ]
            );

            return redirect()
                ->route('producto.index')
                ->with('success', "Publicación republicada correctamente. Nuevo MLM: {$newPub->mlm}");
        } catch (\Throwable $e) {
            Log::warning('ML republishProductByMlm failed', [
                'mlm' => $ml,
                'err' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'No se pudo republicar: ' . $e->getMessage());
        }
    }

    public function republishLlantaById($id, Request $request)
    {
        $user = $request->user();
        $llanta = Llanta::findOrFail($id);

        $pub = MeliPublication::where('user_id', $user->id)
            ->where('sku', $llanta->sku)
            ->latest('id')
            ->first();

        if (!$pub || !$pub->mlm) {
            return back()->with('error', 'No existe una publicación previa para esta llanta.');
        }

        return redirect()->route('producto.ml.republish.form', ['ml' => $pub->mlm]);
    }

    public function republishCompuestoById($id, Request $request)
    {
        $user = $request->user();
        $compuesto = ProductoCompuesto::findOrFail($id);

        $pub = MeliPublication::where('user_id', $user->id)
            ->where('sku', (string) $compuesto->sku)
            ->latest('id')
            ->first();

        if (!$pub || !$pub->mlm) {
            return back()->with('error', 'No existe una publicación previa para este compuesto.');
        }

        return redirect()->route('producto.ml.republish.form', ['ml' => $pub->mlm]);
    }
}