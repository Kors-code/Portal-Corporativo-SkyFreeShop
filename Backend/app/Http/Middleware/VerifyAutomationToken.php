<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyAutomationToken
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('services.automation.token', '');

        if ($expected === '') {
            return response()->json(['message' => 'Automation token is not configured.'], 503);
        }

        $provided = (string) $request->header('X-Automation-Token', '');

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized automation request.'], 401);
        }

        return $next($request);
    }
}
