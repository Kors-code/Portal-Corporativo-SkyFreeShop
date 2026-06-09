<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $login = trim((string) $request->input('login'));
        $key = Str::lower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'login' => 'Demasiados intentos. Intenta mas tarde.',
            ]);
        }

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $login)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'login' => 'Credenciales invalidas.',
            ]);
        }

        RateLimiter::clear($key);

        if (in_array($user->fav_2fa ?? null, ['google_authenticator', 'email'], true)) {
            return response()->json([
                'message' => 'Este usuario tiene 2FA activo. El flujo movil de 2FA debe implementarse.',
                'requires_2fa' => true,
            ], 423);
        }

        $token = $user->createToken(
            $request->input('device_name', 'Portal Sky Mobile')
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->serializeUser($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'role_id' => $user->role_id,
            'seller_code' => $user->seller_code,
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
