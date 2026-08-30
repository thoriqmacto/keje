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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    /*
    |--------------------------------------------------------------------------
    | Google (Drive + YouTube Data API v3)
    |--------------------------------------------------------------------------
    |
    | Server-side OAuth only. These client secrets live here on the API host
    | and must never be exposed to the Next.js app or any NEXT_PUBLIC_*
    | variable.
    |
    | TWO separate OAuth clients, deliberately. Google rejects any consent
    | request that mixes the YouTube scopes with drive.file ("scopes that
    | cannot be requested together"), so YouTube and Drive are authorized
    | independently and never appear in the same authorization request. The
    | scopes themselves live on App\Enums\GoogleService, which is the single
    | source of truth for what each flow may ask for.
    |
    */

    'google' => [
        'clients' => [
            'youtube' => [
                'client_id' => env('GOOGLE_YOUTUBE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_YOUTUBE_CLIENT_SECRET'),
                'redirect_uri' => env('GOOGLE_YOUTUBE_REDIRECT_URI'),
            ],
            'drive' => [
                'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
                'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
            ],
        ],
    ],

    'drive' => [
        // Optional. When empty the app creates/reuses a folder by name.
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'folder_name' => env('GOOGLE_DRIVE_FOLDER_NAME', 'Keje YouTube Outputs'),
        // Resumable upload chunk size. Must be a multiple of 256 KiB.
        'chunk_size' => (int) env('GOOGLE_DRIVE_CHUNK_SIZE', 8 * 1024 * 1024),
    ],

    'youtube' => [
        // Uploads are refused unless the connected channel matches this.
        'expected_channel_id' => env('YOUTUBE_EXPECTED_CHANNEL_ID'),
        'chunk_size' => (int) env('YOUTUBE_CHUNK_SIZE', 8 * 1024 * 1024),
        'default_category_id' => env('YOUTUBE_DEFAULT_CATEGORY_ID', '27'),
    ],

];
