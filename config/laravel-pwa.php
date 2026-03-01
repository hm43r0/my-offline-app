<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel PWA Configuration
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'Laravel PWA'),
    'short_name' => env('APP_SHORT_NAME', 'PWA'),
    'start_url' => '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#6777ef',

    'offline' => [
        // The route or URL to use as the offline fallback page
        'page' => '/offline',
    ],

    'service_worker' => [
        // Path under `public/` where your service worker is published
        'file' => 'serviceworker.js',
    ],

    // Manifest file path (under public/)
    'manifest' => [
        'file' => 'manifest.json',
    ],
];
