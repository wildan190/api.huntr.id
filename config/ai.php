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
    'openai_model'   => env('OPENAI_MODEL', 'gpt-4o-mini'),
];
