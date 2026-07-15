<?php
// app/Http/Controllers/PhotoController.php

namespace App\Http\Controllers;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PhotoController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'imagen' => ['required', 'image', 'max:5120'],
        ]);

        $file    = $request->file('imagen');
        $extension = strtolower($file->getClientOriginalExtension());
        $nombre  = Str::uuid()->toString() . '.' . $extension;

        $rutaRelativa = 'fotos/' . $nombre;
        $file->storeAs('fotos', $nombre);

        Photo::create(['ruta' => $rutaRelativa]);

        return back()->with('success', 'Imagen subida correctamente');
    }
    public function show($id)
{
    $photo = Photo::findOrFail($id);
    $ruta = (string) $photo->ruta;

    if (!preg_match('/^fotos\/[A-Za-z0-9-]+\.(jpg|jpeg|png|gif|webp|bmp)$/i', $ruta)) {
        abort(404);
    }

    $basePath = realpath(storage_path('app/fotos'));
    $path = realpath(storage_path('app/' . $ruta));

    if (!$basePath || !$path || !str_starts_with($path, $basePath . DIRECTORY_SEPARATOR) || !file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
}
}
