<?php

namespace App\Http\Controllers\PersonalController;

use App\Http\Controllers\Controller;  
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function showForm()
    {
        // Vista con el formulario
        return view('Disciplinas.Formularios.DisciplinaVerbal');
    }

    public function handleForm(Request $request)
    {
        // (Opcional) Validar y redirigir; en este flujo el formulario envía directo a generar PDF
        $request->validate([
            'nombre' => 'required|string|max:255',
            // agrega reglas que necesites...
        ]);

        return back()->with('success', 'Formulario recibido');
    }
}
