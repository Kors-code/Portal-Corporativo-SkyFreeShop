<?php
// app/Http/Controllers/PhotoController.php

namespace App\Http\Controllers;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function store(Request $request)
    {
        $file    = $request->file('imagen');
        $nombre  = time() . '_' . $file->getClientOriginalName();

        // 2. Guardar el archivo en storage/app/fotos (privado)
        $rutaRelativa = 'fotos/' . $nombre;
        $file->storeAs('fotos', $nombre);

        // 3. Guardar en BD la ruta relativa
        Photo::create(['ruta' => $rutaRelativa]);

        return back()->with('success', 'Imagen subida correctamente');
    }
    public function show($id)
{
    $photo = Photo::findOrFail($id);
    $path = storage_path('app/' . $photo->ruta);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
}
}
