<?php

namespace App\Exports;

use App\Models\Personal\Empleado;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class EmpleadosExport implements FromCollection, WithHeadings
{
    protected $request;

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $query = Empleado::query();

        if ($this->request->filled('query')) {
            $busqueda = $this->request->input('query');
            $query->where(function ($q) use ($busqueda) {
                $q->where('colaborador', 'like', "%$busqueda%")
                  ->orWhere('cedula', 'like', "%$busqueda%");
            });
        }

        if ($this->request->filled('fecha_inicio') && $this->request->filled('fecha_fin')) {
            $query->whereBetween('fecha_ingreso', [
                $this->request->fecha_inicio,
                $this->request->fecha_fin
            ]);
        }

        if ($this->request->filled('estado')) {
            $query->where('estado', $this->request->estado);
        }

        return $query->select('id', 'colaborador', 'cedula', 'estado', 'rh', 'genero', 'fecha_nacimiento')->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Colaborador',
            'Cédula',
            'Estado',
            'RH',
            'Género',
            'Fecha de Nacimiento'
        ];
    }
}
