<?php

namespace App\Services\Autopartes\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiResponsesClient
{
    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function create(array $payload): Response
    {
        return Http::withToken((string) config('autopartes_ai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) config('autopartes_ai.timeout', 60)))
            ->post(self::ENDPOINT, $payload);
    }
}
