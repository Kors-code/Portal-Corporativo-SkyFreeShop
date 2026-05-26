// Tipos del módulo de Entregas

export type CategoriaKey =
  | 'precios_promociones'
  | 'logistica'
  | 'cajas'
  | 'personal'
  | 'otros_temas'
  | 'temas_pendientes';

export type PrioridadKey = 'baja' | 'media' | 'alta' | 'urgente';

export type EstadoEntrega =
  | 'abierta'
  | 'entregada'
  | 'recibida'
  | 'completada'
  | 'rechazada';

export type TurnoKey = 'mañana' | 'tarde' | 'noche';

export interface CategoriaInfo {
  label: string;
  icon: string;
  color: string;
  opciones: string[];
}

export interface PrioridadInfo {
  label: string;
  color: string;
}

export interface Empleado {
  id: number;
  colaborador: string;
  cedula?: string;
  email?: string;
  sede?: string;
  jefe_inmediato?: string;
  tiene_usuario_portal?: boolean;
  portal_user_id?: number | null;
  portal_user_email?: string | null;
  portal_user_role?: string | null;
}

export interface Novedad {
  id?: number;
  entrega_id?: number;
  categoria: CategoriaKey;
  titulo?: string | null;
  descripcion: string;
  prioridad: PrioridadKey;
  requiere_seguimiento: boolean;
  resuelto?: boolean;
  observaciones_receptor?: string | null;
  orden?: number;
}

export interface FirmaDigital {
  id: number;
  entrega_id: number;
  empleado_id: number;
  tipo_firma: 'entrega' | 'recepcion';
  firma_data: string;
  formato: 'svg' | 'png' | 'base64';
  fecha_firma: string;
  empleado?: Empleado;
}

export interface EntregaLog {
  id: number;
  entrega_id: number;
  empleado_id?: number;
  accion: string;
  detalles?: string;
  created_at: string;
  empleado?: Empleado;
}

export interface Entrega {
  id: number;
  codigo_acta: string;
  nombre_acta: string;
  lider_entrega_id: number;
  lider_recibe_id: number;
  turno: TurnoKey;
  fecha_acta: string;
  sede?: string | null;
  estado: EstadoEntrega;
  fecha_entrega?: string | null;
  fecha_recepcion?: string | null;
  observaciones?: string | null;
  razon_rechazo?: string | null;
  pdf_path?: string | null;
  correo_enviado: boolean;
  created_at: string;
  updated_at: string;

  // Relaciones
  lider_entrega?: Empleado;
  lider_recibe?: Empleado;
  novedades?: Novedad[];
  firma_entrega?: FirmaDigital | null;
  firma_recepcion?: FirmaDigital | null;
  logs?: EntregaLog[];
}

export interface DashboardStats {
  entregas_realizadas: number;
  entregas_completadas: number;
  entregas_pendientes_firma: number;
  recibidas_pendientes: number;
  recibidas_completadas: number;
}

export interface DashboardResponse {
  stats: DashboardStats;
  recientes: Entrega[];
}

export interface CategoriasResponse {
  categorias: Record<CategoriaKey, CategoriaInfo>;
  prioridades: Record<PrioridadKey, PrioridadInfo>;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface CrearEntregaPayload {
  lider_entrega_id: number;
  lider_recibe_id: number;
  turno: TurnoKey;
  fecha_acta: string;
  sede?: string;
  observaciones?: string;
  novedades: Array<{
    categoria: CategoriaKey;
    titulo?: string;
    descripcion: string;
    prioridad: PrioridadKey;
    requiere_seguimiento: boolean;
    orden?: number;
  }>;
}

export interface FirmarPayload {
  empleado_id: number;
  tipo_firma: 'entrega' | 'recepcion';
  firma_data: string;
  formato?: 'svg' | 'png' | 'base64';
  usar_firma_guardada?: boolean;
}
