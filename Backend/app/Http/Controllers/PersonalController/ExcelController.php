<?php

namespace App\Http\Controllers\PersonalController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Imports\EmpleadosImport;
use Maatwebsite\Excel\Facades\Excel;

class ExcelController extends Controller
{
    public function showForm()
    {
        return view('Disciplinas.Subir_Datos.Subir_Personal');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:5120',
        ]);

        try {
            Excel::import(new EmpleadosImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Error importando: ' . $e->getMessage()]);
        }

        return back()->with('success', 'Importación completada correctamente.');
    }
}
