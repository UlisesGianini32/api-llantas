<?php

namespace App\Services\Autopartes\Ai;

class AutomotivePartAiConfiguration
{
    public function isEnabled(): bool
    {
        return (bool) config('autopartes_ai.enabled', false);
    }

    public function hasApiKey(): bool
    {
        return trim((string) config('autopartes_ai.api_key')) !== '';
    }

    public function model(): string
    {
        return trim((string) config('autopartes_ai.model'));
    }

    public function promptVersion(): string
    {
        return trim((string) config('autopartes_ai.prompt_version'));
    }

    public function assertReady(): void
    {
        if (! $this->isEnabled()) {
            throw new AutomotivePartAiException(
                'La integración de IA para Autopartes está deshabilitada.',
                'integration_disabled',
            );
        }

        if (! $this->hasApiKey()) {
            throw new AutomotivePartAiException(
                'La credencial de OpenAI no está configurada.',
                'missing_api_key',
            );
        }

        if ($this->model() === '') {
            throw new AutomotivePartAiException(
                'No se configuró un modelo de OpenAI.',
                'missing_model',
            );
        }

        if ($this->promptVersion() === '') {
            throw new AutomotivePartAiException(
                'No se configuró una versión de prompt.',
                'missing_prompt_version',
            );
        }
    }

    public function publicSettings(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'configured' => $this->hasApiKey(),
            'model' => $this->model(),
            'prompt_version' => $this->promptVersion(),
            'max_batch' => max(1, (int) config('autopartes_ai.max_batch', 10)),
            'max_daily_items' => max(1, (int) config('autopartes_ai.max_daily_items', 50)),
        ];
    }
}
