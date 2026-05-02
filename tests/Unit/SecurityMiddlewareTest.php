<?php

namespace Tests\Unit;

use App\Http\Middleware\EnforceCanonicalUrl;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    public function test_canonical_middleware_redirects_to_https_primary_domain(): void
    {
        config([
            'security.canonical_url' => 'https://aksaxiterz.com',
            'security.enforce_canonical_url' => true,
            'security.force_https' => true,
        ]);

        $request = Request::create('http://www.aksaxiterz.com/product/1?package=day', 'GET');

        $response = (new EnforceCanonicalUrl)->handle($request, fn () => new Response('ok'));

        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame('https://aksaxiterz.com/product/1?package=day', $response->headers->get('Location'));
    }

    public function test_canonical_middleware_skips_health_check(): void
    {
        config([
            'security.canonical_url' => 'https://aksaxiterz.com',
            'security.enforce_canonical_url' => true,
            'security.force_https' => true,
        ]);

        $request = Request::create('http://railway.internal/up', 'GET');

        $response = (new EnforceCanonicalUrl)->handle($request, fn () => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_security_headers_include_csp_and_hsts_on_https(): void
    {
        config([
            'security.content_security_policy' => true,
            'security.hsts' => true,
        ]);

        $request = Request::create('https://aksaxiterz.com/orders', 'GET');

        $response = (new SecurityHeaders)->handle($request, fn () => new Response('ok'));

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('X-Powered-By'));
    }
}
