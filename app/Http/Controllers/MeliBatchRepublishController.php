<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\Product;
use App\Models\User;
use App\Services\MeliRepublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MeliBatchRepublishController extends Controller
{
    public function store(Request $request, MeliRepublishService $republishService): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:50'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'destination_account_id' => ['required', 'integer'],
            'gtin_overrides' => ['nullable', 'array'],
            'gtin_overrides.*' => ['nullable', 'string', 'regex:/^(?:\d{8}|\d{12,14})$/'],
        ], [
            'product_ids.required' => 'Selecciona al menos un producto.',
            'product_ids.min' => 'Selecciona al menos un producto.',
            'product_ids.max' => 'Puedes procesar máximo 50 productos por lote.',
            'product_ids.*.exists' => 'Uno de los productos seleccionados ya no existe.',
            'destination_account_id.required' => 'Selecciona la cuenta secundaria.',
            'gtin_overrides.*.regex' => 'El GTIN debe contener únicamente 8, 12, 13 o 14 dígitos.',
        ]);

        /** @var User $ownerUser */
        $ownerUser = $request->user();

        $sourceAccount = $ownerUser->meliAccounts()
            ->where('is_default', true)
            ->first()
            ?? $ownerUser->meliAccounts()->orderBy('id')->first();

        if (! $sourceAccount) {
            throw ValidationException::withMessages([
                'source_account' => 'No existe una cuenta principal de Mercado Libre vinculada.',
            ]);
        }

        $destinationAccount = $ownerUser->meliAccounts()
            ->whereKey((int) $validated['destination_account_id'])
            ->first();

        if (! $destinationAccount) {
            throw ValidationException::withMessages([
                'destination_account_id' => 'La cuenta secundaria seleccionada no existe.',
            ]);
        }

        if ((int) $destinationAccount->id === (int) $sourceAccount->id) {
            throw ValidationException::withMessages([
                'destination_account_id' => 'La cuenta destino no puede ser la cuenta principal.',
            ]);
        }

        if (empty($sourceAccount->access_token)) {
            throw ValidationException::withMessages([
                'source_account' => 'La cuenta principal no tiene un access token. Reautorízala.',
            ]);
        }

        if (empty($destinationAccount->access_token)) {
            throw ValidationException::withMessages([
                'destination_account_id' => 'La cuenta secundaria no tiene un access token. Reautorízala.',
            ]);
        }

        $sourceApiUser = $this->makeApiUser($ownerUser, $sourceAccount);
        $destinationApiUser = $this->makeApiUser($ownerUser, $destinationAccount);

        $products = Product::query()
            ->whereIn('id', $validated['product_ids'])
            ->get()
            ->keyBy('id');

        $successful = [];
        $failed = [];

        foreach ($validated['product_ids'] as $productId) {
            /** @var Product|null $product */
            $product = $products->get((int) $productId);

            if (! $product) {
                $failed[] = $this->failedRow(
                    (int) $productId,
                    null,
                    null,
                    'Producto no encontrado',
                    'product_not_found',
                    'El producto ya no existe en la base de datos.'
                );
                continue;
            }

            $sourceMlm = strtoupper(trim((string) $product->ml));
            if ($sourceMlm === '') {
                $failed[] = $this->failedRow(
                    $product->id,
                    null,
                    (string) $product->sku,
                    (string) $product->name,
                    'missing_mlm',
                    'El producto no tiene un MLM asociado.'
                );
                continue;
            }

            $gtinOverride = trim((string) (
                $validated['gtin_overrides'][(string) $product->id]
                ?? $validated['gtin_overrides'][$product->id]
                ?? ''
            ));

            try {
                $result = $republishService->republishProductBetweenAccounts(
                    $ownerUser,
                    $sourceApiUser,
                    $destinationApiUser,
                    $sourceMlm,
                    [
                        'title' => (string) ($product->name ?: $sourceMlm),
                        'price' => (float) $product->price,
                        'sku' => (string) ($product->sku ?: $sourceMlm),
                        'brand' => trim((string) $product->brand),
                        'line' => $this->guessProductLine(
                            (string) $product->name,
                            (string) $product->brand
                        ),
                        'model' => (string) ($product->name ?: $sourceMlm),
                        'keep_catalog' => false,
                        'official_store_id' => null,
                        'universal_code' => $gtinOverride !== '' ? $gtinOverride : null,
                    ]
                );

                $successful[] = [
                    'product_id' => $product->id,
                    'source_mlm' => $sourceMlm,
                    'destination_mlm' => $result['destination_mlm'],
                    'sku' => (string) $product->sku,
                    'title' => $result['title'],
                    'status' => $result['status'],
                    'permalink' => $result['permalink'],
                    'description_warning' => $result['description_warning'],
                ];
            } catch (\Throwable $exception) {
                $formattedError = $republishService->formatMlException($exception);

                Log::warning('ML batch republish product failed', [
                    'user_id' => $ownerUser->id,
                    'product_id' => $product->id,
                    'source_mlm' => $sourceMlm,
                    'source_meli_user_id' => $sourceAccount->meli_user_id,
                    'destination_meli_user_id' => $destinationAccount->meli_user_id,
                    'error' => $exception->getMessage(),
                ]);

                $failed[] = [
                    'product_id' => $product->id,
                    'source_mlm' => $sourceMlm,
                    'sku' => (string) $product->sku,
                    'title' => (string) ($product->name ?: $sourceMlm),
                    'gtin_submitted' => $gtinOverride !== '' ? $gtinOverride : null,
                    'needs_gtin' => $this->errorNeedsGtin($formattedError),
                    'error' => $formattedError,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'El lote terminó de procesarse.',
            'source_account' => $this->accountData($sourceAccount),
            'destination_account' => $this->accountData($destinationAccount),
            'summary' => [
                'total' => count($validated['product_ids']),
                'successful' => count($successful),
                'failed' => count($failed),
            ],
            'successful' => $successful,
            'failed' => $failed,
        ]);
    }

    private function makeApiUser(User $ownerUser, MeliAccount $account): User
    {
        /** @var User $apiUser */
        $apiUser = clone $ownerUser;
        $apiUser->forceFill([
            'meli_id' => $account->meli_user_id,
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_at' => $account->expires_at,
            'official_store_id' => $account->official_store_id,
        ]);
        $apiUser->setAttribute('id', $ownerUser->id);

        return $apiUser;
    }

    private function accountData(MeliAccount $account): array
    {
        return [
            'id' => $account->id,
            'meli_user_id' => (string) $account->meli_user_id,
            'nickname' => $account->nickname,
        ];
    }

    private function failedRow(
        int $productId,
        ?string $sourceMlm,
        ?string $sku,
        string $title,
        string $code,
        string $message
    ): array {
        return [
            'product_id' => $productId,
            'source_mlm' => $sourceMlm,
            'sku' => $sku,
            'title' => $title,
            'error' => [
                'http_status' => null,
                'code' => $code,
                'message' => $message,
                'causes' => [],
            ],
        ];
    }

    private function guessProductLine(string $title, string $brand): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $title));
        $brand = trim((string) preg_replace('/\s+/', ' ', $brand));

        if ($title === '') {
            return '';
        }

        $line = $title;
        if ($brand !== '') {
            $line = trim((string) preg_replace(
                '/\b' . preg_quote($brand, '/') . '\b/iu',
                '',
                $line,
                1
            ));
        }

        $line = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:ml|l|g|gr|kg|oz|pzas?|piezas?)\b/iu', '', $line);
        $line = preg_replace('/\b(?:shampoo|acondicionador|mascarilla|aceite|ampolleta|kit|tratamiento|crema|spray|cera|gel)\b/iu', '', $line);
        $line = trim((string) preg_replace('/\s+/', ' ', $line));

        if ($line === '') {
            return $brand !== '' ? $brand : 'Línea general';
        }

        return mb_substr($line, 0, 60);
    }

    private function errorNeedsGtin(array $error): bool
    {
        $parts = [
            (string) ($error['code'] ?? ''),
            (string) ($error['message'] ?? ''),
        ];

        foreach (($error['causes'] ?? []) as $cause) {
            if (! is_array($cause)) {
                continue;
            }

            $parts[] = (string) ($cause['code'] ?? '');
            $parts[] = (string) ($cause['message'] ?? '');
        }

        $haystack = mb_strtolower(implode(' ', $parts));

        return str_contains($haystack, 'invalid_product_identifier')
            || str_contains($haystack, 'product identifier')
            || str_contains($haystack, 'gtin')
            || str_contains($haystack, 'código universal')
            || str_contains($haystack, 'codigo universal')
            || str_contains($haystack, 'ean')
            || str_contains($haystack, 'upc');
    }

}
