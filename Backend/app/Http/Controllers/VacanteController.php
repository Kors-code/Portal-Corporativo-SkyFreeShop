<?php
namespace App\Http\Controllers;

use App\Models\Vacante;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VacanteController extends Controller
{
    public function index()
    {
        $vacantes = Vacante::all();
        return view('vacantes.index', compact('vacantes'));
    }


    public function create()
    {
        return view('vacantes.create');
    }

    public function store(Request $request)
    {
    $request->validate([
        'titulo' => 'required|string|max:255|unique:vacantes,titulo',
        'descripcion' => 'required|string',
        'requisito_ia' => 'required|string',
        'salario' => 'required|string',
        'beneficios' => 'required|required|min:1',
        'beneficios.*' => 'required|string|min:2',
        'requisitos' => 'required|array|min:1',
        'requisitos.*' => 'required|string|min:2',
        'criterios' => 'required|array|size4',
        'criterios' => 'required',
        'localidad' => 'required'
        
        
        
    ]);

        if (array_sum($request->criterios) !== 100) {
        return back()->withErrors(['criterios' => 'La suma de los pesos debe ser exactamente 100%'])->withInput();
    }

    Vacante::create([
        'titulo' => $request->titulo,
        'descripcion' => $request->descripcion,
        'requisito_ia' => $request->requisito_ia,
        'salario' => $request->salario,
        'beneficios' => $request->beneficios,
        'slug' => Str::slug($request->titulo),
        'requisitos' => $request->requisitos,
        'criterios' => $request->criterios,
        'localidad' => $request->localidad,
        
    ]);
        return redirect()->route('vacantes.index')->with('success', 'Vacante creada correctamente.');
    }

// VacanteController.php
        public function vervacantes($localidad)
        {
            $vacantes = Vacante::all();
            return view('vacantes.vacantes', compact('vacantes', 'localidad'));
        }
        public function inicio()
        {
            return view('vacantes.inicio');
        }

    public function show($slug)
    {
        $vacante = Vacante::where('slug', $slug)->firstOrFail();
        if ($vacante->habilitado == 0) {
        // opción 1: mostrar una vista de error personalizada
        return response()->view('errors.vacanteDesabilitada', [], 403);
        }
        return view('vacantes.show', compact('vacante'));
    }
    public function edit($slug)
    {
        $vacante = Vacante::where('slug', $slug)->firstOrFail();
        return view('vacantes.edit', compact('vacante'));
    }
public function update(Request $request, $slug)
{
    $vacante = Vacante::where('slug', $slug)->firstOrFail();

    // ✅ Validación de datos
    $request->validate([
        'titulo' => 'required|string|max:255',
        'descripcion' => 'required|string',
        'requisito_ia' => 'required|string',
        'salario' => 'required|string|max:255',
        'localidad' => 'required|string|max:255',
        'beneficios' => 'nullable|array',
        'beneficios.*' => 'nullable|string|max:255',
        'requisitos' => 'nullable|array',
        'requisitos.*' => 'nullable|string|max:255',
        'criterios' => 'required|array',
        'criterios.*' => 'nullable|integer|min:0|max:100',
    ]);

    // ✅ Actualización de los campos
    $vacante->update([
        'titulo' => $request->titulo,
        'descripcion' => $request->descripcion,
        'requisito_ia' => $request->requisito_ia,
        'salario' => $request->salario,
        'localidad' => $request->localidad,
        'beneficios' => $request->beneficios ?? [],
        'requisitos' => $request->requisitos ?? [],
        'criterios' => $request->criterios ?? [],
        'slug' => Str::slug($request->titulo),
    ]);

    return redirect()
        ->route('vacantes.index')
        ->with('success', 'Vacante actualizada correctamente ✅');
}
    public function destroy($slug)
    {
        $vacante = Vacante::where('slug', $slug)->firstOrFail();
        $vacante->delete();

        return redirect()->route('vacantes.index')->with('success', 'Vacante eliminada correctamente.');
    }
    public function vacantes()
       {
 $vacantes = Vacante::all();
    return view('vacantes.vacantes', compact('vacantes'));
    }
    public function habilitar(Request $request, $slug)
    {
        $vacante = Vacante::where('slug', $slug)->firstOrFail();
    
        if ($request->habilitado === "habilitado") {
            $vacante->habilitado = true;
            $vacante->save();
            $msg = 'Vacante habilitada correctamente.';
        }
    
        if ($request->habilitado === "deshabilitar") {
            $vacante->habilitado = false;
            $vacante->save();
            $msg = 'Vacante deshabilitada correctamente.';
        }
    
        return redirect()->route('vacantes.index')->with('success', $msg);
    }

}
