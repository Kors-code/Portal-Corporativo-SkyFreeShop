<?php

namespace App\Http\Controllers\PersonalController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Personal\Empleado;
use App\Models\Personal\LlamadoAtencion;
use App\Exports\EmpleadosExport;
use App\Exports\DisciplinasPositivasExport;
use Maatwebsite\Excel\Facades\Excel;

class ListController extends Controller
{
 
    protected $connection = 'mysql_personal';   
   public function mostrarEmpleados(Request $request)
{
    
    $query = Empleado::query();

    // 🔍 Filtro por texto (nombre o cédula)
    if ($request->filled('query')) {
        $busqueda = $request->input('query');
        $query->where(function ($q) use ($busqueda) {
            $q->where('colaborador', 'like', "%$busqueda%")
              ->orWhere('cedula', 'like', "%$busqueda%");
        });
    }

    // 📅 Filtro por rango de fechas de nacimiento
    if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
        $query->whereBetween('fecha_ingreso', [
            $request->fecha_inicio,
            $request->fecha_fin
        ]);
    }

    // 🟢 Filtro por estad
    if ($request->filled('estado')) {
        $query->where('estado', $request->estado);
    }

    // 🔃 Obtener resultados ordenados
    $empleados = $query->orderBy('colaborador', 'asc')->get();

    return view('Disciplinas.Listas.Empleados', compact('empleados'));
}


   public function mostrarDisciplinasPositivas(Request $request)
    {
        $query = LlamadoAtencion::query();
        
        

        // 🔍 Filtro por fecha (si ambos campos tienen valor)
        if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
            $query->whereBetween('fecha_evento', [
                $request->fecha_inicio,
                $request->fecha_fin
            ]);
        }

        // 🔍 Filtro por texto (nombre, cédula o id)
        if ($request->filled('query')) {
            $busqueda = $request->input('query');
            $query->where(function($q) use ($busqueda) {
                $q->where('cedula', 'like', "%$busqueda%")
                  ->orWhere('nombre', 'like', "%$busqueda%")
                  ->orWhere('id', 'like', "%$busqueda%");
            });
        }
            if ($request->filled('estado')) {
        $query->where('estado', $request->estado);
    }


        // 🔃 Obtener resultados
        $LlamadoAtencion = $query->orderBy('created_at', 'desc')->get();

        return view('Disciplinas.Listas.Disciplinas_Positivas', compact('LlamadoAtencion'));
    }
    public function mostrarDisciplinasPositivasUsers(Request $request)
    {
        $LlamadoAtencion = collect(); // colección vacía por defecto
    
        if ($request->filled('query')) {
            $busqueda = $request->input('query');
    
            $query = LlamadoAtencion::query();
    
            // 🟢 Solo registros activos
            $query->where('estado', 'activo');
    
            // 🔍 Buscar coincidencia exacta por cédula
            $query->where(function ($q) use ($busqueda) {
                $q->where('cedula', '=', $busqueda);
            });
    
            // 🔃 Obtener resultados
            $LlamadoAtencion = $query->orderBy('created_at', 'desc')->get();
        }
    
        return view('Disciplinas.Listas.Disciplinas_Positivas_users', compact('LlamadoAtencion'));
    }

        public function eliminarDisciplina(Request $request)
    {
        $LlamadoAtencion = LlamadoAtencion::findOrFail($request->id);
        $LlamadoAtencion->estado = 3;
        $LlamadoAtencion->save();
        
        
        
    
        return redirect()->back()->with('success', 'Disciplina eliminada correctamente.');
    }
        public function restaurarDisciplina(Request $request)
    {
        $LlamadoAtencion = LlamadoAtencion::findOrFail($request->id);
        $LlamadoAtencion->estado = 1;
        $LlamadoAtencion->save();
        
        
        
    
        return redirect()->back()->with('success', 'Disciplina restaurada Correctamente');
    }
    
        public function MostrarEliminados(Request $request)
        {
            $query = LlamadoAtencion::query();
            $query->where('estado', 'eliminada');
            
            
        // 🔍 Filtro por fecha (si ambos campos tienen valor)
        if ($request->filled('fecha_inicio') && $request->filled('fecha_fin')) {
            $query->whereBetween('fecha_evento', [
                $request->fecha_inicio,
                $request->fecha_fin
            ]);
        }

        // 🔍 Filtro por texto (nombre, cédula o id)
        if ($request->filled('query')) {
            $busqueda = $request->input('query');
            $query->where(function($q) use ($busqueda) {
                $q->where('cedula', 'like', "%$busqueda%")
                  ->orWhere('nombre', 'like', "%$busqueda%")
                  ->orWhere('id', 'like', "%$busqueda%");
            });
        }

            $LlamadoAtencion = $query->orderBy('created_at', 'desc')->get();
            
            return view('Disciplinas.Listas.Disciplinas_Eliminadas', compact('LlamadoAtencion'));
            
        }


        public function exportarEmpleadosExcel(Request $request)
        {
            return Excel::download(new EmpleadosExport($request), 'Empleados.xlsx');
        }

        public function exportarDisciplinasExcel(Request $request)
        {
            return Excel::download(new DisciplinasPositivasExport($request), 'Disciplinas_Positivas.xlsx');
        }


}
