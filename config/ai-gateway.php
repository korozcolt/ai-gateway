<?php

use Korbytes\AiGateway\Providers\AnthropicProvider;
use Korbytes\AiGateway\Providers\FakeAiProvider;
use Korbytes\AiGateway\Providers\GeminiProvider;
use Korbytes\AiGateway\Providers\OpenAiCompatibleProvider;
use Korbytes\AiGateway\Providers\OpenAiProvider;

/*
 | Korbytes AI Gateway.
 |
 | Provider connections (URL, token, models, prices, request options) live in the DATABASE (table
 | ai_providers) with the token encrypted by a dedicated key. Nothing about individual providers
 | goes in .env: the only secret outside the DB is the key that encrypts those tokens.
 | Generate it with `php artisan ai:generate-key`.
 */
return [
    // Closed list of drivers a connection can use. Adding one = a class implementing AiProviderInterface + a line here.
    // Anthropic (Claude) and OpenAI (ChatGPT) have their own driver; OpenRouter, NVIDIA Build, Azure v1, Mistral, Groq... use `openai_compatible`.
    'drivers' => [
        'anthropic' => AnthropicProvider::class,
        'fake' => FakeAiProvider::class,
        'gemini' => GeminiProvider::class,
        'openai' => OpenAiProvider::class,
        'openai_compatible' => OpenAiCompatibleProvider::class,
    ],

    'credentials' => [
        'current_key_id' => env('AI_CREDENTIALS_KEY_ID'),
        'keys' => array_filter([
            env('AI_CREDENTIALS_KEY_ID') => env('AI_CREDENTIALS_KEY'),
        ], fn ($key, $id) => filled($id) && filled($key), ARRAY_FILTER_USE_BOTH),
        // JSON object {"<key id>": "base64:..."} with retired keys that still decrypt old rows (rotation).
        'retired_keys' => json_decode((string) env('AI_CREDENTIALS_RETIRED_KEYS', '[]'), true) ?: [],
        // Other application keys (e.g. a chat encryption key) the credentials key must differ from.
        'distinct_from' => [],
    ],

    'usage' => [
        // Queue used to write the usage ledger (null = the default queue).
        'queue' => null,
    ],

    // JSON file with the connections to seed (tokens included, encrypted on import). Keep it OUT of git.
    'seed_file' => database_path('seeders/data/ai-providers.json'),
];
