<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entrega;
use App\Models\EntregaLog;
use App\Models\Empleado;
use App\Models\FirmaDigital;
use App\Models\Novedad;
use App\Services\EntregaPdfService;
use App\Services\EntregaMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EntregaController extends Controller
{
    protected EntregaPdfService $pdfService;
    protected EntregaMailService $mailService;

    public function __construct(EntregaPdfService $pdfService, EntregaMailService $mailService)
    {
        $this->pdfService = $pdfService;
        $this->mailService = $mailService;
    }

    /**
     * GET /api/entregas
     * Listar todas las entregas con filtros
     */
    public function index(Request $request)
    {
        $query = Entrega::with([
            'liderEntrega:id,colaborador,email',
            'liderRecibe:id,colaborador,email',
            'novedades',
            'firmaEntrega',
            'firmaRecepcion',
        ]);

        // Filtros
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('lider_id')) {
            $query->paraLider($request->lider_id);
        }

        if ($request->filled('tipo')) {
            if ($request->tipo === 'entrega') {
                $query->entregadasPor($request->lider_id);
            } elseif ($request->tipo === 'recepcion') {
                $query->recibidasPor($request->lider_id);
            }
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_acta', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_acta', '<=', $request->fecha_hasta);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo_acta', 'LIKE', "%{$search}%")
                  ->orWhere('nombre_acta', 'LIKE', "%{$search}%")
                  ->orWhereHas('liderEntrega', fn($q2) => $q2->where('colaborador', 'LIKE', "%{$search}%"))
                  ->orWhereHas('liderRecibe', fn($q2) => $q2->where('colaborador', 'LIKE', "%{$search}%"));
            });
        }

        $entregas = $query->orderBy('created_at', 'desc')
                          ->paginate($request->get('per_page', 15));

        return response()->json($entregas);
    }

    /**
     * GET /api/entregas/lideres
     * Listar líderes disponibles (empleados activos)
     */
    public function lideres()
    {
        $lideres = Empleado::lideres()
            ->select('id', 'colaborador', 'cedula', 'email', 'sede', 'jefe_inmediato')
            ->orderBy('colaborador')
            ->get();

        return response()->json($lideres);
    }

    /**
     * GET /api/entregas/categorias
     * Devolver categorías y opciones predefinidas
     */
    public function categorias()
    {
        return response()->json([
            'categorias' => Novedad::$categorias,
            'prioridades' => Novedad::$prioridades,
        ]);
    }

    /**
     * GET /api/entregas/dashboard
     * Estadísticas dashboard del líder
     */
    public function dashboard(Request $request)
    {
        $empleadoId = $request->get('empleado_id');

        if (!$empleadoId) {
            return response()->json(['error' => 'empleado_id requerido'], 422);
        }

        $stats = [
            'entregas_realizadas' => Entrega::entregadasPor($empleadoId)->count(),
            'entregas_completadas' => Entrega::entregadasPor($empleadoId)->where('estado', 'completada')->count(),
            'entregas_pendientes_firma' => Entrega::entregadasPor($empleadoId)->where('estado', 'abierta')->count(),
            'recibidas_pendientes' => Entrega::recibidasPor($empleadoId)
                ->whereIn('estado', ['abierta', 'entregada'])->count(),
            'recibidas_completadas' => Entrega::recibidasPor($empleadoId)->where('estado', 'completada')->count(),
        ];

        $entregasRecientes = Entrega::with(['liderEntrega:id,colaborador', 'liderRecibe:id,colaborador'])
            ->paraLider($empleadoId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => $stats,
            'recientes' => $entregasRecientes,
        ]);
    }

    /**
     * POST /api/entregas
     * Crear una nueva acta de entrega con novedades
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'lider_entrega_id' => 'required|exists:empleados,id',
            'lider_recibe_id' => 'required|exists:empleados,id|different:lider_entrega_id',
            'turno' => 'required|in:mañana,tarde,noche',
            'fecha_acta' => 'required|date',
            'sede' => 'nullable|string|max:100',
            'observaciones' => 'nullable|string|max:2000',
            'novedades' => 'required|array|min:1',
            'novedades.*.categoria' => 'required|in:precios_promociones,logistica,cajas,personal,otros_temas,temas_pendientes',
            'novedades.*.titulo' => 'nullable|string|max:255',
            'novedades.*.descripcion' => 'required|string|max:2000',
            'novedades.*.prioridad' => 'nullable|in:baja,media,alta,urgente',
            'novedades.*.requiere_seguimiento' => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            $entrega = Entrega::create([
                'lider_entrega_id' => $validated['lider_entrega_id'],
                'lider_recibe_id' => $validated['lider_recibe_id'],
                'turno' => $validated['turno'],
                'fecha_acta' => $validated['fecha_acta'],
                'sede' => $validated['sede'] ?? null,
                'observaciones' => $validated['observaciones'] ?? null,
                'estado' => 'abierta',
            ]);

            foreach ($validated['novedades'] as $index => $novedadData) {
                Novedad::create([
                    'entrega_id' => $entrega->id,
                    'categoria' => $novedadData['categoria'],
                    'titulo' => $novedadData['titulo'] ?? null,
                    'descripcion' => $novedadData['descripcion'],
                    'prioridad' => $novedadData['prioridad'] ?? 'media',
                    'requiere_seguimiento' => $novedadData['requiere_seguimiento'] ?? false,
                    'orden' => $index,
                ]);
            }

            // Log de creación
            EntregaLog::create([
                'entrega_id' => $entrega->id,
                'empleado_id' => $validated['lider_entrega_id'],
                'accion' => 'created',
                'detalles' => 'Acta de entrega creada',
                'ip_address' => $request->ip(),
            ]);

            DB::commit();

            $entrega->load(['liderEntrega', 'liderRecibe', 'novedades']);

            return response()->json([
                'message' => 'Acta creada exitosamente',
                'entrega' => $entrega,
            ], 201);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Error creando entrega', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/entregas/{id}
     * Ver detalle de una entrega
     */
    public function show($id)
    {
        $entrega = Entrega::with([
            'liderEntrega',
            'liderRecibe',
            'novedades',
            'firmaEntrega.empleado:id,colaborador,cedula',
            'firmaRecepcion.empleado:id,colaborador,cedula',
            'logs.empleado:id,colaborador',
        ])->findOrFail($id);

        return response()->json($entrega);
    }

    /**
     * POST /api/entregas/{id}/firmar
     * Firmar entrega (como quien entrega o quien recibe)
     */
    public function firmar(Request $request, $id)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
            'tipo_firma' => 'required|in:entrega,recepcion',
            'firma_data' => 'required|string',
            'formato' => 'nullable|in:svg,png,base64',
            'usar_firma_guardada' => 'nullable|boolean',
        ]);

        $entrega = Entrega::findOrFail($id);
        $empleadoId = (int) $validated['empleado_id'];
        $tipoFirma = $validated['tipo_firma'];

        // Validar autorización
        if ($tipoFirma === 'entrega' && !$entrega->puedeFirmarComoEntrega($empleadoId)) {
            return response()->json([
                'error' => 'No estás autorizado a firmar como entrega o ya fue firmada'
            ], 403);
        }

        if ($tipoFirma === 'recepcion' && !$entrega->puedeFirmarComoRecepcion($empleadoId)) {
            return response()->json([
                'error' => 'No puedes firmar como recepción todavía. El otro líder debe firmar primero.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $firmaData = $validated['firma_data'];

            // Si quiere usar firma guardada, traerla del empleado
            if (!empty($validated['usar_firma_guardada'])) {
                $empleado = Empleado::find($empleadoId);
                if ($empleado && $empleado->firma_personal) {
                    $firmaData = $empleado->firma_personal;
                }
            }

            $firma = FirmaDigital::create([
                'entrega_id' => $entrega->id,
                'empleado_id' => $empleadoId,
                'tipo_firma' => $tipoFirma,
                'firma_data' => $firmaData,
                'formato' => $validated['formato'] ?? 'base64',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'fecha_firma' => now(),
            ]);

            // Guardar como firma personal del empleado para futuros usos
            if (empty($validated['usar_firma_guardada'])) {
                Empleado::where('id', $empleadoId)->update([
                    'firma_personal' => $firmaData
                ]);
            }

            // Actualizar estado de la entrega
            if ($tipoFirma === 'entrega') {
                $entrega->update([
                    'estado' => 'entregada',
                    'fecha_entrega' => now(),
                ]);

                // Enviar correo al líder que recibe
                $this->mailService->notificarLiderReceptor($entrega->fresh());

            } else { // recepcion
                $entrega->update([
                    'estado' => 'completada',
                    'fecha_recepcion' => now(),
                ]);

                // Generar PDF final
                $pdfPath = $this->pdfService->generar($entrega->fresh());
                $entrega->update(['pdf_path' => $pdfPath]);

                // Notificar al líder que entregó
                $this->mailService->notificarCierreActa($entrega->fresh());
            }

            // Log
            EntregaLog::create([
                'entrega_id' => $entrega->id,
                'empleado_id' => $empleadoId,
                'accion' => 'signed_' . $tipoFirma,
                'detalles' => 'Firma capturada',
                'ip_address' => $request->ip(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Firma registrada exitosamente',
                'entrega' => $entrega->fresh()->load(['liderEntrega', 'liderRecibe', 'novedades', 'firmaEntrega', 'firmaRecepcion']),
            ]);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Error firmando entrega', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/entregas/{id}/rechazar
     * Rechazar una entrega
     */
    public function rechazar(Request $request, $id)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:empleados,id',
            'razon_rechazo' => 'required|string|max:500',
        ]);

        $entrega = Entrega::findOrFail($id);

        if ($entrega->lider_recibe_id !== (int) $validated['empleado_id']) {
            return response()->json(['error' => 'No autorizado para rechazar'], 403);
        }

        $entrega->update([
            'estado' => 'rechazada',
            'razon_rechazo' => $validated['razon_rechazo'],
        ]);

        EntregaLog::create([
            'entrega_id' => $entrega->id,
            'empleado_id' => $validated['empleado_id'],
            'accion' => 'rejected',
            'detalles' => $validated['razon_rechazo'],
            'ip_address' => $request->ip(),
        ]);

        $this->mailService->notificarRechazo($entrega->fresh());

        return response()->json([
            'message' => 'Entrega rechazada',
            'entrega' => $entrega->fresh(),
        ]);
    }

    /**
     * POST /api/entregas/{id}/observacion-novedad
     * Receptor agrega observaciones a una novedad
     */
    public function agregarObservacionNovedad(Request $request, $id, $novedadId)
    {
        $validated = $request->validate([
            'observaciones_receptor' => 'required|string|max:1000',
        ]);

        $entrega = Entrega::findOrFail($id);
        $novedad = Novedad::where('entrega_id', $entrega->id)
                          ->where('id', $novedadId)
                          ->firstOrFail();

        $novedad->update([
            'observaciones_receptor' => $validated['observaciones_receptor'],
        ]);

        return response()->json([
            'message' => 'Observación agregada',
            'novedad' => $novedad,
        ]);
    }

    /**
     * GET /api/entregas/{id}/pdf
     * Descargar PDF de la entrega
     */
    public function descargarPdf($id)
    {
        $entrega = Entrega::with([
            'liderEntrega',
            'liderRecibe',
            'novedades',
            'firmaEntrega.empleado',
            'firmaRecepcion.empleado',
        ])->findOrFail($id);

        $pdf = $this->pdfService->generarRespuesta($entrega);

        return $pdf->download("acta-{$entrega->codigo_acta}.pdf");
    }

    /**
     * GET /api/entregas/{id}/pdf-stream
     * Ver PDF en navegador
     */
    public function verPdf($id)
    {
        $entrega = Entrega::with([
            'liderEntrega',
            'liderRecibe',
            'novedades',
            'firmaEntrega.empleado',
            'firmaRecepcion.empleado',
        ])->findOrFail($id);

        $pdf = $this->pdfService->generarRespuesta($entrega);

        return $pdf->stream("acta-{$entrega->codigo_acta}.pdf");
    }

    /**
     * GET /api/empleados/{id}/firma
     * Obtener firma guardada de un empleado
     */
    public function obtenerFirmaEmpleado($id)
    {
        $empleado = Empleado::findOrFail($id);

        return response()->json([
            'tiene_firma' => !empty($empleado->firma_personal),
            'firma' => $empleado->firma_personal,
        ]);
    }

    /**
     * POST /api/empleados/{id}/firma
     * Guardar firma personal del empleado
     */
    public function guardarFirmaEmpleado(Request $request, $id)
    {
        $validated = $request->validate([
            'firma_data' => 'required|string',
        ]);

        $empleado = Empleado::findOrFail($id);
        $empleado->update(['firma_personal' => $validated['firma_data']]);

        return response()->json([
            'message' => 'Firma personal guardada',
        ]);
    }

    /**
     * DELETE /api/entregas/{id}
     * Eliminar acta (solo si está abierta)
     */
    public function destroy($id)
    {
        $entrega = Entrega::findOrFail($id);

        if ($entrega->estado === 'completada') {
            return response()->json(['error' => 'No se puede eliminar un acta completada'], 422);
        }

        $entrega->delete();

        return response()->json(['message' => 'Acta eliminada']);
    }
}
