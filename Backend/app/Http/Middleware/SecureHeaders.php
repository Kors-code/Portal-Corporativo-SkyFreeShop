<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecureHeaders
{

    private function getContentSecurityPolicy(Request $request): string
    {

        $csp = collect([
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "require-trusted-types-for 'script'",
 
        ])->implode('; ');
        
        $basePolicy = [
            "default-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'"
        ];
        
        if (app()->environment('production')) {
            // CSP más estricto para producción
            $policy = array_merge($basePolicy, [
                "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
                "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: https:",
                "connect-src 'self'",
                "media-src 'self'"
            ]);
        } else {
            // CSP más permisivo para desarrollo
            $policy = array_merge($basePolicy, [
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: https: blob:",
                "connect-src 'self' ws: wss:", // Para hot reload
                "media-src 'self'"
            ]);
        }
        
        return implode('; ', $policy);
    }

    /**
     * Verificar si es una página sensible que requiere headers adicionales
     */
    private function isSensitivePage(Request $request): bool
    {
        $sensitiveRoutes = [
            '/admin',
            '/dashboard',
            '/profile',
            '/settings',
            '/api/auth',
            '/login',
            '/register'
        ];
        
        $currentPath = $request->path();
        
        foreach ($sensitiveRoutes as $route) {
            if (str_starts_with('/' . $currentPath, $route)) {
                return true;
            }
        }
        
        return false;
    }
}