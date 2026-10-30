<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Genkit / Google AI Configuration
    |--------------------------------------------------------------------------
    |
    | API key dari environment variable GENKIT_API_KEY.
    | Model default: gemini-2.0-flash (cepat, hemat, cocok untuk procurement).
    |
    */

    'genkit_api_key' => env('GENKIT_API_KEY'),

    // Google Gemini REST API endpoint
    'endpoint' => env('AI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),

    // Model yang digunakan
    'model' => env('AI_MODEL', 'gemini-2.0-flash'),

    // Timeout request ke AI API (detik)
    'timeout' => env('AI_TIMEOUT', 30),

    // Max tokens output
    'max_tokens' => env('AI_MAX_TOKENS', 2048),

    /*
    |--------------------------------------------------------------------------
    | OpenAI / ChatGPT Configuration
    |--------------------------------------------------------------------------
    |
    | API Key dan Model OpenAI untuk demo bot vendor, negosiasi, & PO confirm.
    |
    */
    'openai_api_key' => env('OPENAI_API_KEY'),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    /*
    /*
    |--------------------------------------------------------------------------
    | Brave Search API
    |--------------------------------------------------------------------------
    |
    | Digunakan oleh AgenticProcurementService untuk mencari produk, harga
    | pasar, dan spesifikasi teknis secara real-time dari internet.
    |
    */
    'brave_search_api_key' => env('BRAVE_SEARCH_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Perplexity Agent API / Huntr Assist
    |--------------------------------------------------------------------------
    |
    | The key is resolved exclusively by Laravel. Do not place it in a Vite
    | environment variable: browser bundles must never receive provider keys.
    |
    */
    'perplexity' => [
        'api_key' => env('PERPLEXITY_API_KEY'),
        'endpoint' => env('PERPLEXITY_AGENT_ENDPOINT', 'https://api.perplexity.ai/v1/agent'),
        'effort' => env('PERPLEXITY_AGENT_EFFORT', 'low'),
        'timeout' => (int) env('PERPLEXITY_AGENT_TIMEOUT', 30),
        'max_output_tokens' => (int) env('PERPLEXITY_AGENT_MAX_OUTPUT_TOKENS', 700),
    ],

    'huntr_assist' => [
        'openai_fallback' => (bool) env('HUNTR_ASSIST_OPENAI_FALLBACK', true),
    ],
];
