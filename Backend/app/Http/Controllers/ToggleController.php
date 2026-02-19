<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ToggleController extends Controller
{
    public function store(Request $request)
    {
        $key   = $request->input('key');
        $value = (bool)$request->input('value');

        // Guardar en sesión bajo 'toggles.{key}'
        session()->put("toggles.{$key}", $value);

        return response()->json(['saved' => true]);
    }
}
