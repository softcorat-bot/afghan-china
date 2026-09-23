<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The SPA and the API usually live on different origins (Quasar dev server
| on :9000, Laravel on :8000; or shop.example.com → api.example.com), so the
| browser asks permission before every API call. If the SPA's exact origin
| is not allowed here, the browser blocks the request and the login page can
| only say "Login failed".
|
| Allowed:
|   • FRONTEND_URL — one or more origins, comma-separated
|     (e.g. "https://shop.example.com,https://www.shop.example.com").
|   • CORS_ALLOWED_ORIGINS — extra origins, comma-separated (optional).
|   • localhost / 127.0.0.1 / [::1] on ANY port. Quasar silently moves to
|     9001, 9002 … when 9000 is busy, which used to break login outright.
|   • Private-network (LAN) addresses on any port, so the till PCs of the
|     shop can open the dashboard served from another machine on the LAN.
|     Set CORS_ALLOW_LAN=false to switch that off.
|
| Auth is bearer-token based (see AuthController), so a permissive local
| policy does not expose session cookies.
*/

$csv = static fn (?string $value): array => array_values(array_filter(array_map(
    static fn ($origin) => rtrim(trim($origin), '/'),
    explode(',', (string) $value)
)));

$patterns = [
    // Local development on any port (http or https).
    '#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#',
];

if (filter_var(env('CORS_ALLOW_LAN', true), FILTER_VALIDATE_BOOL)) {
    // RFC 1918 private ranges: 10/8, 172.16/12, 192.168/16.
    $patterns[] = '#^https?://(10(\.\d{1,3}){3}|172\.(1[6-9]|2\d|3[01])(\.\d{1,3}){2}|192\.168(\.\d{1,3}){2})(:\d+)?$#';
}

return [
    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie', 'storage/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_merge(
        $csv(env('FRONTEND_URL', 'http://localhost:9000')),
        $csv(env('CORS_ALLOWED_ORIGINS', '')),
    ))),
    'allowed_origins_patterns' => $patterns,
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => true,
];
