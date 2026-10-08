<?php

$defaultOrigins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:3002',
    'http://localhost:5173',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:3002',
    'http://127.0.0.1:5173',
];

/**
 * Normalise a comma-separated origin list into `scheme://host[:port]` entries.
 *
 * A browser sends `Origin: https://app.example.com` — no path, no trailing
 * slash. So `https://app.example.com/` in the allow-list never matches and the
 * request fails CORS with a confusing "origin not allowed". Parse each entry
 * and keep only the scheme, host and port.
 */
$normaliseOrigins = static function (?string $raw): array {
    return array_values(array_filter(array_map(
        static function (string $origin): string {
            $origin = trim($origin);

            if ($origin === '') {
                return '';
            }

            $parts = parse_url($origin);

            // Anything without both a scheme and a host is not a valid origin.
            if (! isset($parts['scheme'], $parts['host'])) {
                return '';
            }

            return $parts['scheme'].'://'.$parts['host']
                .(isset($parts['port']) ? ':'.$parts['port'] : '');
        },
        explode(',', (string) $raw)
    )));
};

// FRONTEND_URL is the canonical origin of the deployed frontend — the one value
// you set on the hosting platform (Render, Vercel, Netlify). CORS_ALLOWED_ORIGINS
// remains for any *extra* origins (preview deploys, a second domain). Both accept
// a comma-separated list and tolerate trailing slashes.
$frontendOrigins = $normaliseOrigins(env('FRONTEND_URL', ''));
$extraOrigins = $normaliseOrigins(env('CORS_ALLOWED_ORIGINS', ''));

return [

    'paths' => ['api', '/api', 'api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_merge(
        $defaultOrigins,
        $frontendOrigins,
        $extraOrigins,
    ))),

    // Allow any port on localhost / 127.0.0.1; common private LAN ranges for mobile web (same Wi‑Fi)
    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        // NO wildcard patterns for shared public hosts here. `supports_credentials`
        // is true below, so `#^https://[a-z0-9.-]+\.vercel\.app$#` (and the same
        // for netlify.app / trycloudflare.com) let ANYONE who deploys to that
        // shared domain make credentialed cross-origin calls to this API from a
        // page they control. Add a hosted or tunnelled frontend by its exact
        // origin in FRONTEND_URL / CORS_ALLOWED_ORIGINS instead.
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
