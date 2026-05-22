import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import entregasApi from '../services/entregasApi';
import useEmpleadoActual from '../hooks/useEmpleadoActual';
import type { Entrega, EstadoEntrega } from '../types';

type Tipo = '' | 'entrega' | 'recepcion';

type Props = {
  tipoInicial?: Tipo;
  titulo?: string;
};

const ESTADO_COLORS: Record<EstadoEntrega, string> = {
  abierta: 'bg-yellow-500',
  entregada: 'bg-blue-500',
  recibida: 'bg-purple-500',
  completada: 'bg-green-600',
  rechazada: 'bg-red-600',
};

export default function ListadoEntregasPage({ tipoInicial = '', titulo }: Props) {
  const navigate = useNavigate();
  const { empleado } = useEmpleadoActual();

  const [entregas, setEntregas] = useState<Entrega[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  // filtros
  const [tipo, setTipo] = useState<Tipo>(tipoInicial);
  const [estado, setEstado] = useState<string>('');
  const [search, setSearch] = useState('');
  const [fechaDesde, setFechaDesde] = useState('');
  const [fechaHasta, setFechaHasta] = useState('');

  const tituloFinal = useMemo(() => {
    if (titulo) return titulo;
    if (tipo === 'recepcion') return '📥 Actas que debo recibir';
    if (tipo === 'entrega') return '📤 Mis entregas realizadas';
    return '📋 Historial de actas';
  }, [titulo, tipo]);

  useEffect(() => {
    cargar(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [empleado?.id, tipo, estado, fechaDesde, fechaHasta, search]);

  const cargar = async (pagina = 1) => {
    if (!empleado?.id) return;
    setLoading(true);
    try {
      const data = await entregasApi.listar({
        lider_id: empleado.id,
        tipo: tipo || undefined,
        estado: estado || undefined,
        search: search || undefined,
        fecha_desde: fechaDesde || undefined,
        fecha_hasta: fechaHasta || undefined,
        page: pagina,
        per_page: 15,
      });
      setEntregas(data.data ?? []);
      setPage(data.current_page);
      setLastPage(data.last_page);
      setTotal(data.total);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="p-4 sm:p-6 max-w-7xl mx-auto">
      <div className="mb-5">
        <button
          onClick={() => navigate('/entregas')}
          className="text-sm text-indigo-600 hover:text-indigo-800 mb-2"
        >
          ← Dashboard
        </button>
        <h1 className="text-2xl font-bold text-gray-800">{tituloFinal}</h1>
        <p className="text-xs text-gray-500 mt-1">Total: {total} actas</p>
      </div>

      {/* Filtros */}
      <div className="bg-white rounded-lg shadow p-4 mb-5">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">Tipo</label>
            <select
              value={tipo}
              onChange={e => setTipo(e.target.value as Tipo)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            >
              <option value="">Todas (entregadas y recibidas)</option>
              <option value="entrega">📤 Entregadas por mí</option>
              <option value="recepcion">📥 Recibidas por mí</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">Estado</label>
            <select
              value={estado}
              onChange={e => setEstado(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            >
              <option value="">Todos</option>
              <option value="abierta">Abiertas</option>
              <option value="entregada">Firmadas (esperando)</option>
              <option value="completada">Completadas</option>
              <option value="rechazada">Rechazadas</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">Desde</label>
            <input
              type="date"
              value={fechaDesde}
              onChange={e => setFechaDesde(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">Hasta</label>
            <input
              type="date"
              value={fechaHasta}
              onChange={e => setFechaHasta(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
            />
          </div>
        </div>

        <input
          type="text"
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="🔍 Buscar por código, nombre, líder..."
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
        />
      </div>

      {/* Resultados */}
      {loading ? (
        <div className="text-center py-10 text-gray-500">Cargando…</div>
      ) : entregas.length === 0 ? (
        <div className="bg-white rounded-lg shadow text-center py-12 text-gray-400">
          <div className="text-4xl mb-2">📭</div>
          <p>No hay actas que coincidan con los filtros</p>
        </div>
      ) : (
        <>
          {/* Móvil */}
          <div className="sm:hidden space-y-2">
            {entregas.map(e => (
              <EntregaMobileRow
                key={e.id}
                entrega={e}
                empleadoId={empleado!.id}
                onClick={() => navigate(`/entregas/${e.id}`)}
              />
            ))}
          </div>

          {/* Escritorio */}
          <div className="hidden sm:block bg-white rounded-lg shadow overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3 text-left">Código</th>
                  <th className="p-3 text-left">Tipo</th>
                  <th className="p-3 text-left">Otro líder</th>
                  <th className="p-3 text-left">Fecha</th>
                  <th className="p-3 text-center">Novedades</th>
                  <th className="p-3 text-center">Estado</th>
                  <th className="p-3"></th>
                </tr>
              </thead>
              <tbody>
                {entregas.map(e => {
                  const esEntrega = e.lider_entrega_id === empleado?.id;
                  const otroLider = esEntrega ? e.lider_recibe : e.lider_entrega;
                  return (
                    <tr
                      key={e.id}
                      onClick={() => navigate(`/entregas/${e.id}`)}
                      className="border-t hover:bg-gray-50 cursor-pointer"
                    >
                      <td className="p-3 font-bold text-indigo-600">{e.codigo_acta}</td>
                      <td className="p-3 text-xs text-gray-600">{esEntrega ? '📤 Entregué' : '📥 Recibí'}</td>
                      <td className="p-3">{otroLider?.colaborador}</td>
                      <td className="p-3">{new Date(e.fecha_acta).toLocaleDateString('es')}</td>
                      <td className="p-3 text-center">{e.novedades?.length ?? 0}</td>
                      <td className="p-3 text-center">
                        <span className={`px-2 py-1 rounded-full text-xs font-bold text-white uppercase ${ESTADO_COLORS[e.estado]}`}>
                          {e.estado}
                        </span>
                      </td>
                      <td className="p-3 text-right">
                        <button className="text-xs px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded font-semibold">
                          Ver →
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* Paginación */}
          {lastPage > 1 && (
            <div className="flex items-center justify-between mt-4 bg-white rounded-lg shadow p-3">
              <button
                disabled={page === 1}
                onClick={() => cargar(page - 1)}
                className="px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
              >
                ← Anterior
              </button>
              <span className="text-sm text-gray-600">Página {page} de {lastPage}</span>
              <button
                disabled={page === lastPage}
                onClick={() => cargar(page + 1)}
                className="px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Siguiente →
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function EntregaMobileRow({ entrega, empleadoId, onClick }: { entrega: Entrega; empleadoId: number; onClick: () => void }) {
  const esEntrega = entrega.lider_entrega_id === empleadoId;
  const otroLider = esEntrega ? entrega.lider_recibe : entrega.lider_entrega;

  return (
    <div onClick={onClick} className="bg-white rounded-md p-3 shadow-sm cursor-pointer">
      <div className="flex justify-between items-start mb-2">
        <div>
          <div className="text-xs text-gray-500">{entrega.codigo_acta}</div>
          <div className="font-medium">{otroLider?.colaborador}</div>
        </div>
        <span className={`px-2 py-1 rounded-full text-xxs font-bold text-white uppercase ${ESTADO_COLORS[entrega.estado]}`}>
          {entrega.estado}
        </span>
      </div>
      <div className="text-xs text-gray-500 flex justify-between">
        <span>{esEntrega ? '📤 Entregué' : '📥 Recibí'}</span>
        <span>{new Date(entrega.fecha_acta).toLocaleDateString('es')}</span>
      </div>
    </div>
  );
}
