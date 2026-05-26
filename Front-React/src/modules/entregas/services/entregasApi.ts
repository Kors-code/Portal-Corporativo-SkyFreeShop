import api from '../../../api/axios';
import type {
  Entrega,
  Empleado,
  CategoriasResponse,
  DashboardResponse,
  PaginatedResponse,
  CrearEntregaPayload,
  FirmarPayload,
} from '../types';

const BASE = '/entregas';

export const entregasApi = {
  // ============ ENTREGAS ============

  listar: (params: Record<string, any> = {}) =>
    api.get<PaginatedResponse<Entrega>>(BASE, { params }).then(r => r.data),

  obtener: (id: number | string, empleadoId?: number) =>
    api.get<Entrega>(`${BASE}/${id}`, { params: empleadoId ? { empleado_id: empleadoId } : undefined }).then(r => r.data),

  crear: (data: CrearEntregaPayload) =>
    api.post<{ message: string; entrega: Entrega }>(BASE, data).then(r => r.data),

  actualizar: (id: number | string, data: CrearEntregaPayload) =>
    api.put<{ message: string; entrega: Entrega }>(`${BASE}/${id}`, data).then(r => r.data),

  eliminar: (id: number) =>
    api.delete(`${BASE}/${id}`).then(r => r.data),

  firmar: (id: number | string, data: FirmarPayload) =>
    api.post<{ message: string; entrega: Entrega }>(`${BASE}/${id}/firmar`, data).then(r => r.data),

  cerrar: (id: number | string, data: { empleado_id: number }) =>
    api.post<{ message: string; pendientes: number; entrega: Entrega }>(`${BASE}/${id}/cerrar`, data).then(r => r.data),

  rechazar: (id: number | string, data: { empleado_id: number; razon_rechazo: string }) =>
    api.post(`${BASE}/${id}/rechazar`, data).then(r => r.data),

  agregarObservacionNovedad: (
    entregaId: number | string,
    novedadId: number,
    data: { observaciones_receptor: string }
  ) =>
    api.post(`${BASE}/${entregaId}/novedades/${novedadId}/observacion`, data).then(r => r.data),

  actualizarNovedad: (
    entregaId: number | string,
    novedadId: number,
    data: { empleado_id: number; titulo?: string | null; descripcion: string }
  ) =>
    api.patch(`${BASE}/${entregaId}/novedades/${novedadId}`, data).then(r => r.data),

  actualizarEstadoNovedad: (
    entregaId: number | string,
    novedadId: number,
    data: { empleado_id: number; resuelto: boolean; observaciones_receptor?: string }
  ) =>
    api.patch(`${BASE}/${entregaId}/novedades/${novedadId}/resuelto`, data).then(r => r.data),

  // ============ HELPERS ============

  obtenerCategorias: () =>
    api.get<CategoriasResponse>(`${BASE}/categorias`).then(r => r.data),

  obtenerLideres: () =>
    api.get<Empleado[]>(`${BASE}/lideres`).then(r => r.data),

  obtenerEmpleados: () =>
    api.get<Empleado[]>(`${BASE}/empleados`).then(r => r.data),

  obtenerDashboard: (empleadoId: number) =>
    api.get<DashboardResponse>(`${BASE}/dashboard`, { params: { empleado_id: empleadoId } }).then(r => r.data),

  obtenerEmpleadoActual: () =>
    api.get<{ empleado: Empleado | null; user: any }>(`${BASE}/me`).then(r => r.data),

  // ============ FIRMAS PERSONALES ============

  obtenerFirmaEmpleado: (empleadoId: number) =>
    api.get<{ tiene_firma: boolean; firma: string | null }>(`/empleados/${empleadoId}/firma`).then(r => r.data),

  guardarFirmaEmpleado: (empleadoId: number, firmaData: string) =>
    api.post(`/empleados/${empleadoId}/firma`, { firma_data: firmaData }).then(r => r.data),

  // ============ PDF ============

  descargarPdfUrl: (id: number | string, empleadoId?: number) => `${api.defaults.baseURL}${BASE}/${id}/pdf${empleadoId ? `?empleado_id=${empleadoId}` : ""}`,
  verPdfUrl: (id: number | string, empleadoId?: number) => `${api.defaults.baseURL}${BASE}/${id}/pdf-view${empleadoId ? `?empleado_id=${empleadoId}` : ""}`,

  descargarPdf: (id: number | string, empleadoId?: number) =>
    api.get(`${BASE}/${id}/pdf`, { params: empleadoId ? { empleado_id: empleadoId } : undefined, responseType: 'blob' }).then(r => r.data),
};

export default entregasApi;
