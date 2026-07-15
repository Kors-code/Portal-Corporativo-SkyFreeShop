<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;
use MailerSend\MailerSend;

class TwoFactorEmailController extends Controller
{
    public function showSetupForm()
    {
        if (! session()->has('2fa:user:id')) {
            return redirect()->route('login')->withErrors(['login' => 'Debes iniciar sesion primero.']);
        }

        return view('auth.2fa-email-setup');
    }

    public function setup(Request $request)
    {
        $userId = $request->session()->get('2fa:user:id');
        if (! $userId) {
            return redirect()->route('login')->withErrors(['login' => 'Debes iniciar sesion primero.']);
        }

        $user = \App\Models\User::findOrFail($userId);
        $code = (string) random_int(100000, 999999);

        $user->email2fa_secret = Hash::make($code);
        $user->email2fa_expires_at = now()->addMinutes(5);
        $user->fav_2fa = 'email';
        $user->save();

        $apiKey = (string) config('services.mailersend.api_key');
        if ($apiKey === '') {
            return back()->with('error', 'MailerSend no esta configurado.');
        }

        $recipients = [
            new Recipient($user->email, $user->name),
        ];

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Duty Free Partners')
            ->setRecipients($recipients)
            ->setSubject('Verificacion de correo electronico')
            ->setHtml("
                <p>Hola {$user->name},</p>
                <p>Tu codigo de verificacion es: <b>{$code}</b></p>
                <p>Este codigo expira en 5 minutos.</p>
                <p>Si no solicitaste este codigo, ignora este correo.</p>
                <p>Gracias,<br>El equipo de Duty Free Partners</p>
            ");

        try {
            $mailersend = new MailerSend([
                'api_key' => $apiKey,
            ]);
            $mailersend->email->send($emailParams);

            return back()->with('success', 'Correo de verificacion enviado a ' . $user->email);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al enviar correo: ' . $e->getMessage());
        }
    }

    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'required|digits:6',
        ]);

        $userId = $request->session()->get('2fa:user:id');
        if (! $userId) {
            return redirect()->route('login')->withErrors(['login' => 'Debes iniciar sesion primero.']);
        }

        $user = \App\Models\User::findOrFail($userId);

        if (
            $user->email2fa_secret &&
            Hash::check((string) $request->code, (string) $user->email2fa_secret) &&
            $user->email2fa_expires_at &&
            $user->email2fa_expires_at->isFuture()
        ) {
            $user->email2fa_secret = null;
            $user->email2fa_expires_at = null;
            $user->fav_2fa = 'email';
            $user->save();

            Auth::login($user);
            $request->session()->forget('2fa:user:id');

            return redirect()->route('welcome')->with('success', 'Verificacion exitosa.');
        }

        return back()->withErrors(['code' => 'El codigo es invalido o ha expirado.']);
    }
}
