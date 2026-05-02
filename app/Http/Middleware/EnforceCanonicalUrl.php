<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCanonicalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldEnforce($request)) {
            return $next($request);
        }

        $target = $this->redirectTarget($request);

        if ($target) {
            return redirect()->to($target, 308);
        }

        return $next($request);
    }

    private function shouldEnforce(Request $request): bool
    {
        if ($request->is('up')) {
            return false;
        }

        return (bool) config('security.enforce_canonical_url')
            || (bool) config('security.force_https');
    }

    private function redirectTarget(Request $request): ?string
    {
        $canonicalUrl = trim((string) config('security.canonical_url', ''));
        $canonical = $canonicalUrl !== '' ? parse_url($canonicalUrl) : [];

        $targetHost = strtolower((string) ($canonical['host'] ?? ''));
        $targetScheme = strtolower((string) ($canonical['scheme'] ?? ''));

        if ($targetHost === '') {
            $targetHost = strtolower($request->getHost());
        }

        if ((bool) config('security.force_https')) {
            $targetScheme = 'https';
        } elseif ($targetScheme === '') {
            $targetScheme = $request->getScheme();
        }

        $targetPort = $this->normalizedPort($canonical['port'] ?? null, $targetScheme);
        $currentScheme = strtolower($request->getScheme());
        $currentHost = strtolower($request->getHost());
        $currentPort = $this->normalizedPort($request->getPort(), $currentScheme);

        if (
            $currentScheme === $targetScheme &&
            $currentHost === $targetHost &&
            $currentPort === $targetPort
        ) {
            return null;
        }

        return $targetScheme.'://'.$targetHost.$this->portSuffix($targetPort).$request->getRequestUri();
    }

    private function normalizedPort(mixed $port, string $scheme): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $port = (int) $port;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return null;
        }

        return $port;
    }

    private function portSuffix(?int $port): string
    {
        return $port ? ':'.$port : '';
    }
}
