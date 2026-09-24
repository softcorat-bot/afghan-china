<?php

/*
 | Cross-origin rules for the SPA.
 |
 | The dashboard talks to this API from several places, and every one of them
 | sends a browser `Origin` header that must be answered with a matching
 | `Access-Control-Allow-Origin` — otherwise the browser throws the response
 | away and the sign-in fails with a bare "Network Error" even though the API
 | answered 200. The origins this app actually runs from are:
 |
 |   • the Quasar dev server — port 9000 by default, but Quasar silently picks
 |     the next free port when 9000 is taken;
 |   • a shop-floor PC opening the dashboard over the LAN by IP address, which
 |     is the normal way a till reaches the back-office server;
 |   • the packaged desktop app, loaded over `file://`, whose Origin is the
 |     literal string `null`;
 |   • whatever domain a hosted install is served from (CORS_ALLOWED_ORIGINS).
 |
 | `allowed_origins` is matched exactly, so it cannot express "any port" or
 | "any machine on the LAN" — those live in `allowed_origins_patterns`, which
 | are regular expressions (delimiters included) matched against the Origin.
 */

// Extra origins a hosted install is served from: CORS_ALLOWED_ORIGINS=https://mis.example.com,https://admin.example.com
$extraOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_merge([
        env('FRONTEND_URL', 'http://localhost:9000'),
        'http://localhost:9000',
        'http://127.0.0.1:9000',
    ], $extraOrigins))),

    // localhost / 127.0.0.1 / [::1] on ANY port — the dev server moves to 9001+
    // whenever 9000 is busy, and a locked port reads as a broken login.
    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^https?://\[::1\](:\d+)?$#',
        // Private/LAN address space — a till PC reaching the server on the shop
        // network (10/8, 172.16/12, 192.168/16) plus link-local.
        '#^https?://(10\.\d{1,3}\.\d{1,3}\.\d{1,3}'
            .'|192\.168\.\d{1,3}\.\d{1,3}'
            .'|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}'
            .'|169\.254\.\d{1,3}\.\d{1,3})(:\d+)?$#',
        // Packaged desktop app loaded from disk: `file://` sends Origin: null.
        '#^null$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
