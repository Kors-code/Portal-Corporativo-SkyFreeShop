<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class AuthCorreoController extends Controller
{
    public function verify($id, $hash)
    {
        $user = User::findOrFail($id);

        // Validar que el hash sea correcto
        if (! hash_equals($hash, sha1($user->email))) {
            return redirect()->route('login')->with('error', 'Enlace inválido o caducado.');
        }

        // Si ya está verificado
        if ($user->auth_correo) {
            return redirect()->route('login')->with('info', 'Tu correo ya está verificado.');
        }

        // Actualizar estado de verificación
        $user->auth_correo = 1;
        $user->save();

        return redirect()->route('login')->with('success', 'Correo verificado correctamente, ya puedes iniciar sesión.');
    }
}
