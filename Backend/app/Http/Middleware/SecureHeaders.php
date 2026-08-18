<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->getContentSecurityPolicy());
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Origin-Agent-Cluster', '?1');

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        if ($this->shouldDisableCache($request, $response)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    private function getContentSecurityPolicy(): string
    {
        $basePolicy = [
            "default-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "upgrade-insecure-requests",
        ];

        if (app()->environment('production')) {
            $policy = array_merge($basePolicy, [
                "script-src 'self'",
                "style-src 'self'",
                "font-src 'self' data:",
                "img-src 'self' data: https:",
                "connect-src 'self'",
                "frame-src 'self' blob:",
                "media-src 'self'",
                "manifest-src 'self'",
            ]);
        } else {
            $policy = array_merge($basePolicy, [
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "font-src 'self' data:",
                "img-src 'self' data: https: blob:",
                "connect-src 'self' http://127.0.0.1:8000 http://localhost:8000 http://127.0.0.1:5173 http://localhost:5173 ws: wss:",
                "frame-src 'self' blob:",
                "media-src 'self'",
                "manifest-src 'self'",
            ]);
        }

        return implode('; ', $policy);
    }

    private function shouldDisableCache(Request $request, Response $response): bool
    {
        if ($this->isSensitivePage($request)) {
            return true;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        return str_contains($contentType, 'text/html')
            || str_starts_with('/' . $request->path(), '/api/');
    }

    private function isSensitivePage(Request $request): bool
    {
        $sensitiveRoutes = [
            '/admin',
            '/dashboard',
            '/profile',
            '/settings',
            '/api/auth',
            '/api/v1',
            '/login',
            '/register',
            '/panel',
            '/usuarios',
            '/presupuesto',
        ];

        $currentPath = '/' . $request->path();

        foreach ($sensitiveRoutes as $route) {
            if (str_starts_with($currentPath, $route)) {
                return true;
            }
        }

        return false;
    }
}
