<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
public function handle(Request $request, Closure $next, ...$permissions)
{
    $user = $request->user();

    if (!$user) {
        abort(403, 'No autenticado');
    }

    foreach ($permissions as $permission) {
        if ($user->hasPermission($permission)) {
            return $next($request);
        }
    }

    abort(403, 'No tienes permiso');
}
}