<?php

namespace App\Http\Controllers\PersonalController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\EmpleadosImport;
use App\Models\Personal\Empleado;


class EmpleadoController extends Controller
{
    protected $connection = 'mysql_personal';
    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:10240',
        ]);
    
        try {
            Excel::import(new EmpleadosImport, $request->file('file'));
        } catch (\Throwable $e) {
            \Log::error('Error importando empleados: ' . $e->getMessage());
            return back()->with('error', 'Error al importar: ' . $e->getMessage());
        }
    
        return back()->with('success', 'Importación completada correctamente.');
    }

    public function buscarPorCedula($cedula)
{
    $empleado = Empleado::on('mysql_personal')->where('cedula', $cedula)->first();
    


    if (!$empleado) {
        return response()->json([
            'success' => false,
            'message' => 'Empleado no encontrado'
        ], 404);
    }

    // Buscar el último cargo registrado del empleado
    $historial = $empleado->historialCargos()
        ->with('cargo') // para traer el nombre del cargo desde la relación
        ->latest('fecha_ingreso')
        ->first();

    return response()->json([
        'success' => true,
        'nombre' => $empleado->colaborador,
        'cargo' => $historial && $historial->cargo ? $historial->cargo->nombre : 'Sin cargo registrado'
    ]);
}
}
