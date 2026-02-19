<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Models\Photo;
use Illuminate\Validation\Rule;
use MailerSend\MailerSend;
use MailerSend\Helpers\Builder\EmailParams;
use MailerSend\Helpers\Builder\Recipient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;


class UserController extends Controller
{
    public function create()
    {
        // Obtén todas las fotos para la galería
        $photos = Photo::all(); 
        return view('/usuarios/create', compact('photos'));
    }

public function store(Request $request)
{
    $request->validate([
        'name'     => 'required|string|max:255',
        'username' => 'required|string|max:255|unique:users',
        'email'    => 'required|string|email|unique:users',
        'password' => 'required|string|min:8|confirmed',
        'role'     => 'required|string|in:super_admin,admin,user,user_portal,user_disciplina,seller',
        'imagenes' => 'nullable|string',
        'auth_correo' => 'nullable|boolean',
    ]);

    User::create([
        'name'     => $request->name,
        'email'    => $request->email,
        'username' => $request->username,
        'password' => Hash::make($request->password),
        'role'     => $request->role,
        'foto'     => $request->imagenes ?? '/imagenes/perfil/sinfoto.jpg',
        'auth_correo' => $request->auth_correo ?? 0,
    ]);

    return redirect()->route('usuarios.create')->with('success', 'Usuario creado correctamente.');
}

        public function index(Request $request)
    {
        // Obtener término de búsqueda
        $q = $request->input('q');

        // Construir la consulta
        $query = User::query();

        if ($q) {
            $query->where('id', $q)
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhere('username', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%");
        }

        // Paginación (p.ej. 15 por página)
        $users = $query->orderBy('id')->paginate(15)
                       ->appends(['q' => $q]);

        return view('usuarios.view-user', compact('users','q'));
    }
     public function destroy(User $user)
    {
        $user->delete();
    
        return redirect()->route('view-users')
                         ->with('success', 'Usuario eliminado correctamente.');
    }
    public function verusuario(User $user)
    {

        
        $usuario = $user;
        if (!$usuario) {
            return redirect()->route('users.index')->with('error', 'Usuario no encontrado.');
        }

        // Verificar si la lección actual está completada

        return view('usuarios/ver_usuario', [
            'titulo' => 'Perfil',
            'usuario_id' => $usuario->id,
            'usuario' => $usuario,
            'baseUrl' => url('/'), // Para usar en las rutas de las vistas
        ]);
    }
    public function verperfil()
    {

        $user = auth()->user();
        $usuario = $user;
        if (!$usuario) {
            return redirect()->route('users.index')->with('error', 'Usuario no encontrado.');
        }

        // Verificar si la lección actual está completada

        return view('usuarios/ver_perfil', [
            'titulo' => 'Perfil',
            'usuario_id' => $usuario->id,
            'usuario' => $usuario,
            'baseUrl' => url('/'), // Para usar en las rutas de las vistas
        ]);
    }

    public function edit($id)
    {
        $usuario = User::find($id);
        if (!$usuario) {
            return redirect()->route('users.index')->with('error', 'Usuario no encontrado.');
        }

        $photos = Photo::all(); 


        return view('usuarios/edit-user', compact('usuario', 'photos'));
    }
    public function ver($id)
    {
        $usuario = User::find($id);
        if (!$usuario) {
            return redirect()->route('users.index')->with('error', 'Usuario no encontrado.');
        }

        return view('usuarios/ver_usuario', compact('usuario'));
    }

    public function update(Request $request, $id)
    {
        $usuario = User::find($id);
        if (!$usuario) {
            return redirect()->route('users.index')->with('error', 'Usuario no encontrado.');
        }
    
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'username' => [
            'required','string','max:255',
            Rule::unique('users','username')->ignore($id),
            ],
            'email' => [
            'required','email',
            Rule::unique('users','email')->ignore($id),
        ],

            'password'       => 'nullable|min:6',
            'role'     => 'required|string|in:super_admin,admin,user',
            'auth_correo'    => 'required|in:0,1',
            'imagenes'       => 'nullable|string',  
        ]);
    
        $usuario->name         = $validated['name'];
        $usuario->username     = $validated['username'];
        $usuario->email        = $validated['email'];
        $usuario->role          = $validated['role'];
        $usuario->auth_correo  = $validated['auth_correo'];
    
    
        if (!empty($validated['password'])) {
            $usuario->password = bcrypt($validated['password']);
        }
    
        $usuario->save();
    
        return redirect()->route('users.index')->with('success', 'Usuario actualizado correctamente.');
    }

public function enviarVerificacion()
{
    $user = Auth::user();
    $mailersend = new MailerSend([
        'api_key' => 'mlsn.608054d02d63a90ad67cab94e7cdf80ca366b43675588065dfb86fae3d0a5ba0'
    ]);

    $recipients = [
        new Recipient($user->email, $user->name),
    ];

    // Generar un token único y guardarlo en el usuario
    $token = Str::random(64);
    $user->email_verification_token = $token;
    $user->email_token_expired_at = now()->addMinutes(10);
    $user->save();

    $verificationUrl = URL::to('/verify-email/' . $user->id . '/' . $token);

    $recipients = [
        new Recipient($user->email, $user->name),
    ];

    $emailParams = (new EmailParams())
        ->setFrom('no-reply@skyfreeshopdutyfree.com')
        ->setFromName('Duty Free Partners')
        ->setRecipients($recipients)
        ->setSubject('Verificación de correo electrónico')
        ->setHtml('<p>Hola ' . $user->name . ',</p>
                   <p>Haz clic en el siguiente enlace para verificar tu correo:</p>
                   <a href="' . $verificationUrl . '">Verificar Correo</a>');

    try {
        $response = $mailersend->email->send($emailParams);

        return back()->with('success', 'Correo de verificación enviado a ' . $user->email);
    } catch (\Exception $e) {
        return back()->with('error', 'Error al enviar correo: ' . $e->getMessage());
    }
}
public function verifyEmail($id, $token)
{
    $user = User::findOrFail($id);

    // Validar token y expiración
    if (
        $user->email_verification_token === $token &&
        $user->email_token_expired_at &&
        now()->lessThanOrEqualTo($user->email_token_expired_at)
    ) {
        $user->auth_correo = true;
        $user->email_verification_token = null;
        $user->email_token_expired_at = null;
        $user->email_verified_at = now();
        $user->fav_2fa = "email";
        $user->save();

        return view('usuarios.verificacion', [
            'status' => 'success',
            'mensaje' => 'Tu correo fue verificado correctamente ✅'
        ]);
    }

    return view('usuarios.verificacion', [
        'status' => 'error',
        'mensaje' => 'El enlace de verificación no es válido o ya expiró ❌'
    ]);
}

}
