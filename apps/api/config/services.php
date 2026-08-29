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
    | Server-side OAuth only. The client secret lives here on the API host and
    | must never be exposed to the Next.js app or any NEXT_PUBLIC_* variable.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),

        /*
         * Least privilege:
         *   drive.file      — only files this app created, not the whole Drive
         *   youtube.upload  — upload only
         *   youtube.readonly— read back the channel so we can verify it
         */
        'scopes' => [
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
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
