<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\MeliQuestion;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MeliQuestionService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    /**
     * Sincroniza las preguntas más recientes de una cuenta.
     *
     * @return array{received:int, saved:int, total:int}
     */
    public function syncAccount(MeliAccount $account, int $maxPages = 4): array
    {
        $this->assertLinked($account);

        $limit = 50;
        $offset = 0;
        $received = 0;
        $saved = 0;
        $total = 0;
        $itemCache = [];
        $maxPages = max(1, min(20, $maxPages));

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->get($account, '/questions/search', [
                'seller_id' => (string) $account->meli_user_id,
                'api_version' => 4,
                'sort_fields' => 'date_created',
                'sort_types' => 'DESC',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $questions = array_values(array_filter(
                (array) ($payload['questions'] ?? []),
                'is_array'
            ));

            $total = (int) ($payload['total'] ?? count($questions));
            $received += count($questions);

            foreach ($questions as $question) {
                $this->persistQuestion($account, $question, $itemCache);
                $saved++;
            }

            if (count($questions) < $limit || ($offset + $limit) >= $total) {
                break;
            }

            $offset += $limit;
        }

        return compact('received', 'saved', 'total');
    }

    public function syncQuestion(MeliAccount $account, string $questionId): MeliQuestion
    {
        $this->assertLinked($account);
        $id = rawurlencode(trim($questionId));

        if ($id === '') {
            throw new RuntimeException('El ID de la pregunta está vacío.');
        }

        $response = $this->get($account, "/questions/{$id}", [
            'api_version' => 4,
        ]);
        $question = $response->json();

        if (! is_array($question) || empty($question['id'])) {
            throw new RuntimeException('Mercado Libre no devolvió la pregunta solicitada.');
        }

        return $this->persistQuestion($account, $question);
    }

    public function answer(MeliQuestion $question, string $text): MeliQuestion
    {
        $question->loadMissing('meliAccount');
        $account = $question->meliAccount;

        if (! $account || (int) $account->user_id !== (int) $question->user_id) {
            throw new RuntimeException('La cuenta de Mercado Libre de la pregunta no está disponible.');
        }

        if (! $question->is_unanswered) {
            throw new RuntimeException('Esta pregunta ya no está pendiente de respuesta.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('La respuesta no puede estar vacía.');
        }

        $response = $this->post($account, '/answers', [
            'question_id' => ctype_digit($question->question_id)
                ? (int) $question->question_id
                : $question->question_id,
            'text' => $text,
        ]);

        $answer = $response->json();
        $answer = is_array($answer) ? $answer : [];

        $question->forceFill([
            'status' => 'ANSWERED',
            'answer_text' => (string) ($answer['text'] ?? $text),
            'answer_status' => strtoupper((string) ($answer['status'] ?? 'ACTIVE')),
            'answered_at' => $this->parseDate($answer['date_created'] ?? null) ?? now(),
            'last_synced_at' => now(),
        ])->save();

        try {
            return $this->syncQuestion($account, $question->question_id);
        } catch (\Throwable $e) {
            Log::warning('MELI QUESTIONS: respuesta enviada, pero no se pudo refrescar', [
                'question_id' => $question->question_id,
                'meli_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return $question->fresh();
        }
    }

    /** @return array<string, mixed> */
    public function responseTime(MeliAccount $account): array
    {
        $this->assertLinked($account);
        $sellerId = rawurlencode((string) $account->meli_user_id);
        $response = $this->get($account, "/users/{$sellerId}/questions/response_time");
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, array<string, mixed>>  $itemCache
     */
    private function persistQuestion(
        MeliAccount $account,
        array $question,
        array &$itemCache = []
    ): MeliQuestion {
        $questionId = trim((string) ($question['id'] ?? ''));
        $itemId = trim((string) ($question['item_id'] ?? ''));

        if ($questionId === '' || $itemId === '') {
            throw new RuntimeException('Mercado Libre devolvió una pregunta sin ID o sin publicación.');
        }

        $existing = MeliQuestion::query()
            ->where('meli_account_id', $account->id)
            ->where('question_id', $questionId)
            ->first();

        if (! array_key_exists($itemId, $itemCache)) {
            $itemCache[$itemId] = $this->resolveItemData($account, $itemId, $existing);
        }

        $item = $itemCache[$itemId];
        $answer = is_array($question['answer'] ?? null)
            ? $question['answer']
            : [];

        return MeliQuestion::query()->updateOrCreate(
            [
                'meli_account_id' => $account->id,
                'question_id' => $questionId,
            ],
            [
                'user_id' => $account->user_id,
                'item_id' => $itemId,
                'seller_id' => (string) ($question['seller_id'] ?? $account->meli_user_id),
                'buyer_id' => $this->buyerId($question),
                'status' => strtoupper((string) ($question['status'] ?? 'UNANSWERED')),
                'text' => (string) ($question['text'] ?? ''),
                'answer_text' => array_key_exists('text', $answer)
                    ? (string) $answer['text']
                    : null,
                'answer_status' => isset($answer['status'])
                    ? strtoupper((string) $answer['status'])
                    : null,
                'question_created_at' => $this->parseDate($question['date_created'] ?? null),
                'answered_at' => $this->parseDate($answer['date_created'] ?? null),
                'deleted_from_listing' => (bool) ($question['deleted_from_listing'] ?? false),
                'hold' => (bool) ($question['hold'] ?? false),
                'suspected_spam' => (bool) ($question['suspected_spam'] ?? false),
                'item_title' => $item['title'] ?? null,
                'item_thumbnail' => $item['thumbnail'] ?? null,
                'item_permalink' => $item['permalink'] ?? null,
                'item_price' => $item['price'] ?? null,
                'currency_id' => $item['currency_id'] ?? null,
                'sku' => $item['sku'] ?? null,
                'available_quantity' => $item['available_quantity'] ?? null,
                'raw' => $question,
                'last_synced_at' => now(),
            ]
        );
    }

    /** @return array<string, mixed> */
    private function resolveItemData(
        MeliAccount $account,
        string $itemId,
        ?MeliQuestion $existing
    ): array {
        if ($existing && filled($existing->item_title)) {
            return [
                'title' => $existing->item_title,
                'thumbnail' => $existing->item_thumbnail,
                'permalink' => $existing->item_permalink,
                'price' => $existing->item_price,
                'currency_id' => $existing->currency_id,
                'sku' => $existing->sku,
                'available_quantity' => $existing->available_quantity,
            ];
        }

        $publication = MeliPublication::query()
            ->where('user_id', $account->user_id)
            ->where('mlm', $itemId)
            ->where(function ($query) use ($account) {
                $query->where('meli_account_id', $account->id)
                    ->orWhereNull('meli_account_id');
            })
            ->orderByRaw('meli_account_id IS NULL')
            ->orderByDesc('id')
            ->first();

        if ($publication) {
            $local = $this->normalizeItem(
                MeliPublication::itemArrayFromRaw($publication->raw),
                $publication->sku,
                $publication->permalink
            );

            if (filled($local['title'] ?? null)) {
                return $local;
            }
        }

        try {
            $response = $this->get(
                $account,
                '/items/'.rawurlencode($itemId),
                ['attributes' => 'id,title,thumbnail,secure_thumbnail,pictures,permalink,price,currency_id,available_quantity,seller_custom_field,attributes,variations']
            );
            $remote = $response->json();

            return $this->normalizeItem(is_array($remote) ? $remote : []);
        } catch (\Throwable $e) {
            Log::warning('MELI QUESTIONS: no se pudo completar el producto', [
                'item_id' => $itemId,
                'meli_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'title' => 'Publicación '.$itemId,
                'thumbnail' => null,
                'permalink' => null,
                'price' => null,
                'currency_id' => null,
                'sku' => null,
                'available_quantity' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function normalizeItem(
        array $item,
        ?string $fallbackSku = null,
        ?string $fallbackPermalink = null
    ): array {
        $thumbnail = trim((string) ($item['secure_thumbnail'] ?? $item['thumbnail'] ?? ''));

        if ($thumbnail === '' && is_array($item['pictures'] ?? null)) {
            $first = $item['pictures'][0] ?? [];
            if (is_array($first)) {
                $thumbnail = trim((string) ($first['secure_url'] ?? $first['url'] ?? ''));
            }
        }

        return [
            'title' => trim((string) ($item['title'] ?? '')) ?: null,
            'thumbnail' => $thumbnail !== '' ? $thumbnail : null,
            'permalink' => trim((string) ($item['permalink'] ?? $fallbackPermalink ?? '')) ?: null,
            'price' => is_numeric($item['price'] ?? null) ? (float) $item['price'] : null,
            'currency_id' => trim((string) ($item['currency_id'] ?? '')) ?: null,
            'sku' => $this->resolveSku($item) ?? (filled($fallbackSku) ? $fallbackSku : null),
            'available_quantity' => is_numeric($item['available_quantity'] ?? null)
                ? max(0, (int) $item['available_quantity'])
                : null,
        ];
    }

    private function resolveSku(array $item): ?string
    {
        $sku = trim((string) ($item['seller_custom_field'] ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        foreach ((array) ($item['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute) || ($attribute['id'] ?? '') !== 'SELLER_SKU') {
                continue;
            }

            $sku = trim((string) ($attribute['value_name'] ?? $attribute['value_id'] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        foreach ((array) ($item['variations'] ?? []) as $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $sku = trim((string) ($variation['seller_custom_field'] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        return null;
    }

    private function buyerId(array $question): ?string
    {
        $id = $question['buyer_id'] ?? data_get($question, 'from.id');
        $id = trim((string) ($id ?? ''));

        return $id !== '' ? $id : null;
    }

    private function get(MeliAccount $account, string $path, array $query = []): Response
    {
        $response = Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->timeout(30)
            ->get(self::API_BASE.$path, $query);

        return $this->ensureSuccessful($response, 'consultar');
    }

    private function post(MeliAccount $account, string $path, array $payload): Response
    {
        $response = Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post(self::API_BASE.$path, $payload);

        return $this->ensureSuccessful($response, 'responder');
    }

    private function ensureSuccessful(Response $response, string $action): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $json = $response->json();
        $message = is_array($json)
            ? trim((string) ($json['message'] ?? $json['error_description'] ?? $json['error'] ?? ''))
            : '';

        if ($response->status() === 401) {
            $message = 'El token venció. Ejecuta la renovación de tokens y vuelve a intentar.';
        } elseif ($response->status() === 429) {
            $message = 'Mercado Libre limitó temporalmente las consultas. Intenta de nuevo en unos minutos.';
        } elseif ($message === '') {
            $message = 'Mercado Libre devolvió HTTP '.$response->status().'.';
        }

        throw new RuntimeException("No se pudo {$action} en Mercado Libre: {$message}");
    }

    private function assertLinked(MeliAccount $account): void
    {
        if (! filled($account->meli_user_id) || ! filled($account->access_token)) {
            throw new RuntimeException('La cuenta de Mercado Libre no está vinculada o no tiene token.');
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
