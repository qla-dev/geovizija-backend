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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Off: quizzes come from the Claude agent (POST /api/quizzes); a missing day shows the latest quiz.
    // On: the first visitor of a day without a quiz triggers OpenRouter generation.
    'quiz' => [
        'auto_generate' => (bool) env('QUIZ_AUTO_GENERATE', false),
    ],

    // Secret that pasted JSON must contain for POST /api/publish. Only its bcrypt hash is stored;
    // override with PUBLISH_SECRET_HASH (a bcrypt hash) to change it without a deploy.
    'publish' => [
        'secret_hash' => env('PUBLISH_SECRET_HASH', '$2y$10$WfXjerIaYpjjs4JhrH4p2OKG5dY3AA5rdL6gm93KWwDFiEDsYvEB6'),
    ],

    'admin' => [
        'token' => env('ADMIN_API_TOKEN'),
    ],

    // Facebook Page sharing of new articles (App\Services\MetaPublisher); off until both are set.
    // META_PAGE_TOKEN is a long-lived Page access token with pages_manage_posts.
    'meta' => [
        'page_id' => env('META_PAGE_ID'),
        'page_token' => env('META_PAGE_TOKEN'),
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'site_url' => env('SITE_URL', 'https://geovizija.com'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        'fallback_model' => env('OPENROUTER_FALLBACK_MODEL'),
        'image_model' => env('OPENROUTER_IMAGE_MODEL', 'google/gemini-2.5-flash-image'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions'),
    ],

];
