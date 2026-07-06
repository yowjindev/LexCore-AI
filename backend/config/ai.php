<?php

return [
    'driver' => env('AI_DRIVER', 'claude'),

    // Let document chat ground answers with live web results (provider-native
    // search tools). Analysis/risk jobs never use this — chat only.
    'web_search_enabled' => (bool) env('AI_WEB_SEARCH_ENABLED', true),

    'claude' => [
        'api_key'    => env('CLAUDE_API_KEY'),
        'model'      => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 4096),
    ],

    'gemini' => [
        'api_key'             => env('GEMINI_API_KEY'),
        'model'               => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'max_tokens'          => (int) env('GEMINI_MAX_TOKENS', 4096),
        'requests_per_minute' => (int) env('GEMINI_RPM', 5),
    ],

    'embedding' => [
        'driver' => env('AI_EMBEDDING_DRIVER', 'gemini'),
        'gemini' => [
            'api_key'    => env('GEMINI_API_KEY'),
            'model'      => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
            'dimensions' => 3072,
        ],
    ],
];
