<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MailerSend\MailerSend;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;

class TwoFactorEmailController extends Controller
{
    // Formulario para pedir OTP
    public function showSetupForm()
    {
        return view('auth.2fa-email-setup');
    }

    // Generar y enviar OTP
    public function setup(Request $request)
    {
        $userId = $request->session()->get('2fa:user:id');
        $user = \App\Models\User::findOrFail($userId);
        // Generar código de 6 dígitos
        $code = rand(100000, 999999);

        // Guardar en BD con expiración
        $user->email2fa_secret = $code;
        $user->email2fa_expires_at = now()->addMinutes(5);
        $user->fav_2fa = "email";
        $user->save();

        // Enviar correo
        $recipients = [
            new Recipient($user->email, $user->name),
        ];

        $emailParams = (new EmailParams())
            ->setFrom('no-reply@skyfreeshopdutyfree.com')
            ->setFromName('Duty Free Partners')
            ->setRecipients($recipients)
            ->setSubject('Verificación de correo electrónico')
            ->setHtml("
                <p>Hola {$user->name},</p>
                <p>Tu código de verificación es: <b>{$code}</b></p>
                <p>Este código expira en 5 minutos.</p>
                <p>Si no solicitaste este código, ignora este correo.</p>
                <p>Gracias,<br>El equipo de Duty Free Partners</p>
            ");

        try {
    $mailersend = new MailerSend([
        'api_key' => 'mlsn.608054d02d63a90ad67cab94e7cdf80ca366b43675588065dfb86fae3d0a5ba0'
    ]);
            $mailersend->email->send($emailParams);

            return back()->with('success', 'Correo de verificación enviado a ' . $user->email);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al enviar correo: ' . $e->getMessage());
        }
    }

    // Validar OTP
    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'required|digits:6',
        ]);
        $userId = $request->session()->get('2fa:user:id');
        $user = \App\Models\User::findOrFail($userId);

        if (
            $user->email2fa_secret === $request->code &&
            $user->email2fa_expires_at &&
            $user->email2fa_expires_at->isFuture()
        ) {
            // OTP válido
            $user->email2fa_secret = null;
            $user->email2fa_expires_at = null;
            $user->fav_2fa = "email";
            $user->save();
             Auth::login($user);
            $request->session()->forget('2fa:user:id');
            return redirect()->route('welcome')->with('success', 'Verificación exitosa ✅');
        }

        return back()->withErrors(['code' => 'El código es inválido o ha expirado.']);
    }
}
