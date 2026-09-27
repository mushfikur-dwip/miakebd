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

    // Meta (Facebook) Pixel. Optional: App\Support\MetaPixel also picks the id
    // up from a `site_meta_pixel_id` setting, or out of the snippet pasted into
    // Admin -> Analytics.
    'meta_pixel' => [
        'id' => env('META_PIXEL_ID'),

        // Conversions API. With no token the server sends nothing and the
        // browser pixel carries on alone, so this is safe to leave empty.
        'token'       => env('META_CAPI_TOKEN'),
        // Set while checking Events Manager -> Test events, then remove.
        'test_code'   => env('META_CAPI_TEST_CODE'),
        'api_version' => env('META_API_VERSION', 'v21.0'),

        // Which product field the Facebook catalogue is keyed on: 'id' or
        // 'sku'. Dynamic product ads only match when this agrees with the
        // feed's id column. The browser and the server both read it here, so
        // the two can never disagree.
        'content_id'  => env('META_CONTENT_ID', 'id'),
    ],

    // TikTok Pixel. Optional: App\Support\TikTokPixel also reads the id out of
    // a snippet pasted into Admin -> Analytics. Its events reuse the Meta
    // settings above for currency and content ids, so both catalogues match.
    'tiktok_pixel' => [
        'id' => env('TIKTOK_PIXEL_ID'),

        // Events API. With no token the server sends nothing and the browser
        // pixel carries on alone, so this is safe to leave empty.
        'token'     => env('TIKTOK_EVENTS_TOKEN'),
        // Set while checking Events Manager -> Test events, then remove.
        'test_code' => env('TIKTOK_EVENTS_TEST_CODE'),
    ],

];
