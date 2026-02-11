<?php

return [
    // Active AI provider: 'openai' or 'anthropic'
    'default_provider' => env('NORMALIZER_PROVIDER', 'openai'),

    // OpenAI
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 10),
        'max_retries' => (int) env('OPENAI_MAX_RETRIES', 2),
    ],

    // Anthropic
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 10),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 2),
    ],

    // Libpostal (optional microservice)
    'libpostal' => [
        'enabled' => (bool) env('LIBPOSTAL_ENABLED', false),
        'url' => env('LIBPOSTAL_URL', 'http://localhost:5000'),
        'timeout' => (int) env('LIBPOSTAL_TIMEOUT', 3),
    ],

    // Cache
    'cache' => [
        'enabled' => (bool) env('NORMALIZER_CACHE_ENABLED', true),
        'ttl_days' => (int) env('NORMALIZER_CACHE_TTL_DAYS', 30),
        'prefix' => 'addr:',
    ],

    // Limits
    'batch_max_size' => 50,

    // Logs
    'log_retention_days' => 30,

    // Postcode validation
    'validate_postal_codes' => (bool) env('NORMALIZER_VALIDATE_POSTCODES', true),
];
