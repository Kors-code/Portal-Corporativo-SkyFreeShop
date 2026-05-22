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

const BASE = '/v1/entregas';

export const entregasApi = {
  // ============ ENTREGAS ============

  listar: (params: Record<string, any> = {}) =>
    api.get<PaginatedResponse<Entrega>>(BASE, { params }).then(r => r.data),

  obtener: (id: number | string) =>
    api.get<Entrega>(`${BASE}/${id}`).then(r => r.data),

  crear: (data: CrearEntregaPayload) =>
    api.post<{ message: string; entrega: Entrega }>(BASE, data).then(r => r.data),

  eliminar: (id: number) =>
    api.delete(`${BASE}/${id}`).then(r => r.data),

  firmar: (id: number | string, data: FirmarPayload) =>
    api.post<{ message: string; entrega: Entrega }>(`${BASE}/${id}/firmar`, data).then(r => r.data),

  rechazar: (id: number | string, data: { empleado_id: number; razon_rechazo: string }) =>
    api.post(`${BASE}/${id}/rechazar`, data).then(r => r.data),

  agregarObservacionNovedad: (
    entregaId: number | string,
    novedadId: number,
    data: { observaciones_receptor: string }
  ) =>
    api.post(`${BASE}/${entregaId}/novedades/${novedadId}/observacion`, data).then(r => r.data),

  // ============ HELPERS ============

  obtenerCategorias: () =>
    api.get<CategoriasResponse>(`${BASE}/categorias`).then(r => r.data),

  obtenerLideres: () =>
    api.get<Empleado[]>(`${BASE}/lideres`).then(r => r.data),

  obtenerDashboard: (empleadoId: number) =>
    api.get<DashboardResponse>(`${BASE}/dashboard`, { params: { empleado_id: empleadoId } }).then(r => r.data),

  // ============ FIRMAS PERSONALES ============

  obtenerFirmaEmpleado: (empleadoId: number) =>
    api.get<{ tiene_firma: boolean; firma: string | null }>(`/v1/empleados/${empleadoId}/firma`).then(r => r.data),

  guardarFirmaEmpleado: (empleadoId: number, firmaData: string) =>
    api.post(`/v1/empleados/${empleadoId}/firma`, { firma_data: firmaData }).then(r => r.data),

  // ============ PDF ============

  descargarPdfUrl: (id: number | string) => `${api.defaults.baseURL}${BASE}/${id}/pdf`,
  verPdfUrl: (id: number | string) => `${api.defaults.baseURL}${BASE}/${id}/pdf-view`,

  descargarPdf: (id: number | string) =>
    api.get(`${BASE}/${id}/pdf`, { responseType: 'blob' }).then(r => r.data),
};

export default entregasApi;
