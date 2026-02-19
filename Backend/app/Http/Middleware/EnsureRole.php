<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureRole
{
    public function handle($request, Closure $next, ...$roles)
    {
        if (!Auth::check()) {
            abort(403, 'Debes iniciar sesión para acceder a esta sección.');
        }

        // Verifica si el rol del usuario está dentro de los roles permitidos
        if (!in_array(Auth::user()->role, $roles)) {
            abort(403, 'No tienes permisos para acceder a esta sección.');
        }

        return $next($request);
    }
}
