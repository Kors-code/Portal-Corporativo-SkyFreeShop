<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthCheck
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Si no estás logueado, te manda a la pantalla de login
        if (!session()->has('auth.isLogged') || session('auth.isLogged') !== true) {
            return redirect()->route('login')->with('error', 'Debes iniciar sesión para acceder.');
        }
        // Si estás OK, dejas pasar la petición
        return $next($request);
    }
}
