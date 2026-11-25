<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // AI provider adapters (App\Domain\Ai). Keys live encrypted in ai_providers, never here.
    // Base URLs are fixed and trusted on purpose: no env override.
    'ai' => [
        'connect_timeout' => 10,
        'timeout' => 540, // < ExplainWorkflowJob::$timeout (600) < queue retry_after (1900)
        'test_timeout' => 20,
        'anthropic_url' => 'https://api.anthropic.com',
        'openai_url' => 'https://api.openai.com',
        'gemini_url' => 'https://generativelanguage.googleapis.com',
    ],

];
