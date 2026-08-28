<?php

return [
    'enabled' => filter_var(env('AUTOPARTES_AI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'model' => env('AUTOPARTES_AI_MODEL', 'gpt-5.6'),
    'max_batch' => (int) env('AUTOPARTES_AI_MAX_BATCH', 10),
    'max_daily_items' => (int) env('AUTOPARTES_AI_MAX_DAILY_ITEMS', 50),
    'timeout' => (int) env('AUTOPARTES_AI_TIMEOUT', 60),
    'max_retries' => (int) env('AUTOPARTES_AI_MAX_RETRIES', 3),
    'prompt_version' => env('AUTOPARTES_AI_PROMPT_VERSION', 'v1'),
    'title_max_chars' => (int) env('AUTOPARTES_AI_TITLE_MAX_CHARS', 60),
    'api_key' => env('OPENAI_API_KEY'),
];
