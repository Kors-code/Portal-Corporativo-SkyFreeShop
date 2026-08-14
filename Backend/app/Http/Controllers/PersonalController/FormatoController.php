<?php

namespace App\Http\Controllers\PersonalController;
use App\Http\Controllers\Controller; 
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Personal\LlamadoAtencion;
use App\Models\Personal\Empleado;

class FormatoController extends Controller
{
    private const DISCIPLINAS_TEST_RECIPIENTS = [
        'sebastian.cruz@dutyfreepartners.com',
    ];

    private const DISCIPLINAS_PRODUCTION_RECIPIENTS = [
        'Luisa.Grajales@dutyfreepartners.com',
        'Liliana.Otalvaro@dutyfreepartners.com',
        'Maria.Giraldo@dutyfreepartners.com',
    ];

    protected $connection = 'mysql_personal';
    public function generarPDF(Request $request)
    {
    

    $firmaEmpleado = $request->input('firma_empleado');
    $firmaJefe = $request->input('firma_jefe');
    $Proceso = $request->input('Proceso');

    // Guardarlas en la sesión temporalmente
    session([
        'firma_empleado' => $firmaEmpleado,
        'firma_jefe' => $firmaJefe,
        'Proceso' => $Proceso,
    ]);

        $validated = $request->validate([
            'fecha' => 'required|date',
            'Proceso' => 'required|string|max:255',
            'cedula' => 'required|string|max:50',
            'nombre' => 'required|string|max:255',
            'cargo' => 'required|string|max:255',
            'fecha_evento' => 'required|date',
            'hora' => 'required|string|max:20',
            'fase' => 'required|string|max:255',
            'detalle' => 'nullable|string',
            'jefe' => 'required|string|max:255',
            'jefe_cedula' => 'required|string|max:50',
            'cargo_jefe' => 'required|string|max:255',
            'firma_empleado' => 'required',
            'firma_jefe' => 'required',
        ]);

        session($validated);
        // Si el formulario envía "accion = nueva", borramos la sesión y devolvemos el formulario limpio
        if ($request->input('accion') === 'nueva') {
            session()->forget('llamado_actual_id');
            session()->forget('firma_empleado');
            session()->forget('firma_jefe');
            session()->forget('Proceso');
            session()->forget(array_keys($validated));
            // redirigir sin registrar nada y sin valores 'old' (formulario limpio)
            return redirect()->back()->with('success', 'Lista para nueva disciplina ✅');
        }

        // Obtener empleado
        $empleado = Empleado::where('cedula', $validated['cedula'])->firstOrFail();

        // Preparar datos para PDF & DB
        $codigo = substr($validated['Proceso'], 0, 3);
        $detalleProceso = substr($validated['Proceso'], 4);
        $data = [
            'fecha' => $validated['fecha'],
            'orientacion' => $detalleProceso, 
            'cedula' => $validated['cedula'],
            'nombre' => $validated['nombre'],
            'cargo' => $validated['cargo'],
            'fecha_evento' => $validated['fecha_evento'],
            'hora' => $validated['hora'],
            'fase' => $validated['fase'],
            'detalle' => $validated['detalle'],
            'jefe' => $validated['jefe'],
            'jefe_cedula' => $validated['jefe_cedula'],
            'cargo_jefe' => $validated['cargo_jefe'],
            'firma_empleado' => $validated['firma_empleado'],
            'firma_jefe' => $validated['firma_jefe'],
            'grupo' => $codigo,
        ];
        
        
        
                $vistaPDF = match ($validated['fase']) {
                    '1' => 'plantillas.plantillaFase1',
                    '2' => 'plantillas.plantillaFase2',
                    '3' => 'plantillas.plantillaFase3',
                    '4' => 'plantillas.plantillaFase4',
                    default  => 'plantillas.plantillaFase1',
                };



        // Generar PDF
        $pdf = Pdf::loadView($vistaPDF, compact('data'))
          ->setPaper('A4', 'portrait');

        // Guardar PDF en storage/app/llamados
        Storage::disk('local')->makeDirectory('llamados');
        $fileName = 'llamados/llamado_' . $empleado->cedula . '_' . now()->format('Ymd_His') . '.pdf';
        Storage::disk('local')->put($fileName, $pdf->output());

        // Lógica: si existe session('llamado_actual_id') -> actualizar ese registro
        // si no existe -> crear nuevo y guardar su id en sesión.
        $llamadoId = session('llamado_actual_id');
        $llamado = null;

        if ($llamadoId) {
            $llamado = LlamadoAtencion::find($llamadoId);
            if ($llamado) {
                // Actualizar existente
                $llamado->update([
                    'empleado_id' => $empleado->id,
                    'fecha' => $validated['fecha'],
                    'orientacion' => $detalleProceso,
                    'nombre' => $validated['nombre'],
                    'cedula' => $validated['cedula'],
                    'jefe' => $validated['jefe'],
                    'jefe_cedula' => $validated['jefe_cedula'],
                    'cargo_jefe' => $validated['cargo_jefe'],
                    'cargo' => $validated['cargo'],
                    'fecha_evento' => $validated['fecha_evento'],
                    'hora' => $validated['hora'],
                    'fase' => $validated['fase'],
                    'grupo' => $codigo,
                    'detalle' => $validated['detalle'],
                    'ruta_pdf' => $fileName,
                ]);
            } else {
                // Si por alguna razón el ID guardado no existe, creamos uno nuevo
                $llamado = LlamadoAtencion::create([
                    'empleado_id' => $empleado->id,
                    'fecha' => $validated['fecha'],
                    'nombre' => $validated['nombre'],
                    'cedula' => $validated['cedula'],
                    'jefe' => $validated['jefe'],
                    'jefe_cedula' => $validated['jefe_cedula'],
                    'cargo_jefe' => $validated['cargo_jefe'],
                    'cargo' => $validated['cargo'],
                    'fecha_evento' => $validated['fecha_evento'],
                    'hora' => $validated['hora'],
                    'fase' => $validated['fase'],
                    'orientacion' => $detalleProceso,
                    'detalle' => $validated['detalle'],
                    'ruta_pdf' => $fileName,
                    'grupo' => $codigo,
                ]);
                session(['llamado_actual_id' => $llamado->id]);
            }
        } else {
            // Crear nuevo y guardar su id en sesión
            $llamado = LlamadoAtencion::create([
                'empleado_id' => $empleado->id,
                'fecha' => $validated['fecha'],
                'nombre' => $validated['nombre'],
                'cedula' => $validated['cedula'],
                'jefe' => $validated['jefe'],
                'jefe_cedula' => $validated['jefe_cedula'],
                'cargo_jefe' => $validated['cargo_jefe'],
                'cargo' => $validated['cargo'],
                'fecha_evento' => $validated['fecha_evento'],
                'hora' => $validated['hora'],
                'fase' => $validated['fase'],
                'orientacion' => $detalleProceso,
                'detalle' => $validated['detalle'],
                'ruta_pdf' => $fileName,
                'grupo' => $codigo,
            ]);
            session(['llamado_actual_id' => $llamado->id]);
        }

        if ($llamado) {
            $this->notificarDisciplinaPositiva($llamado, $empleado, $fileName);
        }

        // Preparar respuesta (descarga o volver con success)
        // ... después de guardar/crear/actualizar el llamado y generar $fileName
        




// Preparar respuesta (antes devolvías response()->download directamente)
$path = Storage::disk('local')->path($fileName);

// En lugar de devolver el PDF directamente, volvemos con input y con pdf_path.
// Así la vista podrá mostrar la alerta y disparar la descarga desde JS.
if ($request->input('accion') === 'aplicar') {
    return back()
        ->withInput()
        ->with('aplicar', 'Disciplina Positiva Aplicada con éxito ✅')
        ->with('pdf_pathLink', $fileName);
}
if ($request->has('download')) {
    return back()
        ->withInput()
        ->with('success', 'Disciplina Positiva Aplicada con éxito ✅')
        ->with('pdf_path', $fileName);
}

// caso normal: aplicar sin descargar
return back()
    ->withInput()
    ->with('success', 'Disciplina Positiva Aplicada con éxito ✅')
    ->with('pdf_path', $fileName ?? null);

    }

    public function descargarPDF(Request $request)
    {
        $path = (string) $request->query('path', '');

        if (!preg_match('/^llamados\/llamado_[A-Za-z0-9_-]+_[0-9]{8}_[0-9]{6}\.pdf$/', $path)) {
            abort(404, 'Archivo no encontrado');
        }

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Archivo no encontrado');
        }

        $basePath = realpath(Storage::disk('local')->path('llamados'));
        $filePath = realpath(Storage::disk('local')->path($path));

        if (!$basePath || !$filePath || !str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR)) {
            abort(404, 'Archivo no encontrado');
        }

        return response()->download($filePath);
    }
public function importarExcel(Request $request)
{
    $request->validate([
        'archivo_excel' => 'required|file|mimes:xlsx,csv',
    ]);

    $file = $request->file('archivo_excel');
    $data = Excel::toArray([], $file)[0]; // Toma la primera hoja

    $errores = [];
    $insertados = 0;

    foreach ($data as $index => $row) {
        // Saltar encabezado
        if ($index === 0) continue;

        try {
            // Limpiar espacios
            $cedula = trim($row[2] ?? '');
            $fechaEvento = isset($row[0]) ? trim($row[0]) : null;

            $validator = Validator::make([
                'fecha_evento' => $fechaEvento,
                'cedula' => $cedula,
                'nombre' => $row[3] ?? null,
            ], [
                'fecha_evento' => 'required|date',
                'cedula' => 'required',
                'nombre' => 'required',
            ]);

            if ($validator->fails()) {
                $errores[] = "Fila " . ($index + 1) . ": " . implode(', ', $validator->errors()->all());
                continue;
            }

            // Buscar empleado por cédula (sin espacios)
            $empleado = Empleado::where('cedula', $cedula)->first();

            if (!$empleado) {
                $errores[] = "Fila " . ($index + 1) . ": No se encontró el empleado con cédula {$cedula}";
                continue;
            }

            // Crear el llamado
            $llamado = LlamadoAtencion::create([
                'empleado_id'   => $empleado->id,
                'fecha_evento' => $this->convertirFechaExcel($row[0] ?? null),
                'fase'          => $row[1] ?? null,
                'cedula'        => $cedula,
                'nombre'        => $row[3] ?? null,
                'cargo'         => $row[4] ?? null,
                'grupo'         => $row[5] ?? null, 
                'jefe'          => $row[6] ?? null, 
                'orientacion'       => $row[7] ?? null, // descripción
                'fecha'         => !empty($row[8]) ? $row[8] : now()->format('Y-m-d'),
            ]);
            
            // 🔹 Generar PDF automáticamente con los datos importados
            $this->generarPDFDesdeModelo($llamado, $empleado);
            
            $insertados++;


        } catch (\Throwable $e) {
            Log::error("Error en fila " . ($index + 1) . ": " . $e->getMessage());
            $errores[] = "Fila " . ($index + 1) . ": Error inesperado. " . $e->getMessage();
        }
    }

    if (!empty($errores)) {
        return back()
            ->with('import_errors', $errores)
            ->with('import_success', "Se importaron $insertados registros correctamente, con algunos errores.");
    }

    return back()->with('import_success', "✅ Se importaron $insertados registros correctamente.");
}
private function convertirFechaExcel($value)
{
    if (!$value) return null;

    // Si viene como número serial de Excel
    if (is_numeric($value)) {
        return \Carbon\Carbon::createFromTimestamp(($value - 25569) * 86400)
                ->format('Y-m-d');
    }

    // Si viene como texto tipo 01/07/2025
    try {
        return \Carbon\Carbon::parse($value)->format('Y-m-d');
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Genera el PDF a partir de un modelo existente.
 */
private function generarPDFDesdeModelo(LlamadoAtencion $llamado, Empleado $empleado)
{
    $data = [
        'fecha' => $llamado->fecha,
        'orientacion' => $llamado->orientacion,
        'cedula' => $llamado->cedula,
        'nombre' => $llamado->nombre,
        'cargo' => $llamado->cargo,
        'fecha_evento' => $llamado->fecha_evento,
        'hora' => $llamado->hora ?? '',
        'fase' => $llamado->fase,
        'detalle' => $llamado->detalle,
        'jefe' => $llamado->jefe,
        'jefe_cedula' => $llamado->jefe_cedula ?? '',
        'cargo_jefe' => $llamado->cargo_jefe ?? '',
        'firma_empleado' => session('firma_empleado'),
        'firma_jefe' => session('firma_jefe'),
        'grupo' => $llamado->grupo,
    ];

    $vistaPDF = match ($llamado->fase) {
    '1' => 'plantillas.plantillaFase1',
    '2' => 'plantillas.plantillaFase2',
    '3' => 'plantillas.plantillaFase3',
    '4' => 'plantillas.plantillaFase4',
    default  => 'plantillas.plantillaFase1',
    };
    
    $pdf = Pdf::loadView($vistaPDF, compact('data'))
              ->setPaper('A4', 'portrait');


    // Guardar PDF
    Storage::disk('local')->makeDirectory('llamados');
    $fileName = 'llamados/llamado_' . $empleado->cedula . '_' . now()->format('Ymd_His') . '.pdf';
    Storage::disk('local')->put($fileName, $pdf->output());

    // Guardar ruta en BD
    $llamado->update(['ruta_pdf' => $fileName]);

    $this->notificarDisciplinaPositiva($llamado->fresh(), $empleado, $fileName);

    return $fileName;
}

private function notificarDisciplinaPositiva(?LlamadoAtencion $llamado, Empleado $empleado, string $fileName): bool
{
    if (!$llamado || !Storage::disk('local')->exists($fileName)) {
        return false;
    }

    $recipients = $this->disciplinasRecipients();
    if (empty($recipients)) {
        Log::warning('No hay destinatarios configurados para disciplina positiva', [
            'llamado_id' => $llamado->id,
        ]);
        return false;
    }

    $pdfContent = Storage::disk('local')->get($fileName);
    $pdfName = basename($fileName);
    $isTest = $this->disciplinasMailTestMode();
    $subjectPrefix = $isTest ? '[PRUEBA] ' : '';

    try {
        Mail::mailer('smtp')->send([], [], function (Message $message) use ($recipients, $llamado, $empleado, $pdfContent, $pdfName, $subjectPrefix, $isTest) {
            $message
                ->from(config('mail.from.address'), 'Sky Free Shop - Disciplinas')
                ->replyTo(config('mail.from.address'), 'No Reply')
                ->to($recipients)
                ->subject($subjectPrefix . 'Disciplina positiva aplicada - ' . ($llamado->nombre ?? $empleado->colaborador ?? $empleado->cedula))
                ->html($this->disciplinaEmailHtml($llamado, $empleado, $isTest))
                ->attachData($pdfContent, $pdfName, ['mime' => 'application/pdf']);
        });

        Log::info('Correo de disciplina positiva enviado', [
            'llamado_id' => $llamado->id,
            'recipients' => $recipients,
            'test' => $isTest,
        ]);

        return true;
    } catch (\Throwable $e) {
        Log::error('Error enviando correo de disciplina positiva', [
            'llamado_id' => $llamado->id,
            'recipients' => $recipients,
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}

private function disciplinasRecipients(): array
{
    $recipients = $this->disciplinasMailTestMode()
        ? self::DISCIPLINAS_TEST_RECIPIENTS
        : self::DISCIPLINAS_PRODUCTION_RECIPIENTS;

    return array_values(array_filter($recipients, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));
}

private function disciplinasMailTestMode(): bool
{
    return filter_var(env('DISCIPLINAS_POSITIVAS_MAIL_TEST', true), FILTER_VALIDATE_BOOL);
}

private function disciplinaEmailHtml(LlamadoAtencion $llamado, Empleado $empleado, bool $isTest): string
{
    $badge = $isTest
        ? "<p style='margin:0 0 18px;padding:10px 12px;background:#fef3c7;border-radius:8px;color:#92400e;font-weight:700;'>Correo de prueba: por ahora solo se envia a Sebastian Cruz.</p>"
        : '';

    $nombre = e($llamado->nombre ?? $empleado->colaborador ?? '');
    $cedula = e($llamado->cedula ?? $empleado->cedula ?? '');
    $fase = e((string) ($llamado->fase ?? ''));
    $fecha = e((string) ($llamado->fecha ?? now()->format('Y-m-d')));
    $jefe = e((string) ($llamado->jefe ?? ''));
    $orientacion = e((string) ($llamado->orientacion ?? ''));

    return "
        <div style='font-family:Arial,Helvetica,sans-serif;color:#1f2937;line-height:1.5;'>
            {$badge}
            <p>Hola,</p>
            <p>Se aplico una disciplina positiva y el PDF generado va adjunto para su revision y archivo.</p>
            <table role='presentation' cellspacing='0' cellpadding='0' style='border-collapse:collapse;margin:18px 0;width:100%;max-width:620px;'>
                <tr><td style='padding:8px 10px;background:#f9fafb;font-weight:700;'>Colaborador</td><td style='padding:8px 10px;background:#f9fafb;'>{$nombre}</td></tr>
                <tr><td style='padding:8px 10px;font-weight:700;'>Cedula</td><td style='padding:8px 10px;'>{$cedula}</td></tr>
                <tr><td style='padding:8px 10px;background:#f9fafb;font-weight:700;'>Fase</td><td style='padding:8px 10px;background:#f9fafb;'>{$fase}</td></tr>
                <tr><td style='padding:8px 10px;font-weight:700;'>Fecha</td><td style='padding:8px 10px;'>{$fecha}</td></tr>
                <tr><td style='padding:8px 10px;background:#f9fafb;font-weight:700;'>Jefe</td><td style='padding:8px 10px;background:#f9fafb;'>{$jefe}</td></tr>
                <tr><td style='padding:8px 10px;font-weight:700;'>Orientacion</td><td style='padding:8px 10px;'>{$orientacion}</td></tr>
            </table>
            <p style='color:#6b7280;font-size:13px;'>Mensaje automatico del sistema de disciplinas positivas.</p>
        </div>";
}

}
