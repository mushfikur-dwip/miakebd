<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // The storefront and admin are served from APP_URL itself (www and http
    // both 301 there), so no other site needs to read these responses. Extra
    // origins, if one is ever needed: CORS_ALLOWED_ORIGINS=https://a,https://b
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        (static function (): array {
            $url  = (string) env('APP_URL', '');
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) {
                return [];
            }
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            $bare   = preg_replace('/^www\./i', '', $host);
            $port   = parse_url($url, PHP_URL_PORT);
            $suffix = $port ? ':' . $port : '';

            return [$scheme . '://' . $bare . $suffix, $scheme . '://www.' . $bare . $suffix];
        })()
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
