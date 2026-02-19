<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorController extends Controller
{
    // Vista para mostrar el QR
    public function enable(Request $request)
    {
        $user = Auth::user();

        // Generar TOTP (nuevo secret)
        $totp = TOTP::create();
        $totp->setLabel($user->username);
        $totp->setIssuer('Sky Free Shop');

        // Guardar secret en BD
        $user->google2fa_secret = $totp->getSecret();
        $user->save();
        
        // Generar QR
        $uri = $totp->getProvisioningUri();
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new ImagickImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCode = base64_encode($writer->writeString($uri));
        
        return view('auth.2fa-setup', compact('qrCode'));
    }
    
    public function showVerifyForm()
    {
        return view('auth.2fa-verify');
    }
    
    public function Setup(Request $request)
    {
        $user = Auth::user();
        $request->validate(['code' => 'required']);
        
        // ✅ Recrear TOTP desde el secret almacenado
        $totp = TOTP::createFromSecret($user->google2fa_secret);
        
        if ($totp->verify($request->code)) {
            $user->fav_2fa = "google_authenticator";
            $user->save();
            $request->session()->forget('2fa:user:id');
            return redirect()->route('vacantes.inicio')->with('success', 'Verificación creada con éxito ✅');
        }
        
        return back()->withErrors(['code' => 'Código inválido']);
    }
    public function verify(Request $request)
    {
        $request->validate(['code' => 'required']);
        
        $userId = $request->session()->get('2fa:user:id');
        $user = \App\Models\User::findOrFail($userId);
        
        // ✅ Recrear TOTP desde el secret almacenado
        $totp = TOTP::createFromSecret($user->google2fa_secret);
        
        if ($totp->verify($request->code)) {
            Auth::login($user);
            $request->session()->forget('2fa:user:id');
            return redirect()->intended(route('welcome'));
        }
        
        return back()->withErrors(['code' => 'Código inválido']);
    }
}
