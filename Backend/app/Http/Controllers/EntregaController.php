<?php

namespace App\Http\Controllers;

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
use Illuminate\Support\Str;
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

    public static function resolverEmpleadoParaUsuario($user): ?Empleado
    {
        if (!$user) {
            return null;
        }

        $email = trim((string) ($user->email ?? ''));
        $username = trim((string) ($user->username ?? ''));
        $name = trim((string) ($user->name ?? ''));

        $empleado = self::buscarEmpleadoPorDatos($email, $username, $name);

        if ($empleado) {
            $empleado->setAttribute('portal_user_id', $user->id ?? null);
            $empleado->setAttribute('portal_user_email', $email ?: null);
            $empleado->setAttribute('portal_user_role', $user->role ?? null);
            $empleado->setAttribute('tiene_usuario_portal', true);
            return $empleado;
        }

        $budgetUser = self::buscarUsuarioBudget($email, $name);

        if ($budgetUser) {
            $empleado = self::buscarEmpleadoPorDatos(
                (string) ($budgetUser->email ?? ''),
                '',
                (string) ($budgetUser->name ?? '')
            );

            if ($empleado) {
                $empleado->setAttribute('portal_user_id', $user->id ?? null);
                $empleado->setAttribute('portal_user_email', $email ?: null);
                $empleado->setAttribute('portal_user_role', $user->role ?? null);
                $empleado->setAttribute('tiene_usuario_portal', true);
            }

            return $empleado;
        }

        if (($user->role ?? null) === 'lider') {
            return self::crearEmpleadoPuenteParaUsuarioPortal($user);
        }

        return null;
    }

    public static function buscarUsuarioPortalParaEmpleado(Empleado $empleado)
    {
        try {
            $usuarios = DB::connection('mysql')
                ->table('users')
                ->select('id', 'name', 'email', 'username', 'role')
                ->whereNotNull('email')
                ->where(function ($query) use ($empleado) {
                    if (!empty($empleado->email)) {
                        $query->orWhere('email', $empleado->email);
                    }
                    if (!empty($empleado->cedula)) {
                        $query->orWhere('username', $empleado->cedula);
                    }
                    if (!empty($empleado->colaborador)) {
                        $query->orWhere('name', $empleado->colaborador);
                    }
                })
                ->get();

            if ($usuarios->isEmpty() && !empty($empleado->colaborador)) {
                $nombreEmpleado = self::normalizarTexto($empleado->colaborador);

                $usuarios = DB::connection('mysql')
                    ->table('users')
                    ->select('id', 'name', 'email', 'username', 'role')
                    ->whereNotNull('email')
                    ->get()
                    ->filter(fn ($usuario) => self::normalizarTexto($usuario->name ?? '') === $nombreEmpleado);
            }

            return $usuarios->isEmpty()
                ? null
                : $usuarios
                ->sortByDesc(fn ($usuario) => self::emailEsNotificable($usuario->email ?? null) ? 1 : 0)
                ->first();
        } catch (Throwable $e) {
            Log::warning('No fue posible consultar usuario del portal para empleado', [
                'empleado_id' => $empleado->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private static function buscarEmpleadoPorDatos(string $email = '', string $cedula = '', string $nombre = ''): ?Empleado
    {
        $email = trim($email);
        $cedula = trim($cedula);
        $nombre = trim($nombre);

        if ($email === '' && $cedula === '' && $nombre === '') {
            return null;
        }

        $empleado = Empleado::query()
            ->where(function ($query) use ($email, $cedula, $nombre) {
                if ($email !== '') {
                    $query->orWhere('email', $email);
                }
                if ($cedula !== '') {
                    $query->orWhere('cedula', $cedula);
                }
                if ($nombre !== '') {
                    $query->orWhere('colaborador', $nombre);
                }
            })
            ->first();

        if ($empleado || $nombre === '') {
            return $empleado;
        }

        $nombreNormalizado = self::normalizarTexto($nombre);

        return Empleado::query()
            ->where('colaborador', 'LIKE', "%{$nombre}%")
            ->get()
            ->first(fn ($empleado) => self::normalizarTexto($empleado->colaborador) === $nombreNormalizado)
            ?: Empleado::query()
                ->get()
                ->first(fn ($empleado) => self::normalizarTexto($empleado->colaborador) === $nombreNormalizado);
    }

    private static function buscarUsuarioBudget(string $email = '', string $nombre = '')
    {
        $email = trim($email);
        $nombre = trim($nombre);

        if ($email === '' && $nombre === '') {
            return null;
        }

        try {
            return DB::connection('budget')
                ->table('users')
                ->where(function ($query) use ($email, $nombre) {
                    if ($email !== '') {
                        $query->orWhere('email', $email);
                    }
                    if ($nombre !== '') {
                        $query->orWhere('name', $nombre);
                    }
                })
                ->first();
        } catch (Throwable $e) {
            Log::warning('No fue posible consultar usuario en budget', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private static function normalizarTexto(?string $texto): string
    {
        return Str::of((string) $texto)
            ->ascii()
            ->lower()
            ->squish()
            ->toString();
    }

    public static function emailEsNotificable(?string $email): bool
    {
        $email = trim((string) $email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $dominio = strtolower((string) Str::of($email)->afterLast('@'));

        return !in_array($dominio, ['local', 'empresa.local'], true);
    }

    public function empleadoActual(Request $request)
    {
        $user = $request->user() ?: auth()->user();
        $empleado = self::resolverEmpleadoParaUsuario($user);

        return response()->json([
            'empleado' => $empleado,
            'user' => $user,
        ]);
    }

    private function empleadoAutenticado(Request $request): ?Empleado
    {
        $empleado = self::resolverEmpleadoParaUsuario($request->user() ?: auth()->user());

        if ($empleado) {
            return $empleado;
        }

        if ($request->filled('empleado_id')) {
            return Empleado::find($request->get('empleado_id'));
        }

        return null;
    }

    private function abortarSiNoParticipa(Entrega $entrega, ?Empleado $empleado)
    {
        if (!$empleado) {
            return response()->json(['error' => 'No se encontro empleado asociado al usuario actual'], 403);
        }

        if (!in_array($empleado->id, [(int) $entrega->lider_entrega_id, (int) $entrega->lider_recibe_id], true)) {
            return response()->json(['error' => 'No autorizado para ver esta acta'], 403);
        }

        return null;
    }

    /**
     * GET /api/entregas
     * Listar todas las entregas con filtros
     */
    public function index(Request $request)
    {
        $empleadoActual = $this->empleadoAutenticado($request);
        $liderId = $empleadoActual?->id ?: (int) $request->get('lider_id');

        if (!$liderId) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'total' => 0,
                'per_page' => (int) $request->get('per_page', 15),
            ]);
        }

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

        $query->paraLider($liderId);

        if ($request->filled('tipo')) {
            if ($request->tipo === 'entrega') {
                $query->entregadasPor($liderId);
            } elseif ($request->tipo === 'recepcion') {
                $query->recibidasPor($liderId);
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
        try {
            $usuarios = DB::connection('mysql')
                ->table('users')
                ->select('id', 'name', 'email', 'username', 'role')
                ->where('role', 'lider')
                ->orderBy('name')
                ->get();
        } catch (Throwable $e) {
            Log::error('No fue posible consultar lideres del portal', ['error' => $e->getMessage()]);
            return response()->json([]);
        }

        $lideres = $usuarios
            ->filter(fn ($usuario) => self::emailEsNotificable($usuario->email ?? null))
            ->map(function ($usuario) {
                $empleado = self::buscarOCrearEmpleadoParaUsuarioPortal($usuario);

                if (!$empleado) {
                    return null;
                }

                $empleado->setAttribute('tiene_usuario_portal', true);
                $empleado->setAttribute('portal_user_id', $usuario->id ?? null);
                $empleado->setAttribute('portal_user_email', $usuario->email ?? null);
                $empleado->setAttribute('portal_user_role', $usuario->role ?? null);

                return $empleado;
            })
            ->filter()
            ->values();

        return response()->json($lideres);
    }

    private static function buscarOCrearEmpleadoParaUsuarioPortal($usuario): ?Empleado
    {
        $empleado = self::resolverEmpleadoParaUsuario($usuario);

        if ($empleado) {
            return $empleado;
        }

        return self::crearEmpleadoPuenteParaUsuarioPortal($usuario);
    }

    private static function crearEmpleadoPuenteParaUsuarioPortal($usuario): ?Empleado
    {
        $cedula = trim((string) ($usuario->username ?? ''))
            ?: 'USR' . (string) $usuario->id;

        $empleado = Empleado::withTrashed()
            ->where('cedula', $cedula)
            ->first();

        if ($empleado) {
            if (method_exists($empleado, 'restore') && $empleado->trashed()) {
                $empleado->restore();
            }

            $empleado->update([
                'colaborador' => $empleado->colaborador ?: ($usuario->name ?? 'Lider portal'),
                'email' => $empleado->email ?: ($usuario->email ?? null),
                'estado' => $empleado->estado ?: 'ACTIVO',
            ]);

            return $empleado->fresh();
        }

        try {
            $empleado = Empleado::create([
                'colaborador' => $usuario->name ?? 'Lider portal',
                'cedula' => $cedula,
                'estado' => 'ACTIVO',
                'email' => $usuario->email ?? null,
            ]);

            $empleado->setAttribute('portal_user_id', $usuario->id ?? null);
            $empleado->setAttribute('portal_user_email', $usuario->email ?? null);
            $empleado->setAttribute('portal_user_role', $usuario->role ?? null);
            $empleado->setAttribute('tiene_usuario_portal', true);

            return $empleado;
        } catch (Throwable $e) {
            Log::error('No fue posible crear empleado puente para lider del portal', [
                'portal_user_id' => $usuario->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function empleados()
    {
        $usuariosPortal = self::mapearUsuariosPortalPorEmpleado();

        $empleados = Empleado::query()
            ->select('id', 'colaborador', 'cedula', 'email', 'sede', 'jefe_inmediato')
            ->where(function ($query) {
                $query->where('estado', 'ACTIVO')
                    ->orWhere('estado', 'Activo')
                    ->orWhere('estado', 'activo');
            })
            ->orderBy('colaborador')
            ->get()
            ->map(function ($empleado) use ($usuariosPortal) {
                $usuario = $usuariosPortal[$empleado->id] ?? null;
                $empleado->setAttribute('tiene_usuario_portal', $usuario !== null);
                $empleado->setAttribute('portal_user_id', $usuario->id ?? null);
                $empleado->setAttribute('portal_user_email', $usuario->email ?? null);
                $empleado->setAttribute('portal_user_role', $usuario->role ?? null);

                return $empleado;
            });

        return response()->json($empleados);
    }

    private static function mapearUsuariosPortalPorEmpleado(?string $role = null): array
    {
        try {
            $usuariosQuery = DB::connection('mysql')
                ->table('users')
                ->select('id', 'name', 'email', 'username', 'role')
                ->whereNotNull('email');

            if ($role) {
                $usuariosQuery->where('role', $role);
            }

            $usuarios = $usuariosQuery->get();
        } catch (Throwable $e) {
            Log::warning('No fue posible consultar usuarios del portal', ['error' => $e->getMessage()]);
            return [];
        }

        $mapa = [];

        foreach ($usuarios as $usuario) {
            $empleado = self::resolverEmpleadoParaUsuario($usuario);

            if ($empleado && !isset($mapa[$empleado->id])) {
                $mapa[$empleado->id] = $usuario;
            }
        }

        return $mapa;
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
        $empleadoActual = $this->empleadoAutenticado($request);
        $empleadoId = $empleadoActual?->id ?: $request->get('empleado_id');

        if (!$empleadoId) {
            return response()->json(['error' => 'empleado_id requerido'], 422);
        }

        $stats = [
            'entregas_realizadas' => Entrega::entregadasPor($empleadoId)->count(),
            'entregas_completadas' => Entrega::entregadasPor($empleadoId)->where('estado', 'completada')->count(),
            'entregas_pendientes_firma' => Entrega::entregadasPor($empleadoId)->where('estado', 'abierta')->count(),
            'recibidas_pendientes' => Entrega::recibidasPor($empleadoId)
                ->whereIn('estado', ['abierta', 'entregada', 'recibida'])->count(),
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
            'lider_entrega_id' => 'required|exists:mysql_personal.empleados,id',
            'lider_recibe_id' => 'required|exists:mysql_personal.empleados,id|different:lider_entrega_id',
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

        DB::connection('mysql_personal')->beginTransaction();
        try {
            $entrega = Entrega::create([
                'codigo_acta' => $this->generarCodigoActa(),
                'nombre_acta' => $this->generarNombreActa($validated['fecha_acta'], $validated['turno']),
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

            DB::connection('mysql_personal')->commit();

            $entrega->load(['liderEntrega', 'liderRecibe', 'novedades']);

            return response()->json([
                'message' => 'Acta creada exitosamente',
                'entrega' => $entrega,
            ], 201);

        } catch (Throwable $e) {
            DB::connection('mysql_personal')->rollBack();
            Log::error('Error creando entrega', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'lider_entrega_id' => 'required|exists:mysql_personal.empleados,id',
            'lider_recibe_id' => 'required|exists:mysql_personal.empleados,id|different:lider_entrega_id',
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

        $entrega = Entrega::findOrFail($id);

        if ($entrega->lider_entrega_id !== (int) $validated['lider_entrega_id']) {
            return response()->json(['error' => 'Solo el lider que entrega puede editar el acta'], 403);
        }

        if ($entrega->estado !== 'abierta') {
            return response()->json(['error' => 'Solo se puede editar antes de entregar el acta'], 422);
        }

        DB::connection('mysql_personal')->beginTransaction();
        try {
            $entrega->update([
                'nombre_acta' => $this->generarNombreActa($validated['fecha_acta'], $validated['turno']),
                'lider_recibe_id' => $validated['lider_recibe_id'],
                'turno' => $validated['turno'],
                'fecha_acta' => $validated['fecha_acta'],
                'sede' => $validated['sede'] ?? null,
                'observaciones' => $validated['observaciones'] ?? null,
            ]);

            $entrega->novedades()->delete();

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

            EntregaLog::create([
                'entrega_id' => $entrega->id,
                'empleado_id' => $validated['lider_entrega_id'],
                'accion' => 'updated',
                'detalles' => 'Acta actualizada antes de entrega',
                'ip_address' => $request->ip(),
            ]);

            DB::connection('mysql_personal')->commit();

            return response()->json([
                'message' => 'Acta actualizada',
                'entrega' => $entrega->fresh()->load(['liderEntrega', 'liderRecibe', 'novedades']),
            ]);
        } catch (Throwable $e) {
            DB::connection('mysql_personal')->rollBack();
            Log::error('Error actualizando entrega', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/entregas/{id}
     * Ver detalle de una entrega
     */
    public function show(Request $request, $id)
    {
        $entrega = Entrega::with([
            'liderEntrega',
            'liderRecibe',
            'novedades',
            'firmaEntrega.empleado:id,colaborador,cedula',
            'firmaRecepcion.empleado:id,colaborador,cedula',
            'logs.empleado:id,colaborador',
        ])->findOrFail($id);

        if ($response = $this->abortarSiNoParticipa($entrega, $this->empleadoAutenticado($request))) {
            return $response;
        }

        return response()->json($entrega);
    }

    /**
     * POST /api/entregas/{id}/firmar
     * Firmar entrega (como quien entrega o quien recibe)
     */
    public function firmar(Request $request, $id)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:mysql_personal.empleados,id',
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

        DB::connection('mysql_personal')->beginTransaction();
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
                    'estado' => 'recibida',
                    'fecha_recepcion' => now(),
                ]);
            }

            // Log
            EntregaLog::create([
                'entrega_id' => $entrega->id,
                'empleado_id' => $empleadoId,
                'accion' => 'signed_' . $tipoFirma,
                'detalles' => $tipoFirma === 'recepcion' ? 'Firma de recibido capturada' : 'Acta entregada con firma',
                'ip_address' => $request->ip(),
            ]);

            DB::connection('mysql_personal')->commit();

            return response()->json([
                'message' => $tipoFirma === 'recepcion'
                    ? 'Acta recibida. Ahora puedes marcar novedades completadas y cerrar el acta.'
                    : 'Acta entregada y notificada al lider que recibe.',
                'pendientes' => null,
                'entrega' => $entrega->fresh()->load(['liderEntrega', 'liderRecibe', 'novedades', 'firmaEntrega', 'firmaRecepcion']),
            ]);

        } catch (Throwable $e) {
            DB::connection('mysql_personal')->rollBack();
            Log::error('Error firmando entrega', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function cerrarActa(Request $request, $id)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:mysql_personal.empleados,id',
        ]);

        $entrega = Entrega::findOrFail($id);
        $empleadoId = (int) $validated['empleado_id'];

        if ($entrega->lider_recibe_id !== $empleadoId) {
            return response()->json(['error' => 'Solo el lider que recibe puede cerrar el acta'], 403);
        }

        if ($entrega->estado !== 'recibida') {
            return response()->json(['error' => 'El acta debe estar recibida antes de cerrarla'], 422);
        }

        $pendientes = $entrega->novedades()->where('resuelto', false)->count();

        DB::connection('mysql_personal')->beginTransaction();
        try {
            $entrega->update(['estado' => 'completada']);

            $pdfPath = $this->pdfService->generar($entrega->fresh());
            $entrega->update(['pdf_path' => $pdfPath]);

            EntregaLog::create([
                'entrega_id' => $entrega->id,
                'empleado_id' => $empleadoId,
                'accion' => 'closed',
                'detalles' => "Acta cerrada con {$pendientes} novedades pendientes",
                'ip_address' => $request->ip(),
            ]);

            DB::connection('mysql_personal')->commit();

            $this->mailService->notificarCierreActa($entrega->fresh());

            return response()->json([
                'message' => "Acta cerrada con {$pendientes} novedades pendientes",
                'pendientes' => $pendientes,
                'entrega' => $entrega->fresh()->load(['liderEntrega', 'liderRecibe', 'novedades', 'firmaEntrega', 'firmaRecepcion']),
            ]);
        } catch (Throwable $e) {
            DB::connection('mysql_personal')->rollBack();
            Log::error('Error cerrando acta', ['entrega_id' => $entrega->id, 'error' => $e->getMessage()]);
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
            'empleado_id' => 'required|exists:mysql_personal.empleados,id',
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

    public function actualizarNovedad(Request $request, $id, $novedadId)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:mysql_personal.empleados,id',
            'titulo' => 'nullable|string|max:255',
            'descripcion' => 'required|string|max:2000',
        ]);

        $entrega = Entrega::findOrFail($id);

        if ($entrega->lider_entrega_id !== (int) $validated['empleado_id']) {
            return response()->json(['error' => 'Solo el lider que entrega puede editar novedades'], 403);
        }

        if ($entrega->estado !== 'abierta') {
            return response()->json(['error' => 'Solo se puede editar antes de entregar el acta'], 422);
        }

        $novedad = Novedad::where('entrega_id', $entrega->id)
            ->where('id', $novedadId)
            ->firstOrFail();

        $novedad->update([
            'titulo' => $validated['titulo'] ?? null,
            'descripcion' => $validated['descripcion'],
        ]);

        EntregaLog::create([
            'entrega_id' => $entrega->id,
            'empleado_id' => $validated['empleado_id'],
            'accion' => 'novedad_updated',
            'detalles' => "Novedad {$novedad->id} actualizada antes de entrega",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Novedad actualizada',
            'novedad' => $novedad->fresh(),
            'entrega' => $entrega->fresh()->load(['liderEntrega', 'liderRecibe', 'novedades', 'firmaEntrega', 'firmaRecepcion']),
        ]);
    }

    /**
     * PATCH /api/entregas/{id}/novedades/{novedadId}/resuelto
     * El lider receptor marca una novedad como completada o pendiente.
     */
    public function actualizarEstadoNovedad(Request $request, $id, $novedadId)
    {
        $validated = $request->validate([
            'empleado_id' => 'required|exists:mysql_personal.empleados,id',
            'resuelto' => 'required|boolean',
            'observaciones_receptor' => 'nullable|string|max:1000',
        ]);

        $entrega = Entrega::findOrFail($id);

        if ($entrega->lider_recibe_id !== (int) $validated['empleado_id']) {
            return response()->json(['error' => 'Solo el lider que recibe puede marcar novedades'], 403);
        }

        if ($entrega->estado !== 'recibida') {
            return response()->json(['error' => 'Solo se pueden completar novedades despues de firmar recibido y antes de cerrar el acta'], 422);
        }

        $novedad = Novedad::where('entrega_id', $entrega->id)
            ->where('id', $novedadId)
            ->firstOrFail();

        $novedad->update([
            'resuelto' => (bool) $validated['resuelto'],
            'observaciones_receptor' => $validated['observaciones_receptor'] ?? $novedad->observaciones_receptor,
        ]);

        $pendientes = $entrega->novedades()->where('resuelto', false)->count();

        EntregaLog::create([
            'entrega_id' => $entrega->id,
            'empleado_id' => $validated['empleado_id'],
            'accion' => $validated['resuelto'] ? 'novedad_completed' : 'novedad_pending',
            'detalles' => "Novedad {$novedad->id}. Pendientes: {$pendientes}",
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => $validated['resuelto'] ? 'Novedad completada' : 'Novedad marcada como pendiente',
            'pendientes' => $pendientes,
            'novedad' => $novedad->fresh(),
            'entrega' => $entrega->fresh()->load(['liderEntrega', 'liderRecibe', 'novedades', 'firmaEntrega', 'firmaRecepcion']),
        ]);
    }

    /**
     * GET /api/entregas/{id}/pdf
     * Descargar PDF de la entrega
     */
    public function descargarPdf(Request $request, $id)
    {
        $entrega = Entrega::with([
            'liderEntrega',
            'liderRecibe',
            'novedades',
            'firmaEntrega.empleado',
            'firmaRecepcion.empleado',
        ])->findOrFail($id);

        if ($response = $this->abortarSiNoParticipa($entrega, $this->empleadoAutenticado($request))) {
            return $response;
        }

        $pdf = $this->pdfService->generarRespuesta($entrega);

        return $pdf->download("acta-{$entrega->codigo_acta}.pdf");
    }

    /**
     * GET /api/entregas/{id}/pdf-stream
     * Ver PDF en navegador
     */
    public function verPdf(Request $request, $id)
    {
        $entrega = Entrega::with([
            'liderEntrega',
            'liderRecibe',
            'novedades',
            'firmaEntrega.empleado',
            'firmaRecepcion.empleado',
        ])->findOrFail($id);

        if ($response = $this->abortarSiNoParticipa($entrega, $this->empleadoAutenticado($request))) {
            return $response;
        }

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

    private function generarCodigoActa(): string
    {
        $prefix = 'ENT-' . now()->format('Ymd');
        $count = Entrega::whereDate('created_at', now()->toDateString())->count() + 1;

        do {
            $codigo = $prefix . '-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            $count++;
        } while (Entrega::where('codigo_acta', $codigo)->exists());

        return $codigo;
    }

    private function generarNombreActa(string $fechaActa, string $turno): string
    {
        return "Acta de entrega {$fechaActa} {$turno}";
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
