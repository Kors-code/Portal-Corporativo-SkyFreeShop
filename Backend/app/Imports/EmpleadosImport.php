<?php

namespace App\Imports;

use App\Models\Personal\Empleado;
use App\Models\Personal\cargo;
use App\Models\Personal\historialcargo;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;

class EmpleadosImport implements ToCollection, WithHeadingRow
{
    protected function normalizeKey($key)
    {
        if (is_null($key)) return null;

        $key = trim($key);

        // remover tildes
        $trans = [
            'Á'=>'A','À'=>'A','Â'=>'A','Ä'=>'A','á'=>'a','à'=>'a','â'=>'a','ä'=>'a',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'Ó'=>'O','Ò'=>'O','Ô'=>'O','Ö'=>'O','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'Ñ'=>'N','ñ'=>'n'
        ];
        $key = strtr($key, $trans);

        // minúsculas
        $key = mb_strtolower($key);

        // convertir espacios y no alfanum a _
        $key = preg_replace('/[^a-z0-9]+/u', '_', $key);
        return trim($key, '_');
    }

    protected function parseDate($value)
    {
        if ($value === null || $value === '') return null;

        if (is_numeric($value)) {
            try {
                return Carbon::instance(
                    ExcelDate::excelToDateTimeObject($value)
                )->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $rawRow) {

            // Normalizar keys
            $row = [];
            foreach ($rawRow->toArray() as $k => $v) {
                $row[$this->normalizeKey($k)] = $v;
            }

            // Obtener cédula
            $cedula = trim((string) ($row['cedula'] ?? ''));
            if ($cedula === '') continue;

            // Parseo de fechas
            $fechaIngreso    = $this->parseDate($row['fecha_ingreso'] ?? null);
            $fechaNacimiento = $this->parseDate($row['fecha_nacimiento'] ?? null);
            $fechaRetiro     = $this->parseDate($row['fecha_retiro'] ?? null);

            // Datos de empleado
            $empleadoData = array_filter([
                'colaborador'       => $row['colaborador'] ?? null,
                'email'             => $row['email'] ?? null,
                'contacto'          => $row['contacto'] ?? null,
                'ciudad_residencia' => $row['ciudad_residencia'] ?? null,
                'direccion'         => $row['direccion'] ?? null,
                'nivel_academico'   => $row['nivel_academico'] ?? null,
                'profesion'         => $row['profesion'] ?? null,
                'nivel_ingles'      => $row['nivel_ingles'] ?? null,
                'rh'                => $row['rh'] ?? null,
                'genero'            => $row['genero'] ?? null,
                'edad'              => is_numeric($row['edad'] ?? null) ? intval($row['edad']) : null,
                'fecha_nacimiento'  => $fechaNacimiento,
                'hijos'             => isset($row['hijos']) ? (bool) $row['hijos'] : null,
                'vehiculo'          => isset($row['vehiculo']) ? (bool) $row['vehiculo'] : null,
                'tipo_vivienda'     => $row['tipo_vivienda'] ?? null,
                'estrato'           => $row['estrato'] ?? null,
                'estado_civil'      => $row['estado_civil'] ?? null,
                'eps'               => $row['eps'] ?? null,
                'caja_pension'      => $row['caja_pension'] ?? null,
                'cesantias'         => $row['cesantias'] ?? null,
                'jefe_inmediato'    => $row['jefe_inmediato'] ?? null,
                'sede'              => $row['sede'] ?? null,
                'antiguedad'        => $row['antiguedad'] ?? null,
                'fecha_retiro'      => $fechaRetiro,
                'estado'            => $row['estado'] ?? null,
                'fecha_ingreso'     => $fechaIngreso,
            ], fn($v) => !is_null($v) && $v !== '');

            // Crear/actualizar empleado
            $empleado = Empleado::updateOrCreate(
                ['cedula' => $cedula],
                $empleadoData
            );

            // CARGO
            $cargoNombre = trim((string) ($row['cargo'] ?? ''));
            $cargoId = null;

            if ($cargoNombre !== '') {
                // UpdateOrCreate para actualizar si ya existe
                $cargo = Cargo::updateOrCreate(
                    ['nombre' => $cargoNombre],
                    [
                        'area'          => $row['area'] ?? null,
                        'funcion'       => $row['funcion'] ?? null,
                        'jornada'       => $row['jornada'] ?? null,
                        'tipo_contrato' => $row['tipo_contrato'] ?? null,
                    ]
                );
                $cargoId = $cargo->id;
            }

          // HISTORIAL – obtener historial activo del empleado
$hist = HistorialCargo::where('empleado_id', $empleado->id)
                      ->whereNull('fecha_retiro')
                      ->first();

$histData = array_filter([
    'fecha_ingreso' => $fechaIngreso,
    'fecha_retiro'  => $fechaRetiro,
    'area'          => $row['area'] ?? null,
    'funcion'       => $row['funcion'] ?? null,
    'jornada'       => $row['jornada'] ?? null,
    'tipo_contrato' => $row['tipo_contrato'] ?? null,
    'jefe_inmediato'=> $row['jefe_inmediato'] ?? null,
    'sede'          => $row['sede'] ?? null,
    'estado'        => $row['estado'] ?? null,
    'antiguedad'    => $row['antiguedad'] ?? null,
    'causa_retiro'  => $row['causa_retiro'] ?? null,
    'motivo_retiro' => $row['motivo_retiro'] ?? null,
], fn($v) => !is_null($v) && $v !== '');

if ($hist) {
    // actualizar cargo y otros datos
    $hist->cargo_id = $cargoId;
    $hist->update($histData);
} else {
    // crear historial nuevo
    HistorialCargo::create(array_merge(
        [
            'empleado_id' => $empleado->id,
            'cargo_id' => $cargoId
        ],
        $histData
    ));
}

        }
    }
}
