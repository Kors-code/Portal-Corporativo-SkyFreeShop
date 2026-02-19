<?php

namespace App\Imports;

use App\Models\Usuario;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UsuariosImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Usuario([
            'cedula'   => $row['cedula'],
            'colaborador'   => $row['colaborador'],  
            'email'    => $row['email'],
            'cargo' => $row['cargo'],
            'contacto' => $row['contacto'],
        ]);
    }
}
