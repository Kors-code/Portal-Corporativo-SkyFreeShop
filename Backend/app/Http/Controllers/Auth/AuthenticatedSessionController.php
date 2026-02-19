<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use OTPHP\TOTP;
class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
public function store(LoginRequest $request): RedirectResponse
{
    $request->validate([
        'login'    => 'required|string',
        'password' => 'required|string',
    ]);

    $login = $request->input('login');
    $fieldType = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

    if (Auth::attempt([$fieldType => $login, 'password' => $request->password], $request->boolean('remember'))) {
        $request->session()->regenerate();

        $user = Auth::user();

        // Si el usuario tiene activo el 2FA → pedir código
        if ($user->fav_2fa === 'google_authenticator') {
            Auth::logout(); // cerramos hasta que valide el código

            $request->session()->put('2fa:user:id', $user->id);

            return redirect()->route('2fa.verify');
        }
        if ($user->fav_2fa === 'email') {
            Auth::logout(); // cerramos hasta que valide el código
            $request->session()->put('2fa:user:id', $user->id);
            app(\App\Http\Controllers\TwoFactorEmailController::class)->setup($request);


            return redirect()->route('2fa.email.setup');

        }

        return redirect()->intended(route('welcome'));
    }

    return back()->withErrors(['login' => __('auth.failed')])->onlyInput('login');
}

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(route('welcome'));
    }
}
