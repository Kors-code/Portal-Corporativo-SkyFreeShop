<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;


class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create()
    {
        $cacheTime = 3600;
        $paises = Cache::get('lista_paises');
    
        if (!$paises) {
            try {
                $response = Http::timeout(5)->retry(3, 200)->get('https://restcountries.com/v3.1/all');
    
                if ($response->successful()) {
                    $data = $response->json();
    
                    $paises = [];
                    foreach ($data as $country) {
                        if (isset($country['idd']['root'])) {
                            $root = $country['idd']['root'];
                            $suffix = $country['idd']['suffixes'][0] ?? '';
                            $code = $root . $suffix;
                            $name = $country['name']['common'] ?? 'Desconocido';
                            $paises[] = ['name' => $name, 'code' => $code];
                        }
                    }
    
                    usort($paises, fn($a, $b) => strcmp($a['name'], $b['name']));
                    Cache::put('lista_paises', $paises, $cacheTime);
                } else {
                    // Si la API responde pero no con éxito
                    throw new \Exception("La respuesta no fue exitosa.");
                }
            } catch (\Throwable $e) {
                // Si hay algún error (por ejemplo cURL error 35), usar fallback local
                $paises = [
                    ['name' => 'Colombia', 'code' => '+57'],
                    ['name' => 'Estados Unidos', 'code' => '+1'],
                    ['name' => 'México', 'code' => '+52'],
                ];
            }
        }
    
        $paisPredeterminado = '+57';
        return view('auth.register', compact('paises', 'paisPredeterminado'));
    }
    
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'username' => 'required|string|max:255|unique:users',
        ]);

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'telefono' => $request->telefono,
            'country_code' => $request->country_code,
        ]);
        

        event(new Registered($user));



        return redirect()->route('login')->with('success', 'Usuario registrado correctamente. Ahora puedes iniciar sesión.');


    }
}
