<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class AssignTurnsByMonthImport implements ToCollection, WithHeadingRow
{
    public array $errors = [];

    protected int $TOTAL_TURNS_FALLBACK = 315;

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {

            $rowNumber = $index + 2;

            $mes = trim($row['mes'] ?? '');
            $codigo = trim($row['codigo'] ?? '');
            $turnos = (int) ($row['turnos'] ?? 0);

            // ============================
            // VALIDACIONES BÁSICAS
            // ============================

            if ($mes === '' || $codigo === '') {
                $this->errors[] = "Fila {$rowNumber}: Mes o Codigo vacío";
                continue;
            }

            if (!is_numeric($mes) || $mes < 1 || $mes > 12) {
                $this->errors[] = "Fila {$rowNumber}: Mes inválido (debe ser número 1-12)";
                continue;
            }

            if ($turnos < 0) {
                $this->errors[] = "Fila {$rowNumber}: Turnos no puede ser negativo";
                continue;
            }

            $mes = (int) $mes;

            // ============================
            // 1️⃣ BUSCAR BUDGET POR MES (AÑO ACTUAL)
            // ============================

            $year = now()->year;

            $budget = DB::connection('budget')
                ->table('budgets')
                ->whereMonth('start_date', $mes)
                ->whereYear('start_date', $year)
                ->first();

            if (!$budget) {
                $this->errors[] = "Fila {$rowNumber}: No existe budget para el mes {$mes}/{$year}";
                continue;
            }

            $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS_FALLBACK;

            // ============================
            // 2️⃣ BUSCAR VENDEDOR
            // ============================

            $user = DB::connection('budget')
                ->table('users')
                ->where('codigo_vendedor', $codigo)
                ->first();

            if (!$user) {
                $this->errors[] = "Fila {$rowNumber}: vendedor con código {$codigo} no existe";
                continue;
            }

            // ============================
            // 3️⃣ VALIDAR DISPONIBILIDAD
            // ============================

            $totalAssignedExcept = DB::connection('budget')
                ->table('budget_user_turns')
                ->where('budget_id', $budget->id)
                ->where('user_id', '!=', $user->id)
                ->sum('assigned_turns');

            if ($totalAssignedExcept + $turnos > $totalTurns) {
                $available = max(0, $totalTurns - $totalAssignedExcept);
                $this->errors[] = "Fila {$rowNumber}: No hay suficientes turnos disponibles. Máximo disponible: {$available}";
                continue;
            }

            // ============================
            // 4️⃣ ACTUALIZAR (MISMA LÓGICA assignTurns)
            // ============================

            DB::connection('budget')
                ->table('budget_user_turns')
                ->updateOrInsert(
                    [
                        'budget_id' => $budget->id,
                        'user_id'   => $user->id
                    ],
                    [
                        'assigned_turns' => $turnos,
                        'updated_at'     => now(),
                        'created_at'     => now(),
                    ]
                );
        }
    }
}