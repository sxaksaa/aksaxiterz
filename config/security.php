<?php

$production = env('APP_ENV', 'production') === 'production';
$canonicalUrl = env('AKSA_CANONICAL_URL');

if ($canonicalUrl === null || $canonicalUrl === '') {
    $canonicalUrl = env('APP_URL', '');
}

return [
    'canonical_url' => $canonicalUrl,
    'enforce_canonical_url' => (bool) env('AKSA_ENFORCE_CANONICAL_URL', $production),
    'force_https' => (bool) env('AKSA_FORCE_HTTPS', $production),
    'hsts' => (bool) env('AKSA_HSTS', $production),
    'content_security_policy' => (bool) env('AKSA_CSP', $production),
];
