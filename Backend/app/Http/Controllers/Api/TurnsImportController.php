<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Comisiones\TurnsBatch;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\User;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Settings;
use Illuminate\Support\Str;

class TurnsImportController extends Controller
{
    public function index()
    {
        return TurnsBatch::orderByDesc('created_at')->get();
    }

    public function show($id)
    {
        return TurnsBatch::findOrFail($id);
    }

    public function import(Request $request)
    {



        
        log::info('Iniciando importación de turnos por mes');
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,xls'
            ]);
            
            log::info('Archivo recibido: '.$request->file('file')->getClientOriginalName());
        DB::connection('budget')->beginTransaction();

        try {

            log::info('Procesando filas del archivo');
            $file = $request->file('file');

            $batch = TurnsBatch::create([
                'name' => 'Import ' . now()->format('Y-m-d H:i'),
                'filename' => $file->getClientOriginalName(),
                'created_by' => auth()->id(), // puede ser null y está OK
                'status' => 'processing'
            ]);

            Calculation::getInstance()->setCalculationCacheEnabled(true);

            $sheets = Excel::toCollection(null, $file, null, \Maatwebsite\Excel\Excel::XLSX);

            if ($sheets->isEmpty() || $sheets[0]->isEmpty()) {
                throw new \Exception('El archivo está vacío');
            }

            $rows = $sheets[0]->skip(1)->values();

            $errors = [];
            $imported = 0;

                        log::info('Cargando datos del archivo');

            foreach ($rows as $i => $row) {

                $fila = $i + 2;

                $mesNumero = (int) trim((string)($row[0] ?? ''));
                $nombre    = trim((string)($row[1] ?? ''));
                $codigo    = trim((string)($row[2] ?? ''));
                $turns     = (int) ($row[3] ?? 0);
                $codigoRaw = trim((string)($row[2] ?? ''));

                if (str_starts_with($codigoRaw, '=')) {
                    DB::rollBack();

                    return response()->json([
                        'message' => "El archivo contiene fórmulas en la columna Código (ej: BUSCARV). Debe pegarse como VALORES antes de importar.",
                        'fila'    => $fila,
                        'valor_detectado' => $codigoRaw
                    ], 422);
                }

                $codigo = trim($codigoRaw);



                log::info("Procesando fila $fila: mes=$mesNumero, codigo=$codigo, turnos=$turns");

                if ($mesNumero < 1 || $mesNumero > 12) {
                    $errors[] = "Fila $fila: mes inválido ($mesNumero)";
                    continue;
                }

                if ($codigo === '') {
                    $errors[] = "Fila $fila: código vendedor vacío";
                    continue;
                }

                if ($turns < 0) {
                    $errors[] = "Fila $fila: turnos negativos";
                    continue;
                }

                $budget = Budget::whereMonth('start_date', '<=', $mesNumero)
                    ->whereMonth('end_date', '>=', $mesNumero)
                    ->orderByDesc('start_date')
                    ->first();

                if (!$budget) {
                    $errors[] = "Fila $fila: no hay presupuesto para mes $mesNumero";
                    continue;
                }

                if ($this->isNonAdvisorRow($nombre, $codigo)) {
                    $errors[] = "Fila $fila: fila informativa omitida ($nombre / $codigo)";
                    continue;
                }

                $user = User::where('codigo_vendedor', $codigo)->first();

                if (!$user) {
                    if (!$nombre) {
                        $errors[] = "Fila $fila: vendedor $codigo no existe y no hay nombre para crearlo";
                        continue;
                    }

                    $user = $this->findOrCreateAdvisor($nombre, $codigo);
                } elseif ($nombre && trim((string) $user->name) === '') {
                    $user->name = mb_substr($nombre, 0, 255);
                    $user->save();
                }

                $this->ensureAdvisorRoleForBudget((int) $user->id, $budget);

                DB::connection('budget')->table('budget_user_turns')->updateOrInsert(
                    [
                        'budget_id' => $budget->id,
                        'user_id'   => $user->id
                    ],
                    [
                        'assigned_turns' => $turns,
                        'batch_id'       => $batch->id,
                        'updated_at'     => now()
                    ]
                );

                $imported++;
            }

            $batch->update([
                'rows_imported'   => $imported,
                'rows_with_error' => count($errors),
                'errors'          => $errors,
                'status'          => 'completed'
            ]);

            DB::connection('budget')->commit();

            return response()->json([
                'message'  => 'Importación completada',
                'batch_id' => $batch->id,
                'rows'     => $imported,
                'errors'   => $errors
            ]);

        } catch (Throwable $e) {

            log::error('Error en importación de turnos: '.$e->getMessage());
            log::error($e->getLine());
            DB::connection('budget')->rollBack();

            return response()->json([
                'message' => 'Error procesando importación',
                'error'   => $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);

        }
    }

    private function isNonAdvisorRow(string $name, string $code): bool
    {
        $normalizedName = Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        return in_array($code, ['0', '40', '9999'], true)
            || str_contains($normalizedName, 'no seller')
            || str_contains($normalizedName, 'ventas mostrador')
            || str_contains($normalizedName, 'ventas divididas');
    }

    private function localEmailForAdvisor(string $name, string $code): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'asesor-' . $code;
        }

        return strtolower($slug . '@local');
    }

    private function findOrCreateAdvisor(string $name, string $code): User
    {
        $email = mb_substr($this->localEmailForAdvisor($name, $code), 0, 255);
        $name = mb_substr($name, 0, 255);

        $user = User::where('email', $email)->first();
        if ($user) {
            $changed = false;

            if (!$user->codigo_vendedor) {
                $user->codigo_vendedor = $code;
                $changed = true;
            }

            if (!$user->name) {
                $user->name = $name;
                $changed = true;
            }

            if ($changed) {
                $user->save();
            }

            return $user;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'codigo_vendedor' => $code,
        ]);
    }

    private function ensureAdvisorRoleForBudget(int $userId, Budget $budget): void
    {
        $roleId = DB::connection('budget')->table('roles')
            ->whereRaw('LOWER(name) = ?', ['vendedor'])
            ->value('id');

        if (!$roleId) {
            $rolePayload = [
                'name' => 'vendedor',
            ];

            if (Schema::connection('budget')->hasColumn('roles', 'created_at')) {
                $rolePayload['created_at'] = now();
            }

            if (Schema::connection('budget')->hasColumn('roles', 'updated_at')) {
                $rolePayload['updated_at'] = now();
            }

            $roleId = DB::connection('budget')->table('roles')->insertGetId($rolePayload);
        }

        $hasRoleInBudget = DB::connection('budget')->table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where(function ($roleQ) {
                $roleQ->whereRaw('LOWER(r.name) = ?', ['vendedor'])
                    ->orWhereRaw('LOWER(r.name) LIKE ?', ['asesor%']);
            })
            ->where(function ($dateQ) use ($budget) {
                $dateQ->whereNull('ur.start_date')
                    ->orWhere('ur.start_date', '<=', $budget->end_date);
            })
            ->where(function ($dateQ) use ($budget) {
                $dateQ->whereNull('ur.end_date')
                    ->orWhere('ur.end_date', '>=', $budget->start_date);
            })
            ->exists();

        if ($hasRoleInBudget) {
            return;
        }

        $userRolePayload = [
            'user_id' => $userId,
            'role_id' => $roleId,
            'start_date' => $budget->start_date,
            'end_date' => $budget->end_date,
        ];

        if (Schema::connection('budget')->hasColumn('user_roles', 'created_at')) {
            $userRolePayload['created_at'] = now();
        }

        if (Schema::connection('budget')->hasColumn('user_roles', 'updated_at')) {
            $userRolePayload['updated_at'] = now();
        }

        DB::connection('budget')->table('user_roles')->updateOrInsert(
            [
                'user_id' => $userId,
                'start_date' => $budget->start_date,
            ],
            $userRolePayload
        );
    }

    public function deleteBatch($batchId)
    {
        DB::connection('budget')->beginTransaction();

        try {
            $batch = TurnsBatch::findOrFail($batchId);

            DB::connection('budget')->table('budget_user_turns')
                ->where('batch_id', $batch->id)
                ->delete();

            $batch->delete();

            DB::connection('budget')->commit();

            return response()->json(['message' => 'Batch eliminado']);

        } catch (Throwable $e) {
            DB::connection('budget')->rollBack();
            return response()->json(['message' => 'Error eliminando', 'error'=>$e->getMessage()], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $ids = (array) $request->input('ids', []);

       DB::connection('budget')->beginTransaction();
        try {
            DB::connection('budget')->table('budget_user_turns')->whereIn('batch_id', $ids)->delete();
            TurnsBatch::whereIn('id', $ids)->delete();

            DB::connection('budget')->commit();

            return response()->json(['message' => 'Batches eliminados']);

        } catch (Throwable $e) {
            DB::connection('budget')->rollBack();
            return response()->json(['message' => 'Error eliminando', 'error'=>$e->getMessage()], 500);
        }
    }
}
