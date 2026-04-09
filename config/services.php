<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Services Configuration
    |--------------------------------------------------------------------------
    */
    'document' => [
        'disk' => env('DOCUMENT_DISK', 'local'),
        'storage_path' => env('DOCUMENT_STORAGE_PATH', 'documents'),
        'queue' => env('DOCUMENT_INGESTION_QUEUE', 'default'),
        'chunk_size' => (int) env('DOCUMENT_CHUNK_SIZE', 1200),
        'chunk_overlap' => (int) env('DOCUMENT_CHUNK_OVERLAP', 200),
        'ingestion_retry' => [
            'tries' => (int) env('DOCUMENT_INGESTION_RETRY_TRIES', 3),
            'backoff_seconds' => array_values(array_map(
                fn ($value) => (int) trim($value),
                array_filter(explode(',', (string) env('DOCUMENT_INGESTION_RETRY_BACKOFF', '10,30,120')), fn ($value) => trim($value) !== '')
            )),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'provider' => env('EMBEDDING_PROVIDER', env('LLM_PROVIDER', 'openai')),
        'model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'api_key' => env('EMBEDDING_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('EMBEDDING_BASE_URL', env('LLM_BASE_URL', 'https://api.openai.com/v1')),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'openai'),
        'model' => env('LLM_MODEL', 'gpt-4o-mini'),
        'base_url' => env('LLM_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('LLM_API_KEY', env('OPENAI_API_KEY')),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Retrieval Configuration
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'max_chunks' => (int) env('RAG_MAX_CHUNKS', 5),
        'max_context_chars' => (int) env('RAG_MAX_CONTEXT_CHARS', 12000),
        'max_context_tokens' => (int) env('RAG_MAX_CONTEXT_TOKENS', 3000),
        'min_similarity' => (float) env('RAG_MIN_SIMILARITY', 0.7),
        'low_confidence_similarity' => (float) env('RAG_LOW_CONFIDENCE_SIMILARITY', 0.55),
        'retry' => [
            'attempts' => (int) env('RAG_RETRY_ATTEMPTS', 3),
            'backoff_ms' => array_values(array_map(
                fn ($value) => (int) trim($value),
                array_filter(explode(',', (string) env('RAG_RETRY_BACKOFF_MS', '200,600,1500')), fn ($value) => trim($value) !== '')
            )),
        ],
        'cost' => [
            'input_per_1m_tokens' => (float) env('RAG_COST_INPUT_PER_1M_TOKENS', 0.15),
            'output_per_1m_tokens' => (float) env('RAG_COST_OUTPUT_PER_1M_TOKENS', 0.60),
        ],
    ],

];
