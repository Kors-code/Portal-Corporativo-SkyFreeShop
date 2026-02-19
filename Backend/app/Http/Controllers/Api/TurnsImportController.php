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
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Settings;

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

            $rows = $sheets[0]->skip(1);

            $errors = [];
            $imported = 0;

                        log::info('Cargando datos del archivo');

            foreach ($rows as $i => $row) {

                $fila = $i + 2;

                $mesNumero = (int) trim((string)($row[0] ?? ''));
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

                if (!$codigo) {
                    $errors[] = "Fila $fila: código vendedor vacío";
                    continue;
                }

                if ($turns < 0) {
                    $errors[] = "Fila $fila: turnos negativos";
                    continue;
                }

                $budget = Budget::whereMonth('start_date', '<=', $mesNumero)
                    ->whereMonth('end_date', '>=', $mesNumero)
                    ->first();

                if (!$budget) {
                    $errors[] = "Fila $fila: no hay presupuesto para mes $mesNumero";
                    continue;
                }

                $user = User::where('codigo_vendedor', $codigo)->first();

                if (!$user) {
                    $errors[] = "Fila $fila: vendedor $codigo no existe";
                    continue;
                }

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
