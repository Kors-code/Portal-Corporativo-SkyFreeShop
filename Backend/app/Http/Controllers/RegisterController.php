<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RegisterController extends Controller
{
    public function show()
    {
        // Tiempo de caché en segundos (por ejemplo, 1 hora)
        $cacheTime = 3600;

        // Intenta obtener la lista de países desde la caché
        $paises = Cache::get('lista_paises');

        if (!$paises) {
            // Llamada a la API para obtener la lista de países
            $response = file_get_contents('https://restcountries.com/v3.1/all');
            if ($response !== false) {
                $data = json_decode($response, true);
                $paises = [];
                // Recorre la respuesta para extraer la información
                foreach ($data as $country) {
                    if (isset($country['idd']['root'])) {
                        $root = $country['idd']['root'];
                        $suffix = isset($country['idd']['suffixes'][0]) ? $country['idd']['suffixes'][0] : '';
                        $code = $root . $suffix;
                        $name = $country['name']['common'] ?? 'Desconocido';
                        $paises[] = ['name' => $name, 'code' => $code];
                    }
                }
                // Ordenar la lista por nombre
                usort($paises, fn($a, $b) => strcmp($a['name'], $b['name']));
                // Almacenar en caché
                Cache::put('lista_paises', $paises, $cacheTime);
            } else {
                // Si la API falla, puedes asignar una lista estática básica
                $paises = [
                    ['name' => 'Colombia', 'code' => '+57'],
                    ['name' => 'Estados Unidos', 'code' => '+1'],
                    ['name' => 'México', 'code' => '+52'],
                ];
            }
        }

        // Aquí puedes obtener el país predeterminado basado en algún criterio, por ejemplo:
        $paisPredeterminado = '+57';

        // Retorna la vista pasando la lista de países y el país predeterminado
        return view('auth.register', compact('paises', 'paisPredeterminado'));
    }
}
